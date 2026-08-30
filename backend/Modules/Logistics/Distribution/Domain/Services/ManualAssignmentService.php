<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\DistributionAssignmentSource;
use Modules\Logistics\Distribution\Domain\Events\DistributionAssignmentChanged;
use Modules\Logistics\Distribution\Domain\Events\LateOrderManuallyAssigned;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DistributionSlotZone;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;

/**
 * Manager-initiated changes to Distribution assignment.
 *
 * Every operation here is permitted AFTER cutoff. That is the point: cutoff
 * closes automatic ingestion, not the plan. The only state that refuses manual
 * work is a Closed Window, which has been handed on to Loading.
 *
 * None of these operations touch `orders.status`. An Order can move from
 * Zone A / Slot 1 to Zone B / Slot 2 without its lifecycle changing at all.
 */
final class ManualAssignmentService
{
    public function __construct(
        private readonly DistributionCollectionService $collection,
        private readonly GroupCapacityGuard $capacity,
        private readonly PreparationEligibilityReader $preparation,
    ) {}

    /**
     * Put a Zone into a Slot, and bring that Zone's already-collected Orders with it.
     *
     * The re-sync is what makes the aggregation live in the other direction: when
     * a Zone is attached to a Slot, the Orders already sitting in that Zone must
     * immediately count toward the Slot, not only Orders collected afterwards.
     */
    /**
     * @param  bool  $enforceCapacity  PASS FALSE ONLY FROM DAILY GROUP CREATION.
     *
     * TASK-DISTRIBUTION-DAILY-GROUP-WAVE-LIFECYCLE-002 PART 8. The owner-approved daily
     * rule is that a Group MAY exceed its Template's capacity — 27 eligible orders under
     * a capacity of 20 must produce ONE Group of 27, never a split and never a refusal of
     * order 21. The headroom guard refuses order 21, so creation needs a way past it.
     *
     * It is a PARAMETER, defaulting to true, rather than a change to the guard: every
     * existing caller — the Zones tab, manual attach, the operator moving a zone — keeps
     * the exact behaviour it has today, and only the one automatic creation path opts out.
     * Weakening `GroupCapacityGuard` itself would have silently lifted the limit off
     * manual work too, which is the opposite of what was asked.
     *
     * Capacity remains a planning threshold: the Group still records `capacity_orders`,
     * the board still reports the overflow, and Finalize still demands an explicit
     * operator approval before an over-capacity Group can leave.
     */
    public function assignZoneToSlot(
        DistributionWindow $window,
        int $zoneId,
        VirtualCapacitySlot $slot,
        bool $enforceCapacity = true,
    ): void {
        $this->assertManualAllowed($window);

        if ($slot->distribution_window_id !== $window->id) {
            throw new DistributionException('Slot belongs to a different Distribution Window.');
        }

        // CROSS-WAREHOUSE PROTECTION, server-side and before any write.
        //
        // A ZONE IS GEOGRAPHY, AND GEOGRAPHY IS SHARED. Two warehouses can both
        // deliver into Maadi, so "this zone contains another warehouse's orders" is
        // a normal state, not an error — each Group simply takes its own. Rejecting
        // on that alone would forbid the very case warehouse ownership exists to
        // make possible.
        //
        // What IS refused: claiming a Zone that holds work for someone else and
        // NONE for you. That attachment gains the Group nothing and takes a Zone
        // out of another warehouse's reach, so it can only be a mistake.
        $zoneOrders = DB::table('distribution_window_orders as dwo')
            ->join('orders as o', 'o.id', '=', 'dwo.order_id')
            ->where('dwo.distribution_window_id', $window->id)
            ->where('dwo.distribution_zone_id', $zoneId)
            ->whereNotNull('o.assigned_warehouse_id');

        $mine = (clone $zoneOrders)->where('o.assigned_warehouse_id', $slot->warehouse_id)->exists();
        $theirs = (clone $zoneOrders)->where('o.assigned_warehouse_id', '!=', $slot->warehouse_id)->exists();

        if ($theirs && ! $mine) {
            throw new DistributionException(
                'This zone holds no orders for this group\'s warehouse, only for another. '
                .'It cannot join this distribution group.',
            );
        }

        DB::transaction(function () use ($window, $zoneId, $slot, $enforceCapacity): void {
            // GROUP CAPACITY (order count only). A Zone attach is the main way
            // Orders enter a Group, so leaving it unchecked would make the limit
            // cosmetic — an operator would simply attach a Zone instead of moving
            // Orders one at a time. This is the SAME rule Finalize already applies,
            // enforced at the moment the plan is made rather than at the moment it
            // becomes execution.
            //
            // The incoming count is the Orders that would NEWLY join: this
            // warehouse's loading-eligible Orders in this Zone that are not already
            // in this Group. Re-attaching a Zone the Group already holds therefore
            // costs nothing and stays idempotent.
            $incoming = $enforceCapacity
                ? $this->eligibleZoneArrivals($window, $zoneId, $slot)
                : 0;

            if ($incoming > 0) {
                $this->capacity->assertHasHeadroom($slot, $incoming);
            }

            // Keyed by (window, warehouse, zone) to match the unique index: the same
            // Zone may legitimately be planned by two warehouses, each in its own
            // Group, and updateOrCreate must not treat those as the same row.
            DistributionSlotZone::query()->updateOrCreate(
                [
                    'distribution_window_id' => $window->id,
                    'warehouse_id' => $slot->warehouse_id,
                    'distribution_zone_id' => $zoneId,
                ],
                ['virtual_slot_id' => $slot->id],
            );

            // Only THIS warehouse's orders follow the Zone into the Group.
            DistributionWindowOrder::query()
                ->where('distribution_window_id', $window->id)
                ->where('distribution_zone_id', $zoneId)
                ->whereIn('order_id', function ($q) use ($slot): void {
                    $q->select('id')->from('orders')
                        ->where('assigned_warehouse_id', $slot->warehouse_id);
                })
                ->update(['virtual_slot_id' => $slot->id]);
        });
    }

