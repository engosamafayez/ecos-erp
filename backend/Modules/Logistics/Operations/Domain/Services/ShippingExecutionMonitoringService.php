<?php

declare(strict_types=1);

namespace Modules\Logistics\Operations\Domain\Services;

use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;

/**
 * TASK-ECOS-V1.1-OPS-04-TASK1 — Shipping lifecycle counts, derived entirely
 * from canonical Trip/DeliveryStop/DistributionWindowOrder facts. No new
 * status vocabulary: every count groups by the EXISTING TripStatus/
 * DeliveryStopStatus enum values, reusing their own derived value-sets
 * (custodyEligibleValues/onTheRoadValues) rather than hand-listing a second,
 * driftable copy.
 */
class ShippingExecutionMonitoringService
{
    /**
     * Trip counts by lifecycle stage. Every bucket is a disjoint TripStatus
     * partition, so the buckets sum to the company's total non-cancelled trip
     * count — no hidden residual.
     *
     * @return array<string, mixed>
     */
    public function trips(?string $companyId = null): array
    {
        $counts = Trip::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sumFor = static fn (array $statuses): int => (int) collect($statuses)
            ->sum(fn (TripStatus $s) => $counts[$s->value] ?? 0);

        return [
            'awaiting_loading' => $sumFor([TripStatus::Planning]),
            'loading_in_progress' => $sumFor([TripStatus::Loading]),
            'ready_for_dispatch' => $sumFor([TripStatus::LoadingCompleted, TripStatus::DriverAccepted, TripStatus::ReadyForDispatch]),
            'dispatch_blocked' => $sumFor([TripStatus::DispatchBlocked]),
            // Reuses TripStatus::onTheRoadValues() directly — the same derived
            // set Trip::isReadyForDispatch()/completeStop() gating already uses.
            'executing' => (int) collect(TripStatus::onTheRoadValues())->sum(fn (string $v) => $counts[$v] ?? 0),
            'completed_pending_settlement' => $sumFor([TripStatus::Completed, TripStatus::SettlementPending]),
            'closed' => $sumFor([TripStatus::Closed]),
            'cancelled' => $sumFor([TripStatus::Cancelled]),
            'external_carrier_trips' => Trip::query()
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->where('type', TripType::ExternalCarrier->value)
                ->whereNotIn('status', [TripStatus::Closed->value, TripStatus::Cancelled->value])
                ->count(),
        ];
    }

    /**
     * Groups (VirtualCapacitySlot) that have not yet produced a Trip — the
     * "awaiting trip assignment" population GroupFinalizationService::finalize()
     * would act on next. Derived from the real Group->Trip relationship
     * (Trip.virtual_slot_id), never a separate status field.
     */
    public function groupsAwaitingTripAssignment(?string $companyId = null): int
    {
        $tripGroupIds = Trip::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('virtual_slot_id')
            ->distinct()
            ->pluck('virtual_slot_id');

        return VirtualCapacitySlot::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotIn('id', $tripGroupIds)
            ->count();
    }

    /**
     * Delivery stop counts by canonical DeliveryStopStatus, plus a
     * "retryable_failed" signal reusing the EXACT same FailureReason::isRetryable()
     * rule ReleaseOrderOnRetryableOutcomeListener already applies — a snapshot of
     * how many currently-Failed stops carry a retryable reason, not a claim about
     * which orders have already been released for replanning (that bookkeeping
     * lives on TripOrder.superseded_at, a separate, already-correct mechanism this
     * summary does not need to duplicate).
     *
     * @return array<string, mixed>
     */
    public function deliveryStops(?string $companyId = null): array
    {
        $counts = DeliveryStop::query()
            ->when($companyId !== null, fn ($q) => $q->whereHas('trip', fn ($t) => $t->where('company_id', $companyId)))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = [];
        foreach (DeliveryStopStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        // NOTE: checks whether ANY recorded action on the stop carries a
        // retryable reason, not strictly only its most-recent one — whereHas()
        // cannot cleanly express "only the latest related row" as an existence
        // check, and the canonical listener's own per-event decision (at the
        // moment the stop settled) is not re-derived here. This is therefore a
        // deliberately looser snapshot signal ("stops that carry a retryable
        // reason among their actions"), not a claim equivalent to the live
        // ReleaseOrderOnRetryableOutcomeListener's own real-time decision.
        $retryableReasons = array_map(
            static fn ($r) => $r->value,
            array_filter(
                \Modules\Logistics\Delivery\Domain\Enums\FailureReason::cases(),
                static fn ($r) => $r->isRetryable(),
            ),
        );

        $retryableFailed = DeliveryStop::query()
            ->when($companyId !== null, fn ($q) => $q->whereHas('trip', fn ($t) => $t->where('company_id', $companyId)))
            ->where('status', DeliveryStopStatus::Failed->value)
            ->whereHas('actions', fn ($q) => $q->whereIn('reason', $retryableReasons))
            ->count();

        return [
            'by_status' => $byStatus,
            'retryable_failed' => $retryableFailed,
        ];
    }

    /**
     * Zoned/unzoned and grouped/ungrouped reconciliation for the current
     * Distribution Window population (Section 4/6's required bucket
     * vocabulary) — both derived from DistributionWindowOrder's own nullable
     * distribution_zone_id/virtual_slot_id columns, so
     * zoned + unzoned = total and grouped + ungrouped = total by construction
     * (no hidden residual, no manufactured bucket record).
     *
     * @return array<string, mixed>
     */
    public function windowOrderReconciliation(?string $companyId = null): array
    {
        // A DistributionWindowOrder row's own virtual_slot_id is nulled (not the
        // row deleted) when DailyGroupLifecycleService::closeWave() releases it
        // back to the pool — so every row for this company IS the current
        // membership population; there is no separate "released_at" to filter by.
        $base = DistributionWindowOrder::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));

        $total = (clone $base)->count();
        $zoned = (clone $base)->whereNotNull('distribution_zone_id')->count();
        $grouped = (clone $base)->whereNotNull('virtual_slot_id')->count();

        return [
            'total' => $total,
            'zoned' => $zoned,
            'unzoned' => $total - $zoned,
            'grouped' => $grouped,
            'ungrouped' => $total - $grouped,
        ];
    }
}
