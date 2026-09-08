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

final class RejectPurchaseMaterialAction
{
    public function __construct(
        private readonly PurchaseMaterialOwnershipService $ownership,
        private readonly AuditService $audit,
        private readonly TimelineService $timeline,
    ) {}

    public function execute(string $id, ?string $reason, Request $request): OperationResult
    {
        $material = DB::transaction(function () use ($id, $reason, $request): PurchaseMaterial {
            $material = PurchaseMaterial::query()->lockForUpdate()->find($id);

            if ($material === null) {
                throw new PurchaseMaterialNotFoundException($id);
            }

            if (! $material->status->canReject()) {
                throw new InvalidPurchaseMaterialStatusException(
                    $material->request_number,
                    $material->status->value,
                    [PurchaseMaterialStatus::UnderReview->value, PurchaseMaterialStatus::WaitingSupplierSelection->value],
                );
            }

            $material->update([
                'status' => PurchaseMaterialStatus::Rejected->value,
                'rejected_by' => (string) $request->user()?->id,
                'rejection_reason' => $reason,
                'updated_by' => (string) $request->user()?->id,
            ]);

            if ($request->user() !== null) {
                $this->ownership->claimIfUnowned($material, $request->user());
            }

            $this->timeline->record(
                companyId: (string) $material->company_id,
                subjectType: 'PurchaseMaterial',
                subjectId: (string) $material->id,
                eventType: 'purchase_material.rejected',
                title: 'Request rejected',
                description: $reason,
                actorId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                actorName: $request->user()?->name,
                sourceModule: 'Purchasing.PurchaseMaterials',
            );

            $this->audit->record(
                action: 'purchase_material.rejected',
                entityType: 'PurchaseMaterial',
                entityId: (string) $material->id,
                companyId: $material->company_id,
                userId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                newValues: ['status' => PurchaseMaterialStatus::Rejected->value, 'reason' => $reason],
            );

            return $material;
        });

        return OperationResult::success($material->refresh(), 'Purchase material rejected.');
    }
}
