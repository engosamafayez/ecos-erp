<?php

declare(strict_types=1);

namespace Modules\Logistics\Operations\Domain\Services;

use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\SettlementStatus;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;

/**
 * TASK-ECOS-V1.1-OPS-04-TASK1 — settlement visibility, composed entirely from
 * TripSettlement.status (the canonical SettlementStatus authority) and each
 * trip's own DeliveryStop settlement state. No settlement economics are
 * recalculated here — that remains exclusively DriverDaySettlementReadService/
 * SettlementService's job. Section 9: settlement-closure-vs-physical-return
 * policy is NOT altered or enforced here — both facts are exposed
 * independently for the UI to display side by side.
 */
class SettlementMonitoringService
{
    /**
     * @return array<string, mixed>
     */
    public function settlement(?string $companyId = null): array
    {
        $counts = TripSettlement::query()
            ->when($companyId !== null, fn ($q) => $q->whereHas('trip', fn ($t) => $t->where('company_id', $companyId)))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sumFor = static fn (array $statuses): int => (int) collect($statuses)
            ->sum(fn (SettlementStatus $s) => $counts[$s->value] ?? 0);

        // "Collection difference pending" reuses the SAME underlying condition
        // DriverDaySettlementReadService::collectionsBreakdown() uses to decide
        // whether to report collection_difference or collection_difference_pending
        // (Section 8/9): a settlement whose trip still has at least one
        // DeliveryStop that has not reached DeliveryStopStatus::isSettled() has
        // no final collection picture yet, by construction — never recalculated
        // independently here.
        $collectionDifferencePending = TripSettlement::query()
            ->when($companyId !== null, fn ($q) => $q->whereHas('trip', fn ($t) => $t->where('company_id', $companyId)))
            ->whereHas('trip.stops', fn ($q) => $q->whereNotIn('status', array_map(
                static fn (DeliveryStopStatus $s) => $s->value,
                array_filter(DeliveryStopStatus::cases(), static fn ($s) => $s->isSettled()),
            )))
            ->count();

        return [
            'draft' => $sumFor([SettlementStatus::Draft]),
            'submitted' => $sumFor([SettlementStatus::Submitted]),
            'reconciled' => $sumFor([SettlementStatus::Reconciled]),
            'disputed' => $sumFor([SettlementStatus::Disputed]),
            'finalized' => $sumFor([SettlementStatus::Finalized]),
            // Section 9: exposed as an independent, factual count — never used
            // here to block or alter finalization eligibility.
            'collection_difference_pending' => $collectionDifferencePending,
        ];
    }
}
