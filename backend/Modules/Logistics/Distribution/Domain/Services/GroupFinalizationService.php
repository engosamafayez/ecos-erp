<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;

/**
 * Finalize a Distribution Group into its transport execution object(s).
 *
 * ┌─ WHAT FINALIZE MEANS ────────────────────────────────────────────────────┐
 * │ "The Group's planning and warehouse preparation phase is complete, and    │
 * │  its transport execution can now be created."                            │
 * │                                                                          │
 * │   Group (plan)  ──Finalize──►  Trip(s) (execute)                         │
 * │                                                                          │
 * │ 1 Group → 1 Trip normally. 1 Group → N Trips ONLY when Trip.capacity      │
 * │ forces it. 1 Trip → exactly 1 Group, structurally.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE SPLIT RULE INVENTS NOTHING. Orders are assigned to a Trip one at a time, in
 * a stable order, through the EXISTING `TripService::assignOrder`. When the current
 * Trip reports `remainingCapacity() === 0` — the same rule `assignOrder` already
 * enforces — the next Trip is opened. The split therefore EMERGES from the
 * capacity rule that already exists rather than from an allocation algorithm
 * chosen here. There is no optimisation, no bin-packing, no balancing: fill, then
 * overflow. Deterministic for a given order sequence.
 *
 * WHAT IT NEVER DOES:
 *   • never writes an Order status — that belongs to Operations\Fulfillment, and
 *     is structurally enforced by Order::booted() + OrderStatusGuard;
 *   • never touches inventory — the mutation boundary is Dispatch, not Finalize;
 *   • never touches Preparation — no wave, no wave item, no pool;
 *   • never changes Group membership — the Group remains the planning source of
 *     truth and Finalize only READS it;
 *   • never copies the Group's warehouse onto the Trip — it is derived through
 *     the relation (Trip::operationalWarehouseId()).
 *
 * IDEMPOTENT BY RE-READ, NOT BY KEY. A second Finalize returns the Trips the first
 * one produced. The check runs INSIDE the Group's row lock, so two concurrent
 * Finalizes cannot both pass it — the same lock-then-decide shape the platform
 * already uses (CapacityLedgerService::reserve, GroupPreparationService::record).
 * No idempotency-key infrastructure is introduced; none exists in this platform.
 */
final class GroupFinalizationService
{
    public function __construct(
        private readonly DistributionAggregationService $aggregation,
        private readonly GroupPreparationService $preparation,
        private readonly TripService $trips,
    ) {}

    /**
     * Finalize the Group, producing (or returning) its Trips.
     *
     * @param  VirtualCapacitySlot  $group  ALREADY tenant-resolved by the caller.
     * @return list<Trip> the Group's live Trips, in creation order
     *
     * @throws DistributionException on any unmet prerequisite; the controller
     *                               renders these as HTTP 422.
     */
    public function finalize(
        VirtualCapacitySlot $group,
        ?int $actorId = null,
        bool $approveOverflow = false,
    ): array {
        return DB::transaction(function () use ($group, $actorId, $approveOverflow): array {
            // 1. LOCK THE GROUP. Serialises Finalize against itself and against the
            //    Prepared writes, which lock the same row.
            /** @var VirtualCapacitySlot $locked */
            $locked = VirtualCapacitySlot::query()->lockForUpdate()->findOrFail($group->id);

            // 2. IDEMPOTENCY, inside the lock. Already finalized → return what exists.
            //    Cancelled Trips do not count: a Group whose only Trip was cancelled
            //    has no live execution and may be finalized again.
            $existing = Trip::query()
                ->where('virtual_slot_id', $locked->id)
                ->where('status', '!=', TripStatus::Cancelled->value)
                ->orderBy('id')
                ->get();

            // Idempotency keys on FINALIZATION, not on mere existence. A Group finalized
            // before returns its finalized Trips unchanged. But a BARE Trip — one an
            // assign-vehicle commitment materialised ahead of Finalize
            // (GroupVehicleAssignmentService::resolveTrip) — carries no orders and has
            // `finalized_at IS NULL`. Treating its mere existence as "already finalized"
            // is precisely what left the assign-vehicle-first path's Trip permanently
            // order-less. When every existing Trip is unfinalized, Finalize proceeds and
            // ADOPTS one (see buildTrips), so the two creation paths converge on one Trip.
            if ($existing->contains(static fn (Trip $t): bool => $t->finalized_at !== null)) {
                return $existing->all();
            }

            // 3. OVERFLOW APPROVAL, if the operator asked for it — TASK-1-B-A2.
            //    Recorded INSIDE the Group's lock, before the prerequisite check reads
            //    it, so the approval and the decision it authorises cannot straddle a
            //    concurrent write. Idempotent by value: approving twice at the same
            //    occupancy rewrites the same figure and creates nothing.
            if ($approveOverflow) {
                $this->approveOverflow($locked, $actorId);
            }

            // 4. PREREQUISITES.
            $orderIds = $this->assertFinalizable($locked);

            // 5. Produce the Trips.
            return $this->buildTrips($locked, $orderIds, $actorId);
        });
    }