    /** Detach a Zone from whatever Slot holds it, leaving its Orders slotless. */
    public function detachZone(DistributionWindow $window, int $zoneId, string $warehouseId): void
    {
        $this->assertManualAllowed($window);

        DB::transaction(function () use ($window, $zoneId, $warehouseId): void {
            // ONE warehouse's link, never every warehouse's (Rule 5). A Zone is
            // shared geography: removing Maadi from Main Warehouse's group must
            // leave Warehouse B's Maadi group exactly as it was.
            DistributionSlotZone::query()
                ->where('distribution_window_id', $window->id)
                ->where('distribution_zone_id', $zoneId)
                ->where('warehouse_id', $warehouseId)
                ->delete();

            // Only that warehouse's orders leave the group. They keep their Zone —
            // the Zone simply no longer belongs to a group for this warehouse, which
            // is a first-class operational state, not a deletion.
            DistributionWindowOrder::query()
                ->where('distribution_window_id', $window->id)
                ->where('distribution_zone_id', $zoneId)
                ->whereIn('order_id', function ($q) use ($warehouseId): void {
                    $q->select('id')->from('orders')
                        ->where('assigned_warehouse_id', $warehouseId);
                })
                ->update(['virtual_slot_id' => null]);
        });
    }

    /**
     * Move a Zone from one Distribution Group to another.
     *
     * ┌─ WHY THIS IS NOT JUST "ASSIGN AGAIN" ────────────────────────────────┐
     * │ Re-assigning would silently succeed across warehouses: the write is   │
     * │ keyed on the DESTINATION's warehouse, so "moving" Maadi from Main     │
     * │ Warehouse's group to Warehouse B's group would leave Main's group     │
     * │ untouched and quietly ADD a second, independent claim. The operator   │
     * │ asked to move one thing and two things would exist.                    │
     * │                                                                        │
     * │ A move is a planning operation INSIDE one warehouse. Across warehouses │
     * │ it is refused, and Warehouse B claims the Zone itself instead.         │
     * └────────────────────────────────────────────────────────────────────────┘
     *
     * Atomic: `assignZoneToSlot` performs the whole re-key and order re-sync in a
     * single transaction, so there is no state where the Zone belongs to both or
     * to neither.
     */
    public function moveZone(
        DistributionWindow $window,
        int $zoneId,
        VirtualCapacitySlot $from,
        VirtualCapacitySlot $to,
    ): void {
        if ($from->warehouse_id !== $to->warehouse_id) {
            throw new DistributionException(
                'A zone cannot be moved between groups of different warehouses. '
                .'The other warehouse claims the zone in its own group.',
            );
        }

        if ($from->id === $to->id) {
            return;
        }

        $held = DistributionSlotZone::query()
            ->where('distribution_window_id', $window->id)
            ->where('distribution_zone_id', $zoneId)
            ->where('virtual_slot_id', $from->id)
            ->exists();

        if (! $held) {
            throw new DistributionException('That zone is not in the source group.');
        }

        // One call, one transaction: the (window, warehouse, zone) row is re-pointed
        // and this warehouse's orders follow. Nothing is deleted first.
        $this->assignZoneToSlot($window, $zoneId, $to);
    }

