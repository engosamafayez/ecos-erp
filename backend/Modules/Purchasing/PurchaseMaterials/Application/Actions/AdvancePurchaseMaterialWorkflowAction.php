<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Application\Actions;

use App\Core\Audit\AuditService;
use App\Core\Timeline\TimelineService;
use Modules\Purchasing\PurchaseMaterials\Domain\Enums\PurchaseMaterialStatus;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;
use Modules\Purchasing\PurchaseMaterials\Domain\Services\PurchaseMaterialReceivingService;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011 §7-§10.
 *
 * Advances a Purchase Material's status as a DERIVED CONSEQUENCE of its lines' ordering and
 * receiving progress. The request carries no stored "% done" — this recomputes from the lines
 * every time it runs and writes only the resulting status, mirroring
 * PurchaseMaterialReceivingService's "derived, never stored" philosophy at the header level.
 *
 * Deliberately conservative: only ever moves a request FORWARD through
 * Approved -> Purchasing -> Receiving -> Completed, and only starting from those three states. A
 * request that is Draft/UnderReview/WaitingSupplierSelection (ordering hasn't started) or
 * OnHold/Rejected/Cancelled (an exception state a human deliberately put it in) is never touched
 * here — only an explicit action moves those.
 *
 * Call this from inside the caller's own DB::transaction() after committing whatever line change
 * triggered it (a supplier commitment or a posted receipt) — it takes its own row lock on the
 * header so concurrent recomputes for the same request serialize correctly, but relies on the
 * caller for everything else (this is not itself a transaction boundary).
 */
final class AdvancePurchaseMaterialWorkflowAction
{
    private const AUTO_ADVANCE_FROM = [
        PurchaseMaterialStatus::Approved,
        PurchaseMaterialStatus::Purchasing,
        PurchaseMaterialStatus::Receiving,
    ];

    public function __construct(
        private readonly PurchaseMaterialReceivingService $receiving,
        private readonly AuditService $audit,
        private readonly TimelineService $timeline,
    ) {}

    public function execute(string $purchaseMaterialId): void
    {
        $material = PurchaseMaterial::query()->lockForUpdate()->find($purchaseMaterialId);

        if ($material === null || ! in_array($material->status, self::AUTO_ADVANCE_FROM, true)) {
            return;
        }

        $lines = PurchaseMaterialLine::query()->where('purchase_material_id', $material->id)->get();

        if ($lines->isEmpty()) {
            return;
        }

        $allFulfilled = true;
        $anyReceived = false;
        $anyOrdered = false;

        foreach ($lines as $line) {
            $received = $this->receiving->receivedGross((string) $line->id);
            $requested = round((float) $line->requested_qty, 4);

            if ($received > 0) {
                $anyReceived = true;
            }
            if ($received < $requested) {
                $allFulfilled = false;
            }
            if ((float) ($line->agreed_qty ?? 0) > 0) {
                $anyOrdered = true;
            }
        }

        $target = match (true) {
            $allFulfilled => PurchaseMaterialStatus::Completed,
            $anyReceived => PurchaseMaterialStatus::Receiving,
            $anyOrdered => PurchaseMaterialStatus::Purchasing,
            default => $material->status,
        };

        if ($target === $material->status || $this->rank($target) < $this->rank($material->status)) {
            return;
        }

        $from = $material->status;
        $material->status = $target;
        if ($target === PurchaseMaterialStatus::Completed) {
            $material->completed_at = now();
        }
        $material->save();

        $this->timeline->record(
            companyId: (string) $material->company_id,
            subjectType: 'PurchaseMaterial',
            subjectId: (string) $material->id,
            eventType: 'purchase_material.status_advanced',
            title: "Request moved from {$from->label()} to {$target->label()}",
            actorType: 'system',
            sourceModule: 'Purchasing.PurchaseMaterials',
            metadata: ['from' => $from->value, 'to' => $target->value],
        );

        $this->audit->record(
            action: 'purchase_material.status_advanced',
            entityType: 'PurchaseMaterial',
            entityId: (string) $material->id,
            companyId: $material->company_id,
            oldValues: ['status' => $from->value],
            newValues: ['status' => $target->value],
            metadata: ['trigger' => 'fulfillment_recompute'],
        );
    }

    private function rank(PurchaseMaterialStatus $status): int
    {
        return match ($status) {
            PurchaseMaterialStatus::Approved => 0,
            PurchaseMaterialStatus::Purchasing => 1,
            PurchaseMaterialStatus::Receiving => 2,
            PurchaseMaterialStatus::Completed => 3,
            default => -1,
        };
    }
}