    /**
     * Record the operator's explicit approval to exceed this Group's planning capacity.
     *
     * ┌─ WHAT IS AND IS NOT WRITTEN ─────────────────────────────────────────────┐
     * │ WRITTEN: the approved order COUNT, plus who and when.                     │
     * │ NOT WRITTEN: `capacity_orders`. The planning limit is untouched, so the    │
     * │ Group still reports a maximum of 20 while carrying 25 approved orders.     │
     * │ Raising the limit — or nulling it — would destroy the very constraint the  │
     * │ approval exists to make an exception to.                                   │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * REFUSES A POINTLESS APPROVAL. A Group within its capacity, or with no maximum at
     * all, has nothing to approve; approving anyway would leave a misleading audit row
     * claiming an exception that never happened.
     *
     * IDEMPOTENT BY VALUE. The approved figure is the occupancy at the moment of
     * approval, so approving twice at the same occupancy writes the same number. The
     * caller holds the Group's row lock, so two operators cannot interleave.
     *
     * BOUNDED, NOT A WAIVER. Because the count is stored, later growth past it makes
     * `hasApprovedOverflowFor()` false again and Finalize asks the operator once more.
     */
    private function approveOverflow(VirtualCapacitySlot $group, ?int $actorId): void
    {
        $capacity = $group->capacity_orders;

        if ($capacity === null) {
            throw new DistributionException(
                'This group has no maximum, so there is no overflow to approve.',
            );
        }

        $occupancy = count($this->aggregation->orders(
            $group->distribution_window_id,
            null,
            $group->id,
            ['warehouse_id' => $group->warehouse_id],
        ));

        if ($occupancy <= $capacity) {
            throw new DistributionException(sprintf(
                'This group holds %d orders and its maximum is %d, so there is no overflow to approve.',
                $occupancy,
                $capacity,
            ));
        }

        // forceFill, because these three are deliberately not $fillable — an approval is
        // never mass-assignable from a request payload.
        $group->forceFill([
            'overflow_approved_orders' => $occupancy,
            'overflow_approved_at' => now(),
            'overflow_approved_by' => $actorId,
        ])->save();
    }

