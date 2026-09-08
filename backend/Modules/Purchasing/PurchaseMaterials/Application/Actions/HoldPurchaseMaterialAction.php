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

final class HoldPurchaseMaterialAction
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

            if (! $material->status->canHold()) {
                throw new InvalidPurchaseMaterialStatusException(
                    $material->request_number,
                    $material->status->value,
                    [
                        PurchaseMaterialStatus::Draft->value,
                        PurchaseMaterialStatus::UnderReview->value,
                        PurchaseMaterialStatus::WaitingSupplierSelection->value,
                        PurchaseMaterialStatus::Approved->value,
                    ],
                );
            }

            // TASK-...-011 §4: on_hold was a dead end before — nothing ever recorded which state
            // to resume back into, so once held, a request could only be cancelled, never resumed.
            $fromStatus = $material->status;

            $material->update([
                'status' => PurchaseMaterialStatus::OnHold->value,
                'held_from_status' => $fromStatus->value,
                'updated_by' => (string) $request->user()?->id,
            ]);

            if ($request->user() !== null) {
                $this->ownership->claimIfUnowned($material, $request->user());
            }

            $this->timeline->record(
                companyId: (string) $material->company_id,
                subjectType: 'PurchaseMaterial',
                subjectId: (string) $material->id,
                eventType: 'purchase_material.held',
                title: 'Request placed on hold',
                actorId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                actorName: $request->user()?->name,
                sourceModule: 'Purchasing.PurchaseMaterials',
                metadata: ['held_from_status' => $fromStatus->value],
            );

            $this->audit->record(
                action: 'purchase_material.held',
                entityType: 'PurchaseMaterial',
                entityId: (string) $material->id,
                companyId: $material->company_id,
                userId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                oldValues: ['status' => $fromStatus->value],
                newValues: ['status' => PurchaseMaterialStatus::OnHold->value],
            );

            return $material;
        });

        return OperationResult::success($material->refresh(), 'Purchase material placed on hold.');
    }
}
