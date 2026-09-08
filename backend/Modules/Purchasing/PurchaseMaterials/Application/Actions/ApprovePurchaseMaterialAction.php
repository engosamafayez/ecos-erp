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

final class ApprovePurchaseMaterialAction
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

            // TASK-PROCUREMENT-MANUAL-REMEDIATION-001 (PR-03 status workflow).
            // The under_review → waiting_supplier_selection hop was never wired: no
            // code path wrote waiting_supplier_selection, so that state — the one that
            // unlocks supplier selection — was unreachable and Approve failed on an
            // under_review request even though the drawer offers the button (and
            // Reject already accepts both states). Approve now advances one step along
            // the enum's own nextWorkflowState(): under_review → waiting_supplier_selection,
            // then waiting_supplier_selection → approved. Nothing new is invented.
            $reviewStates = [
                PurchaseMaterialStatus::UnderReview,
                PurchaseMaterialStatus::WaitingSupplierSelection,
            ];

            if (! in_array($material->status, $reviewStates, true)) {
                throw new InvalidPurchaseMaterialStatusException(
                    $material->request_number,
                    $material->status->value,
                    array_map(static fn (PurchaseMaterialStatus $s): string => $s->value, $reviewStates),
                );
            }

            $next = $material->status->nextWorkflowState();

            if ($next === null) {
                throw new InvalidPurchaseMaterialStatusException(
                    $material->request_number,
                    $material->status->value,
                    array_map(static fn (PurchaseMaterialStatus $s): string => $s->value, $reviewStates),
                );
            }

            $attributes = [
                'status' => $next->value,
                'updated_by' => (string) $request->user()?->id,
            ];

            // Stamp approval only on the terminal approval hop, not the intermediate one.
            if ($next === PurchaseMaterialStatus::Approved) {
                $attributes['approved_at'] = now();
                $attributes['approved_by'] = (string) $request->user()?->id;
            }

            $material->update($attributes);

            if ($request->user() !== null) {
                $this->ownership->claimIfUnowned($material, $request->user());
            }

            $this->timeline->record(
                companyId: (string) $material->company_id,
                subjectType: 'PurchaseMaterial',
                subjectId: (string) $material->id,
                eventType: 'purchase_material.approved',
                title: $next === PurchaseMaterialStatus::Approved
                    ? 'Request approved'
                    : 'Request accepted for supplier selection',
                actorId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                actorName: $request->user()?->name,
                sourceModule: 'Purchasing.PurchaseMaterials',
            );

            $this->audit->record(
                action: 'purchase_material.approved',
                entityType: 'PurchaseMaterial',
                entityId: (string) $material->id,
                companyId: $material->company_id,
                userId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                newValues: ['status' => $next->value],
            );

            return $material;
        });

        $message = $material->status === PurchaseMaterialStatus::Approved
            ? 'Purchase material approved.'
            : 'Purchase material accepted for supplier selection.';

        return OperationResult::success($material->refresh(), $message);
    }
}