    /**
     * Move one Order to a different Zone.
     *
     * Its Slot follows the destination Zone's mapping, because that mapping is
     * the source of truth for Slot membership. Passing a Zone with no Slot
     * legitimately leaves the Order slotless.
     *
     * `$zoneId === null` CLEARS the Zone — the Order becomes unzoned and slotless.
     * That case was previously inexpressible, and it had to become expressible: a
     * Zone is DERIVED from `orders.logistics_city_id`, so when an operator changes
     * an address to a city that resolves to nothing, the stored Zone is an
     * assertion nothing supports any more. Leaving it would keep the Order in a
     * zone its own address no longer implies. Widening this parameter changes no
     * existing caller and no existing behaviour: the HTTP endpoint still requires
     * `zone_id`, so only the internal geography-sync path can pass null.
     */
    public function changeOrderZone(
        DistributionWindowOrder $assignment,
        ?int $zoneId,
        ?int $actorId,
        ?string $reason = null,
    ): DistributionWindowOrder {
        $window = $assignment->window;
        $this->assertManualAllowed($window);

        $previousZone = $assignment->distribution_zone_id;
        $previousSlot = $assignment->virtual_slot_id;

        // The destination Group is the one belonging to THIS ORDER's warehouse.
        $orderWarehouseId = DB::table('orders')->where('id', $assignment->order_id)
            ->value('assigned_warehouse_id');

        // No Zone means no Group. Guarded explicitly rather than relying on a null
        // array key coercing to '' inside the slot map lookup.
        $slotId = $zoneId === null || $orderWarehouseId === null
            ? null
            : ($this->collection->slotMapForWindow($window->id, (string) $orderWarehouseId)[$zoneId] ?? null);

        // GROUP CAPACITY. Changing an Order's Zone moves it into the destination
        // Zone's Group, so it is an add to that Group and is checked as one. The
        // whole write is one transaction so the Group stays locked until the
        // assignment row is committed — otherwise two concurrent moves could both
        // pass the check and both land.
        return DB::transaction(function () use (
            $assignment, $zoneId, $slotId, $actorId, $reason, $previousZone, $previousSlot,
        ): DistributionWindowOrder {
            if ($slotId !== null && $slotId !== $previousSlot) {
                $destination = VirtualCapacitySlot::query()->find($slotId);

                if ($destination !== null) {
                    $this->capacity->assertHasHeadroom($destination, 1);
                }
            }

            $assignment->forceFill([
                'distribution_zone_id' => $zoneId,
                'virtual_slot_id' => $slotId,
                'assignment_source' => DistributionAssignmentSource::ManualMove->value,
                'assigned_by' => $actorId,
                'assignment_reason' => $reason,
            ])->save();

            DistributionAssignmentChanged::dispatch($assignment, $previousZone, $previousSlot);

            return $assignment;
        });
    }

