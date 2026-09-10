<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Drivers\Domain\Exceptions\FleetAssignmentException;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Drivers\Domain\Models\DriverVehicleAssignment;
use Modules\Logistics\Drivers\Domain\Services\DriverVehicleAssignmentService;
use Modules\Logistics\Drivers\Domain\Services\FleetIdentityResolver;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\Operations\Loading\Domain\Enums\DriverAssignmentStatus;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use RuntimeException;

/**
 * VP-1 / D3 + D4 — assigning a Vehicle and Driver to a Distribution Group.
 *
 * D3-D: this service OWNS NO PAIRING. `logistics_driver_vehicle_assignments` is
 * the single authority, and the chain it writes through is the one Distribution
 * already documented in the distribution_trips migration:
 *
 *     Group → Trip (virtual_slot_id) → driver_vehicle_assignment_id → ledger
 *
 * That migration states the rule verbatim — "There is deliberately no driver_id,
 * no vehicle_id and no pairing logic: the assignment ledger already guarantees
 * one active driver per vehicle and one active vehicle per driver" — so this
 * service creates no second pairing table, no second pairing column, and no
 * second uniqueness rule. It calls DriverVehicleAssignmentService::assign() and
 * then attaches the resulting ledger row to the Group's Trip.
 *
 * D4-C: capacity is an ORDER COUNT on both sides. The Group's live order count
 * must fit the vehicle's `capacity_orders`. There is no weight, no volume, no
 * stop count and no product-dimension arithmetic here — those columns exist in
 * three places in the schema and are enforced in none, and this service does not
 * become the first to enforce them.
 *
 * SECURITY (S-1…S-6): every reference that arrives from a client is resolved
 * through FleetIdentityResolver, which goes through the Eloquent models so their
 * tenant global scopes apply. A raw `exists:` rule would not — it runs on the
 * query builder and bypasses the scope — so it is never the guard here.
 */
class GroupVehicleAssignmentService
{
    public function __construct(
        private readonly FleetIdentityResolver $fleet,
        private readonly DriverVehicleAssignmentService $ledger,
        private readonly TripService $trips,
        private readonly DistributionAggregationService $aggregation,
    ) {}

