<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Application\Actions;

use App\Core\Audit\AuditService;
use App\Core\Responses\OperationResult;
use App\Core\Timeline\TimelineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Purchasing\PurchaseMaterials\Domain\Enums\PurchaseMaterialStatus;
use Modules\Purchasing\PurchaseMaterials\Domain\Exceptions\InvalidPurchaseMaterialStatusException;
use Modules\Purchasing\PurchaseMaterials\Domain\Exceptions\PurchaseMaterialNotFoundException;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Domain\Services\PurchaseMaterialOwnershipService;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011 §4.
 *
 * on_hold was previously a dead end: HoldPurchaseMaterialAction never recorded which status the
 * request was held from, so nothing could ever move it back out. Resume restores the status
 * captured at hold time (falling back to UnderReview for any request held before this field
 * existed) and clears the marker.
 */
final class ResumePurchaseMaterialAction
{
    public function __construct(
        private readonly PurchaseMaterialOwnershipService $ownership,
        private readonly AuditService $audit,
        private readonly TimelineService $timeline,
    ) {}

    public function execute(string $id, Request $request): OperationResult
    {
        $material = DB::transaction(function () use ($id, $request): PurchaseMaterial {
            $material = PurchaseMaterial::query()->lockForUpdate()->find($id);

            if ($material === null) {
                throw new PurchaseMaterialNotFoundException($id);
            }

            if (! $material->status->canResume()) {
                throw new InvalidPurchaseMaterialStatusException(
                    $material->request_number,
                    $material->status->value,
                    [PurchaseMaterialStatus::OnHold->value],
                );
            }

            $resumeTo = PurchaseMaterialStatus::tryFrom((string) $material->held_from_status)
                ?? PurchaseMaterialStatus::UnderReview;

            $material->update([
                'status' => $resumeTo->value,
                'held_from_status' => null,
                'updated_by' => (string) $request->user()?->id,
            ]);

            if ($request->user() !== null) {
                $this->ownership->claimIfUnowned($material, $request->user());
            }

            $this->timeline->record(
                companyId: (string) $material->company_id,
                subjectType: 'PurchaseMaterial',
                subjectId: (string) $material->id,
                eventType: 'purchase_material.resumed',
                title: "Request resumed to {$resumeTo->label()}",
                actorId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                actorName: $request->user()?->name,
                sourceModule: 'Purchasing.PurchaseMaterials',
            );

            $this->audit->record(
                action: 'purchase_material.resumed',
                entityType: 'PurchaseMaterial',
                entityId: (string) $material->id,
                companyId: $material->company_id,
                userId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                oldValues: ['status' => PurchaseMaterialStatus::OnHold->value],
                newValues: ['status' => $resumeTo->value],
            );

            return $material;
        });

        return OperationResult::success($material->refresh(), 'Purchase material resumed.');
    }
}