    /**
     * Every prerequisite, checked before a single Trip is created.
     *
     * @return list<array{order_id: string, zone_code: string|null, governorate: string|null}>
     */
    private function assertFinalizable(VirtualCapacitySlot $group): array
    {
        // WAREHOUSE. NOT NULL since Part 5B, but a Group that somehow lost it must
        // not produce a Trip with no derivable warehouse.
        if ($group->warehouse_id === null || $group->warehouse_id === '') {
            throw new DistributionException('This group has no warehouse and cannot be finalized.');
        }

        // MEMBERSHIP + ELIGIBILITY, from the canonical read model — the same
        // loading-eligibility predicate LP-1.0 established. No second definition.
        $orders = $this->aggregation->orders(
            $group->distribution_window_id,
            null,
            $group->id,
            ['warehouse_id' => $group->warehouse_id],
        );

        if ($orders === []) {
            // Part 11: never create empty Trips.
            throw new DistributionException(
                'This group has no eligible orders to finalize. Nothing would be transported.',
            );
        }

        // GROUP CAPACITY. Advisory on the ingestion path (GroupCapacityGuard explains
        // why), but Finalize is the moment the plan becomes execution, so it is enforced
        // HERE rather than silently carried forward. NULL stays unconstrained — never zero.
        //
        // TASK-1-B-A2: an EXPLICIT operator approval is now a third outcome. The check is
        // unchanged for every Group that has none — this only adds a way past it, never a
        // way around it:
        //
        //   within capacity                      -> allowed (unchanged)
        //   over capacity, no approval           -> refused (unchanged)
        //   over capacity, approved for >= count -> allowed  (new)
        //
        // `capacity_orders` is neither read differently nor written here. The Group's
        // maximum stays exactly what the operator set; the approval records an accepted
        // exception to it, and `hasApprovedOverflowFor()` bounds that exception by the
        // count that was actually approved.
        if ($group->capacity_orders !== null
            && count($orders) > $group->capacity_orders
            && ! $group->hasApprovedOverflowFor(count($orders))
        ) {
            throw new DistributionException(sprintf(
                'This group holds %d orders but its maximum is %d. '
                .'Approve the overflow or move orders out before finalizing.',
                count($orders),
                $group->capacity_orders,
            ));
        }

        // LOADING PREPARATION. An OVER-prepared product means Required fell after the
        // floor separated stock — an order left, was cancelled, or was postponed.
        // Finalizing that would send a Trip out against a plan the warehouse has
        // already diverged from, so it is refused until a human resolves it.
        // Under-preparation is NOT refused: partial preparation is legitimate and
        // travels as a short quantity, which is the certified contract.
        $overPrepared = $this->overPreparedProducts($group);

        if ($overPrepared !== []) {
            throw new DistributionException(sprintf(
                'Loading preparation is inconsistent: %d product(s) have more prepared than this group '
                .'now requires. Resolve the over-prepared quantities before finalizing.',
                count($overPrepared),
            ));
        }

        // Stable order. The split is deterministic only if the sequence is.
        usort($orders, static fn (array $a, array $b): int => strcmp(
            (string) ($a['order_number'] ?? ''),
            (string) ($b['order_number'] ?? ''),
        ));

        return array_map(static fn (array $o): array => [
            'order_id' => (string) $o['order_id'],
            'zone_code' => isset($o['zone_code']) ? (string) $o['zone_code'] : null,
            'governorate' => isset($o['governorate_name']) ? (string) $o['governorate_name'] : null,
        ], $orders);
    }

    /**
     * Products whose recorded Prepared now exceeds the Group's live Required.
     *
     * @return list<string> product ids
     */
    private function overPreparedProducts(VirtualCapacitySlot $group): array
    {
        $prepared = $this->preparation->preparedByProduct($group->id);

        if ($prepared === []) {
            return [];
        }

        $required = [];

        foreach ($this->aggregation->productAggregation(
            $group->distribution_window_id,
            null,
            $group->id,
            $group->warehouse_id,
        ) as $row) {
            $required[(string) $row['product_id']] = (float) $row['total_quantity'];
        }

        $over = [];

        foreach ($prepared as $productId => $qty) {
            // Same epsilon the platform uses for decimal(x,4) comparisons.
            if ((float) $qty - ($required[(string) $productId] ?? 0.0) > 0.00005) {
                $over[] = (string) $productId;
            }
        }

        return $over;
    }