    /**
     * Assign a vehicle (and its driver) to a Group.
     *
     * @param  string  $vehicleReference  uuid (Operations contract) or bigint id
     * @param  string  $driverReference  uuid (Operations contract) or bigint id
     * @return array{trip: Trip, assignment_id: int, group_orders: int, vehicle_capacity: int, remaining_capacity: int}
     */
    public function assign(
        VirtualCapacitySlot $group,
        string $vehicleReference,
        string $driverReference,
        ?int $actorId = null,
    ): array {
        // S-1/S-2/S-4/S-5 — canonical, tenant-scoped resolution. Resolution
        // happens BEFORE the transaction so a foreign reference costs no lock.
        $vehicle = $this->fleet->vehicle($vehicleReference);
        $driver = $this->fleet->driver($driverReference);

        // S-3 — the pair may not span two companies.
        $this->fleet->assertSameCompany($vehicle, $driver);

        // S-6 — and neither may belong to a company other than the Group's. The
        // Group is the tenant anchor for this operation: it is the object the
        // operator opened, and it is already company-scoped upstream.
        $this->assertBelongsToGroupCompany($group, $vehicle->company_id, 'vehicle');
        $this->assertBelongsToGroupCompany($group, $driver->company_id, 'driver');

        // TASK-ECOS-DISTRIBUTION-GROUP-DETAILS-CANONICAL-RECONCILIATION-009 —
        // the server-side mirror of groupFleetOptions()'s canBeDispatched()
        // exclusion. The selector already hides a non-dispatchable vehicle, so
        // this is the fail-safe for a stale drawer, not the primary UX guard —
        // needs no lock: unlike the pairing check below, no concurrent writer
        // contends on this vehicle's OWN status row for this decision.
        if (! $vehicle->canBeDispatched()) {
            throw FleetAssignmentException::vehicleNotDispatchable(
                $vehicle->plate_number ?? (string) $vehicle->id,
            );
        }

        // TASK-ECOS-DISTRIBUTION-GROUP-DETAILS-CANONICAL-RECONCILIATION-009-R1
        // — the server-side mirror of groupFleetOptions()'s Loading-busy
        // exclusion (see loadingBusyVehicleUuids() below). Same reasoning as
        // the check above: the selector already hides this vehicle, so this
        // is the stale-drawer fail-safe, not the primary UX guard.
        if ($this->loadingBusyVehicleUuids([$vehicle->uuid]) !== []) {
            throw FleetAssignmentException::vehicleBusyInLoading(
                $vehicle->plate_number ?? (string) $vehicle->id,
            );
        }

        // TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §C — the driver-side
        // counterpart to the vehicle check above. Was missing entirely: a Driver could
        // be paired to a new Group while already committed to an active Operations\
        // Loading driver assignment on a different Vehicle. Read directly from
        // DriverAssignmentStatus's own terminal states, not derived through a linked
        // Trip: unlike vehicle_assignments, nothing documents driver_assignments as
        // capable of getting stuck short of its own terminal states.
        if ($this->loadingBusyDriverUuids([$driver->uuid]) !== []) {
            throw FleetAssignmentException::driverBusyInLoading(
                $driver->full_name ?? (string) $driver->id,
            );
        }

        return DB::transaction(function () use ($group, $vehicle, $driver, $actorId) {
            // Lock the Group so the order count read below cannot move under the
            // capacity check — the same lock-then-measure shape GroupFinalization
            // and GroupPreparation already use.
            $locked = VirtualCapacitySlot::query()
                ->whereKey($group->id)
                ->lockForUpdate()
                ->firstOrFail();

            // D4-C — measured live, inside the lock, from the canonical read
            // model. The frontend's number is never trusted, and this is not a
            // second capacity calculation: it is the same aggregation the Groups
            // board renders.
            $groupOrders = $this->groupOrderCount($locked);
            $capacity = (int) $vehicle->capacity_orders;

            if ($groupOrders > $capacity) {
                throw FleetAssignmentException::groupExceedsVehicleCapacity(
                    $groupOrders,
                    $vehicle->plate_number ?? (string) $vehicle->id,
                    $capacity,
                );
            }

            // TASK-DISTRIBUTION-VEHICLE-DRIVER-PAIRING-FILTER-FIX-001 — REUSE an
            // existing active pairing instead of trying to mint a duplicate.
            //
            // `DriverVehicleAssignmentService::assign()` deliberately refuses to
            // re-assign a driver to the vehicle they already hold
            // (`alreadyAssignedToSameVehicle`). That guard is correct for the Fleet
            // screens, where the operator is CHANGING a pairing — but here the
            // operator is choosing an already-paired driver+vehicle to run a Group,
            // so hitting it turned the only valid selection into a 422 and made the
            // Group unassignable. Reusing the live pairing makes this operation
            // idempotent: running it twice attaches the same ledger row to the Trip.
            //
            // When no pairing exists the behaviour is UNCHANGED — the ledger still
            // creates it, so the certified "assignment writes the canonical ledger"
            // contract and the Fleet screens' own semantics are untouched. This
            // service still owns no pairing and still writes none itself.
            $assignment = $this->activePairing($driver, $vehicle)
                ?? $this->ledger->assign(
                    $driver,
                    $vehicle,
                    $actorId === null ? null : (string) $actorId,
                );

            // TASK-DISTRIBUTION-DRIVER-AVAILABILITY-FIX-001 — the authoritative
            // availability guard. The selector hiding an engaged pairing is only UX;
            // THIS is where a double-booking is actually refused, so it holds even
            // when the drawer is bypassed.
            //
            // CONCURRENCY: the outer transaction locks only THIS Group, so two
            // requests claiming one pairing for two different Groups would never
            // contend on a shared row and could both pass a plain read. The pairing
            // is that shared row, so we lock it here — the second request blocks
            // until the first commits, then the locking read below sees the trip the
            // first one wrote and rejects. No schema change, no new index: one row
            // lock on a row that already exists, which is the minimal primitive the
            // existing Group-only lock cannot provide.
            DriverVehicleAssignment::query()
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->trips->assignmentsEngagedElsewhere([$assignment->id], $locked->id, lock: true) !== []) {
                throw FleetAssignmentException::pairingEngagedElsewhere(
                    $vehicle->plate_number ?? (string) $vehicle->id,
                );
            }

            // The Group reaches the ledger through its Trip, never directly.
            $trip = $this->resolveTrip($locked);
            $trip = $this->trips->assignDriverVehicle($trip, $assignment->id);

            return [
                'trip' => $trip,
                'assignment_id' => $assignment->id,
                'group_orders' => $groupOrders,
                'vehicle_capacity' => $capacity,
                'remaining_capacity' => $capacity - $groupOrders,
            ];
        });
    }

    /**
     * The capacity preview the assignment drawer renders.
     *
     * Server-computed on purpose: the UI must not do this arithmetic, or the
     * number it shows could disagree with the number that decides the write.
     *
     * @return array{group_orders: int, vehicle_capacity: int, remaining_capacity: int, fits: bool}
     */
    public function preview(VirtualCapacitySlot $group, string $vehicleReference): array
    {
        $vehicle = $this->fleet->vehicle($vehicleReference);
        $this->assertBelongsToGroupCompany($group, $vehicle->company_id, 'vehicle');

        $groupOrders = $this->groupOrderCount($group);
        $capacity = (int) $vehicle->capacity_orders;

        return [
            'group_orders' => $groupOrders,
            'vehicle_capacity' => $capacity,
            'remaining_capacity' => $capacity - $groupOrders,
            'fits' => $groupOrders <= $capacity,
        ];
    }

    /**
     * Vehicle uuids (from the given candidate set) currently committed to
     * active Operations\Loading work — TASK-ECOS-DISTRIBUTION-GROUP-DETAILS-
     * CANONICAL-RECONCILIATION-009-R1.
     *
     * DELIBERATELY NOT a plain `VehicleAssignmentStatus::isActive()` filter.
     * Source tracing proved that trap: a Group/Trip-originated
     * `vehicle_assignments` row can never advance past `LoadingComplete` —
     * `DispatchVehicleAction` (the only path that would move it to
     * Dispatched/Returning/Reconciling/Reconciled) requires an
     * Operations\Loading `DriverAssignment` row that
     * `GroupLoadingContextService` never creates. A naive "any non-terminal
     * `vehicle_assignments` row = busy" filter would therefore mark every
     * vehicle that has EVER completed one Group/Trip delivery as permanently
     * unavailable, forever — confirmed live on DEV, where a vehicle already
     * carries two such `loading_complete` rows from separate dates weeks
     * apart, neither ever reconciled.
     *
     * So "still busy" is scoped by the linked TRIP's own lifecycle instead —
     * the record that genuinely keeps progressing for this flow, and the
     * SAME classification (`TripStatus::nonTerminalValues()`) the pairing
     * -engagement check already trusts elsewhere. Once a Vehicle's Trip
     * closes or is cancelled, the Vehicle is free again regardless of what
     * `vehicle_assignments.status` itself is frozen at. A row with no linked
     * Trip at all (`trip_id` null — a standalone, non-Distribution Loading
     * assignment, if one exists) falls back to its own status, since there is
     * no Trip to defer to and that status DOES keep progressing outside the
     * Group/Trip bridge.
     *
     * The known custody defect (`loading_complete` with zero `LoadingTask`/
     * `VehicleInventoryItem` rows underneath, parent `LoadingSession` stuck
     * at `draft`) is NOT specially handled here, by design: this reads only
     * `vehicle_assignments.status` and its Trip's `status` — never inventory
     * presence — so a defect-affected row is judged exactly like a normal
     * one, using the existing authoritative commitment record, without
     * pretending inventory custody was materialized either way.
     *
     * Explicitly `Cancelled` rows are excluded regardless of their Trip's own
     * status — an operator who cancelled this specific assignment meant it,
     * even if the Trip itself has not separately closed.
     *
     * ONE bounded query regardless of fleet size — no per-vehicle Loading
     * lookup.
     *
     * @param  list<string>  $vehicleUuids
     * @return list<string>
     */
    public function loadingBusyVehicleUuids(array $vehicleUuids): array
    {
        if ($vehicleUuids === []) {
            return [];
        }

        return DB::table('vehicle_assignments as va')
            ->leftJoin('distribution_trips as dt', 'dt.id', '=', 'va.trip_id')
            ->whereIn('va.vehicle_id', $vehicleUuids)
            ->whereNotIn('va.status', [
                VehicleAssignmentStatus::Reconciled->value,
                VehicleAssignmentStatus::Cancelled->value,
            ])
            ->where(function ($q): void {
                $q->whereNull('va.trip_id')
                    ->orWhereIn('dt.status', TripStatus::nonTerminalValues());
            })
            ->distinct()
            ->pluck('va.vehicle_id')
            ->all();
    }

    /**
     * Driver uuids (from the given candidate set) currently committed to an active
     * Operations\Loading `driver_assignments` row — the driver-side counterpart to
     * `loadingBusyVehicleUuids()` above (TASK-...-FINAL-022 §C).
     *
     * Reads `DriverAssignmentStatus` directly rather than deferring to a linked
     * Trip: that enum already defines its own terminal set (Reconciled/Cancelled/
     * Reassigned via `isTerminal()`) and nothing in this codebase documents a
     * driver_assignments row getting stuck short of one of those, unlike the
     * documented `vehicle_assignments`-stuck-at-LoadingComplete defect that forced
     * `loadingBusyVehicleUuids()` to defer to the Trip instead.
     *
     * @param  list<string>  $driverUuids
     * @return list<string>
     */
    public function loadingBusyDriverUuids(array $driverUuids): array
    {
        if ($driverUuids === []) {
            return [];
        }

        return DB::table('driver_assignments')
            ->whereIn('driver_id', $driverUuids)
            ->whereNotIn('status', [
                DriverAssignmentStatus::Reconciled->value,
                DriverAssignmentStatus::Cancelled->value,
                DriverAssignmentStatus::Reassigned->value,
            ])
            ->distinct()
            ->pluck('driver_id')
            ->all();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * The LIVE pairing for exactly this driver and vehicle, or null.
     *
     * Read straight from the canonical ledger — this service still creates no
     * pairing and owns no second uniqueness rule. `active_flag` is 1 while live
     * and NULL once released, so a released historical row never matches.
     */
    private function activePairing(Driver $driver, Vehicle $vehicle): ?DriverVehicleAssignment
    {
        return DriverVehicleAssignment::query()
            ->where('driver_id', $driver->id)
            ->where('vehicle_id', $vehicle->id)
            ->whereNotNull('active_flag')
            ->first();
    }

    /**
     * The Group's live order count, from the CANONICAL aggregation.
     *
     * Reusing `slotSummaries()` rather than counting rows here is deliberate: it
     * is the same source the Groups board and Finalize already read, so the three
     * can never disagree about how many orders a group holds.
     */
    private function groupOrderCount(VirtualCapacitySlot $group): int
    {
        $summaries = $this->aggregation->slotSummaries($group->distribution_window_id);

        foreach ($summaries as $summary) {
            if (($summary['slot_id'] ?? null) === $group->id) {
                return (int) ($summary['orders_count'] ?? 0);
            }
        }

        // A group the canonical read model does not report holds no orders. This
        // returns 0 rather than throwing, because an empty group is legitimate —
        // and 0 always fits, so it cannot smuggle an over-capacity assignment past
        // the check above.
        return 0;
    }

    /**
     * The Group's Trip, created on demand if the Group has not been finalized.
     *
     * A Group with no Trip is still assignable — assigning a vehicle is how an
     * operator commits to running it — so the Trip is materialised here rather
     * than forcing Finalize first.
     */
    private function resolveTrip(VirtualCapacitySlot $group): Trip
    {
        $existing = Trip::query()
            ->where('virtual_slot_id', $group->id)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // refresh() is load-bearing, not cosmetic: Trip::create() returns a model
        // whose DB-default `capacity` is still null in memory, and a null capacity
        // makes remainingCapacity() collapse to 0.
        // `name` is NOT NULL with no default — the same three columns
        // GroupFinalizationService::openTrip() supplies. capacity / type / status
        // keep their schema defaults deliberately.
        return $this->trips->create([
            'company_id' => $group->company_id,
            'virtual_slot_id' => $group->id,
            'name' => $group->code,
            'trip_number' => $this->trips->nextTripNumber($group->company_id),
        ])->refresh();
    }

    /**
     * A null owner is the shared/unowned pool, which both fleet global scopes
     * already admit, so it is allowed here too. A DIFFERENT owner is not.
     */
    private function assertBelongsToGroupCompany(
        VirtualCapacitySlot $group,
        ?string $entityCompanyId,
        string $what,
    ): void {
        if ($entityCompanyId === null || $group->company_id === null) {
            return;
        }

        if ($entityCompanyId !== $group->company_id) {
            throw new RuntimeException(sprintf(
                'The selected %s does not belong to this group\'s company.',
                $what,
            ));
        }
    }
}
