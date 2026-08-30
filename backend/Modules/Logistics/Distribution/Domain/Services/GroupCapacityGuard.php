<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;

/**
 * The ONE place a Distribution Group's order-count capacity is enforced on write.
 *
 * ┌─ WHY THIS EXISTS ────────────────────────────────────────────────────────┐
 * │ `capacity_orders` has been on the Group since the table was created, and  │
 * │ GroupFinalizationService's own comment states the problem exactly:        │
 * │ "Advisory today (no write path enforces it)". So an operator could pile   │
 * │ 40 orders into a Group whose maximum was 20 and only discover it at       │
 * │ Finalize — after the plan had been built, zones attached and preparation  │
 * │ quantities entered against it.                                           │
 * │                                                                          │
 * │ Finalize keeps its check. It is the backstop for over-capacity that       │
 * │ arrives by AUTOMATIC ingestion, which this guard deliberately does not    │
 * │ police (see below). This guard closes the OPERATOR paths.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * CAPACITY IS ORDER COUNT ONLY (decision D4-C). `capacity_stops`,
 * `capacity_weight_kg` and `capacity_volume_m3` exist on the row and are
 * deliberately NOT read here: nothing in the system enforces them, and enforcing
 * one of them for the first time inside a capacity guard would be a new business
 * rule smuggled in as a bug fix. Vehicle capacity stays where it already is, in
 * GroupVehicleAssignmentService at the assignment stage.
 *
 * NULL IS UNCONSTRAINED, NEVER ZERO. That is the existing contract, stated in
 * both `VirtualCapacitySlot::hasCapacity()` and the Finalize check. A Group with
 * no maximum accepts orders exactly as it does today, so this guard cannot change
 * the behaviour of any Group that has not been given a limit.
 *
 * ONE COUNTING DEFINITION. Current occupancy is read from
 * `DistributionAggregationService::slotOrderCounts()` — the same aggregate the
 * read model calls "the capacity maths", carrying the same loading-eligibility
 * predicate LP-1.0 established. A targeted COUNT(*) here would be a second
 * definition of occupancy, and the guard could then refuse an order the screen
 * says there is room for. This mirrors GroupPreparationService, which recomputes
 * Required from the canonical aggregation inside its own lock for the identical
 * reason.
 *
 * WHY AUTOMATIC INGESTION IS NOT POLICED. `DistributionCollectionService::attach()`
 * lets a newly collected Order inherit its Group from its Zone. Refusing it on
 * capacity would leave the Order with no distribution assignment at all — the
 * unique index means it cannot be retried later — so a capacity limit would start
 * silently dropping work out of Distribution. Ingestion therefore stays
 * unconstrained and Finalize remains its gate, which is the behaviour that is
 * already certified.
 */
final class GroupCapacityGuard
{
    public function __construct(
        private readonly DistributionAggregationService $aggregation,
    ) {}

    /**
     * Refuse the write unless the Group can hold `$incoming` more Orders.
     *
     * MUST be called inside a transaction: the lock it takes is only meaningful
     * until commit. It asserts rather than opening its own transaction, so the
     * lock spans the caller's whole write instead of being released before it.
     *
     * Returns the LOCKED Group, so a caller can keep using the row it just
     * serialised on rather than the possibly-stale instance it passed in.
     *
     * @param  int  $incoming  how many Orders this write is about to add
     *
     * @throws DistributionException when the limit would be exceeded
     */
    public function assertHasHeadroom(VirtualCapacitySlot $group, int $incoming = 1): VirtualCapacitySlot
    {
        // 1. LOCK THE GROUP. Two operators adding to the same Group serialise
        //    here, which is what makes the count below a decision rather than a
        //    guess. The locking read also establishes this transaction's read
        //    view after the previous holder committed, so step 3 sees their rows.
        /** @var VirtualCapacitySlot $locked */
        $locked = VirtualCapacitySlot::query()->lockForUpdate()->findOrFail($group->id);

        // 2. NULL stays unconstrained. Read after the lock, not before, so the
        //    answer cannot come from a stale copy of the row.
        if ($locked->capacity_orders === null) {
            return $locked;
        }

        // 3. LIVE OCCUPANCY, recomputed inside the lock from the canonical
        //    aggregate — scoped to the Group's OWN warehouse, matching how the
        //    Group's totals are scoped everywhere else.
        $current = $this->currentOccupancy($locked);

        if ($current + $incoming > $locked->capacity_orders) {
            throw new DistributionException($this->message($locked, $current, $incoming));
        }

        return $locked;
    }

    /**
     * Refuse a new maximum that is below what the Group already holds.
     *
     * Lowering a limit past current occupancy would create a Group that is over
     * capacity the instant it is saved and could then never be finalized — so the
     * edit is refused instead, naming the number the operator has to reach. A null
     * maximum (removing the limit) is always allowed.
     */
    public function assertCapacityFitsCurrentOrders(VirtualCapacitySlot $group, ?int $capacity): void
    {
        if ($capacity === null) {
            return;
        }

        $current = $this->currentOccupancy($group);

        if ($capacity < $current) {
            throw new DistributionException(sprintf(
                'This group already holds %d orders, so its maximum cannot be set to %d. '
                .'Move orders out of the group first.',
                $current,
                $capacity,
            ));
        }
    }

    /** Orders currently occupying the Group, by the canonical capacity aggregate. */
    public function currentOccupancy(VirtualCapacitySlot $group): int
    {
        $counts = $this->aggregation->slotOrderCounts(
            $group->distribution_window_id,
            $group->warehouse_id,
        );

        return (int) ($counts[$group->id] ?? 0);
    }

    /**
     * Remaining headroom, or null when the Group has no maximum.
     *
     * DERIVED, NEVER STORED — `max_orders - current_orders`. Floored at zero: a
     * Group that ingestion pushed past its limit has no remaining capacity, and a
     * negative number would read as though it owed orders.
     */
    public function remainingCapacity(VirtualCapacitySlot $group): ?int
    {
        if ($group->capacity_orders === null) {
            return null;
        }

        return max(0, $group->capacity_orders - $this->currentOccupancy($group));
    }

    private function message(VirtualCapacitySlot $group, int $current, int $incoming): string
    {
        if ($incoming === 1) {
            return sprintf(
                'This group is at its maximum of %d orders. Raise the maximum or move an order out first.',
                $group->capacity_orders,
            );
        }

        return sprintf(
            'Adding %d orders would put this group over its maximum of %d; it holds %d and has room for %d. '
            .'Raise the maximum or choose fewer orders.',
            $incoming,
            $group->capacity_orders,
            $current,
            max(0, $group->capacity_orders - $current),
        );
    }
}