    /**
     * Create the Trips and fill them, overflowing into a new Trip at capacity.
     *
     * @param  list<array{order_id: string, zone_code: string|null, governorate: string|null}>  $orders
     * @return list<Trip>
     */
    private function buildTrips(VirtualCapacitySlot $group, array $orders, ?int $actorId): array
    {
        /** @var list<Trip> $trips */
        $trips = [];

        // ADOPT an existing unfinalized Trip if one is present — the bare Trip an
        // assign-vehicle commitment materialised ahead of Finalize. Filling it, rather
        // than opening a rival Trip beside it, is what makes assign-vehicle-first and
        // finalize-first converge on the SAME Trip: the one already carrying the vehicle.
        // Only an UNFINALIZED, non-cancelled Trip is adopted, so a re-finalize can never
        // mutate a sealed Trip; the capacity split below is unchanged — overflow still
        // opens fresh Trips once this one is full.
        $trip = Trip::query()
            ->where('virtual_slot_id', $group->id)
            ->whereNull('finalized_at')
            ->where('status', '!=', TripStatus::Cancelled->value)
            ->orderBy('id')
            ->first();

        if ($trip !== null) {
            $trips[] = $trip;
        }

        foreach ($orders as $order) {
            // Open a Trip — the first, when none was adopted, and every subsequent one —
            // only when there is an order to put in it, so an empty Trip is never created.
            if ($trip === null || $trip->remainingCapacity() === 0) {
                $trip = $this->openTrip($group, count($trips) + 1, $actorId);
                $trips[] = $trip;
            }

            $this->trips->assignOrder(
                $trip,
                $order['order_id'],
                ['zone_code' => $order['zone_code'], 'governorate' => $order['governorate']],
                $actorId,
                'auto',
            );

            $trip->refresh();
        }

        // Seal each Trip: stamp the finalize decision and leave Planning.
        //
        // `Planning → Loading` is an EXISTING allowed transition, and Loading is
        // still editable — so this freezes nothing yet, which is correct: the
        // warehouse has not finished loading. Composition freezes later, at
        // `Loading → LoadingCompleted`, which is the existing contract and is not
        // changed here.
        foreach ($trips as $t) {
            $t->forceFill([
                'finalized_at' => now(),
                'finalized_by' => $actorId,
            ])->save();

            $this->trips->changeStatus(
                $t,
                TripStatus::Loading,
                reason: 'Group '.$group->code.' finalized',
                actor: $actorId === null ? null : (string) $actorId,
            );
            $t->refresh();
        }

        return $trips;
    }

    /**
     * A new Trip owned by this Group. Capacity and type keep their table defaults.
     *
     * The `refresh()` is load-bearing, not cosmetic. `Trip::create()` returns a model
     * carrying only the attributes that were passed, so `capacity` is NULL IN MEMORY
     * even though the column defaults to 60. Without the re-read,
     * `remainingCapacity()` computes `max(0, null - 0) = 0`, the very first order is
     * rejected as "at capacity", and `tripAtCapacity(null)` then dies on its int
     * type-hint. Reading the value back is also what keeps the default in the SCHEMA
     * rather than duplicating it as a constant here.
     */
    private function openTrip(VirtualCapacitySlot $group, int $sequence, ?int $actorId): Trip
    {
        $trip = $this->trips->create([
            'company_id' => $group->company_id,
            // THE OWNERSHIP. Single-valued, so this Trip can never name another Group.
            'virtual_slot_id' => $group->id,
            'name' => $group->code.' · '.$sequence,
            'created_by' => $actorId,
            // capacity / type / status keep their schema defaults (60 /
            // company_vehicle / planning). Capacity is deliberately NOT derived from
            // a vehicle: no vehicle is assigned yet, and the architecture decision
            // established that trip capacity is operator-declared, with the vehicle
            // fit checked later in Dispatch's proposal path.
        ], $actorId === null ? null : (string) $actorId);

        return $trip->refresh();
    }
}