    /**
     * Move one Order to a specific Slot, overriding its Zone's mapping.
     *
     * This is the operation a manager uses to resolve an overflow — including
     * approving a redistribution suggestion, which is nothing more than this call
     * made with the suggested Slot. The Zone is left untouched: the Order is
     * still geographically where it was.
     */
    public function changeOrderSlot(
        DistributionWindowOrder $assignment,
        ?VirtualCapacitySlot $slot,
        ?int $actorId,
        ?string $reason = null,
    ): DistributionWindowOrder {
        $window = $assignment->window;
        $this->assertManualAllowed($window);

        if ($slot !== null && $slot->distribution_window_id !== $window->id) {
            throw new DistributionException('Slot belongs to a different Distribution Window.');
        }

        $previousSlot = $assignment->virtual_slot_id;

        // GROUP CAPACITY. This is the direct "put this Order in that Group"
        // operation, so it is the primary path the limit has to hold on. Moving an
        // Order OUT of a Group ($slot === null) and re-stating the Group it is
        // already in are both free — neither adds an Order to anything.
        //
        // The check and the write share one transaction, which is what makes
        // concurrency safe: the second of two simultaneous moves blocks on the
        // Group row until the first has committed, then recounts and refuses.
        return DB::transaction(function () use (
            $assignment, $slot, $actorId, $reason, $previousSlot,
        ): DistributionWindowOrder {
            if ($slot !== null && $slot->id !== $previousSlot) {
                $this->capacity->assertHasHeadroom($slot, 1);
            }

            return $this->writeSlotChange($assignment, $slot, $actorId, $reason);
        });
    }

    /**
     * Move SEVERAL Orders into one Group, all of them or none.
     *
     * WHY THIS EXISTS AS ITS OWN OPERATION
     * `changeOrderSlot()` is atomic for ONE Order. Calling it five times is five
     * transactions, so a destination with three free places would accept three Orders
     * and refuse two, leaving the operator with a half-applied move and nothing to roll
     * back. Selecting five Orders is ONE operator decision, so it gets ONE transaction
     * and ONE capacity decision.
     *
     * NO SECOND ENGINE. Capacity is still `GroupCapacityGuard::assertHasHeadroom()`,
     * which was already N-aware — it is called ONCE for the whole batch rather than
     * once per Order, so the limit is decided on the real arrival count instead of
     * being re-derived five times. The write itself is the SAME `writeSlotChange()`
     * the single-Order path uses, so the two can never diverge.
     *
     * NO NEW PREDICATE. Every rule here is the single-Order contract applied to a set:
     * the Window must still accept manual assignment, and the destination must still
     * belong to that Window. Nothing about Zone compatibility, Trip state or Group
     * finalisation is added, because the single-Order path asserts none of those — a
     * batch that refused what one move allows would be a different contract, not a
     * safer one. That difference is documented in the task report.
     *
     * @param  list<DistributionWindowOrder>  $assignments  already tenancy-resolved by the caller
     * @return array{moved: int, slot_id: string|null, assignment_ids: list<string>, order_ids: list<string>}
     */
    public function changeOrderSlotBatch(
        array $assignments,
        ?VirtualCapacitySlot $slot,
        ?int $actorId,
        ?string $reason = null,
    ): array {
        if ($assignments === []) {
            throw new DistributionException('Select at least one order to move.');
        }

        // DUPLICATES ARE REFUSED, NEVER SILENTLY COLLAPSED. Interpreting a repeated id
        // as one Order would mean the operator's count and the server's count disagree,
        // and the capacity decision below is made on that count.
        $assignmentIds = array_map(
            static fn (DistributionWindowOrder $a): string => (string) $a->id,
            $assignments,
        );

        if (count(array_unique($assignmentIds)) !== count($assignmentIds)) {
            throw new DistributionException('The same order was selected more than once.');
        }

        // ONE WINDOW for the whole batch. The single-Order path compares the destination
        // against that Order's own Window; with a set there has to be one Window to
        // compare against, so a mixed selection is refused rather than half-applied.
        $windowIds = array_unique(array_map(
            static fn (DistributionWindowOrder $a): string => (string) $a->distribution_window_id,
            $assignments,
        ));

        if (count($windowIds) > 1) {
            throw new DistributionException(
                'All selected orders must belong to the same Distribution Window.',
            );
        }

        $window = $assignments[0]->window;
        $this->assertManualAllowed($window);

        if ($slot !== null && $slot->distribution_window_id !== $window->id) {
            throw new DistributionException('Slot belongs to a different Distribution Window.');
        }

        return DB::transaction(function () use ($assignments, $slot, $actorId, $reason, $assignmentIds): array {
            // ONE capacity decision, sized to the Orders that would actually ARRIVE.
            // An Order already in the destination costs nothing, exactly as re-stating
            // the current Group costs nothing on the single-Order path.
            if ($slot !== null) {
                $arrivals = 0;

                foreach ($assignments as $assignment) {
                    if ((string) $assignment->virtual_slot_id !== (string) $slot->id) {
                        $arrivals++;
                    }
                }

                if ($arrivals > 0) {
                    // Throws before ANY row is written, so a refusal moves nothing.
                    $this->capacity->assertHasHeadroom($slot, $arrivals);
                }
            }

            foreach ($assignments as $assignment) {
                $this->writeSlotChange($assignment, $slot, $actorId, $reason);
            }

            return [
                'moved' => count($assignments),
                'slot_id' => $slot?->id,
                'assignment_ids' => $assignmentIds,
                'order_ids' => array_map(
                    static fn (DistributionWindowOrder $a): string => (string) $a->order_id,
                    $assignments,
                ),
            ];
        });
    }

    /**
     * The slot write itself — shared by the single-Order and batch paths.
     *
     * Deliberately contains NO capacity check: the caller decides capacity once, for one
     * Order or for the whole set. Putting the check in here would make the batch pay for
     * it per Order and re-decide a limit it had already decided.
     *
     * Assumes it is already inside a transaction, which both callers guarantee.
     */
    private function writeSlotChange(
        DistributionWindowOrder $assignment,
        ?VirtualCapacitySlot $slot,
        ?int $actorId,
        ?string $reason,
    ): DistributionWindowOrder {
        $previousZone = $assignment->distribution_zone_id;
        $previousSlot = $assignment->virtual_slot_id;

        $assignment->forceFill([
            'virtual_slot_id' => $slot?->id,
            'assignment_source' => DistributionAssignmentSource::ManualMove->value,
            'assigned_by' => $actorId,
            'assignment_reason' => $reason,
        ])->save();

        DistributionAssignmentChanged::dispatch($assignment, $previousZone, $previousSlot);

        return $assignment;
    }

    /**
     * How many Orders a Zone attach would NEWLY bring into a Group.
     *
     * Counted with the SAME loading-eligibility predicate as
     * `slotOrderCounts()`, because that is what the capacity figure is measured
     * in. Counting raw assignment rows instead would refuse an attach on the
     * strength of cancelled or postponed Orders that the Group's own totals do not
     * include.
     *
     * Only this Group's warehouse is counted — a Zone is shared geography, and the
     * other warehouse's Orders in it are going to its own Group, not this one.
     * Orders already in this Group are excluded so a re-attach costs nothing.
     */
    private function eligibleZoneArrivals(
        DistributionWindow $window,
        int $zoneId,
        VirtualCapacitySlot $slot,
    ): int {
        $query = DB::table('distribution_window_orders as dwo')
            ->join('orders as o', 'o.id', '=', 'dwo.order_id')
            ->where('dwo.distribution_window_id', $window->id)
            ->where('dwo.distribution_zone_id', $zoneId)
            ->where('o.assigned_warehouse_id', $slot->warehouse_id)
            ->where(function ($q) use ($slot): void {
                $q->whereNull('dwo.virtual_slot_id')
                    ->orWhere('dwo.virtual_slot_id', '!=', $slot->id);
            });

        return $this->preparation->constrainToLoadingEligible($query, 'o')->count();
    }

    /**
     * Manual Late-Order Assignment — pull an Order into a Window past its cutoff.
     *
     * The Order stays inside Distribution: this is not a direct dispatch and not
     * a shipping bypass. Because an Order holds exactly one assignment, this
     * MOVES the existing row (typically from tomorrow's Window) rather than
     * creating a second one, and the previous Window is retained for audit.
     */
    public function assignLateOrder(
        DistributionWindow $target,
        string $orderId,
        ?int $actorId,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): DistributionWindowOrder {
        $this->assertManualAllowed($target);
        $now ??= CarbonImmutable::now();

        $order = DB::table('orders')
            ->where('id', $orderId)
            ->select('id', 'company_id', 'logistics_city_id', 'assigned_warehouse_id')
            ->first();

        if ($order === null) {
            throw new DistributionException('Order not found.');
        }

        if ((string) $order->company_id !== $target->company_id) {
            // Cross-company assignment is not a permission problem to be reported
            // — it is outside the tenant boundary and must read as not existing.
            throw new DistributionException('Order not found.');
        }

        $zoneId = app(OrderZoneResolver::class)->resolve(
            $order->logistics_city_id === null ? null : (int) $order->logistics_city_id,
        );

        $slotId = $zoneId === null
            ? null
            : ($order->assigned_warehouse_id === null
                ? null
                : ($this->collection->slotMapForWindow(
                    $target->id,
                    (string) $order->assigned_warehouse_id,
                )[$zoneId] ?? null));

        $existing = DistributionWindowOrder::query()->where('order_id', $orderId)->first();

        if ($existing === null) {
            $created = $this->collection->attach(
                companyId: $target->company_id,
                windowId: $target->id,
                orderId: $orderId,
                zoneId: $zoneId,
                slotId: $slotId,
                source: DistributionAssignmentSource::ManualLate,
                actorId: $actorId,
                now: $now,
                previousWindowId: null,
                reason: $reason,
            );

            if ($created === null) {
                throw new DistributionException('Order assignment could not be created.');
            }

            LateOrderManuallyAssigned::dispatch($created, null);

            return $created;
        }

        $previousWindowId = $existing->distribution_window_id;

        if ($previousWindowId === $target->id) {
            return $existing;
        }

        $existing->forceFill([
            'distribution_window_id' => $target->id,
            'distribution_zone_id' => $zoneId,
            'virtual_slot_id' => $slotId,
            'assignment_source' => DistributionAssignmentSource::ManualLate->value,
            'assigned_by' => $actorId,
            'assigned_at' => $now,
            'previous_window_id' => $previousWindowId,
            'assignment_reason' => $reason,
        ])->save();

        LateOrderManuallyAssigned::dispatch($existing, $previousWindowId);

        return $existing;
    }

    /**
     * A Closed Window is the only state that refuses manual work.
     *
     * CutoffReached deliberately passes — see DistributionWindowStatus.
     */
    private function assertManualAllowed(DistributionWindow $window): void
    {
        if (! $window->status->acceptsManualAssignment()) {
            throw new DistributionException(
                "Distribution Window is {$window->status->value}; manual assignment is no longer accepted.",
            );
        }
    }
}
