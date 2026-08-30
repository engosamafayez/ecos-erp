<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Drivers\Domain\Exceptions\FleetAssignmentException;
use Modules\Operations\Loading\Application\Actions\AssignVehicleToSessionAction;
use Modules\Operations\Loading\Application\Actions\CreateLoadingSessionAction;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use RuntimeException;

/**
 * Group → Trip → Vehicle/Driver → Loading.
 *
 * THE ECOS CAPACITY CONTRACT
 * --------------------------
 * Capacity is an ORDER COUNT and nothing else. Weight, volume, refrigeration
 * and product dimensions are NOT business constraints in this platform. This
 * service supplies none of them, validates none of them, and passes null rather
 * than 0 where the legacy Loading columns still exist — because a 0 is a real
 * measurement meaning "carries nothing", while the truth is "not measured".
 *
 * WHAT THIS SERVICE OWNS — AND WHAT IT DELIBERATELY DOES NOT
 * ----------------------------------------------------------
 * It owns NO quantities, NO pairing and NO capacity rule. It is a bridge:
 *
 *   • Group provenance comes from `distribution_trips.virtual_slot_id`, so
 *     Loading reaches its Group through the canonical execution chain instead of
 *     storing a group id of its own.
 *   • The Vehicle and Driver come from the canonical VP-1 pairing ledger, read
 *     through the Trip. No Loading Vehicle, no Loading Driver, no Group Vehicle
 *     and no Group Driver is created.
 *   • Required comes from the canonical Group projection, Prepared from the
 *     approved Group+Product Prepared contract, and Remaining stays derived.
 *     None of the three is recomputed here.
 *
 * A Loading Session remains what the existing contract makes it — a
 * WAREHOUSE + OPERATIONAL DATE container, not a per-Trip or per-Group object.
 * Several Trips out of one warehouse on one day share one session, each as its
 * own vehicle assignment. That shape is preserved rather than redesigned.
 */
class GroupLoadingContextService
{
    public function __construct(
        private readonly CreateLoadingSessionAction $createSession,
        private readonly AssignVehicleToSessionAction $assignVehicle,
        private readonly DistributionAggregationService $aggregation,
    ) {}

    /**
     * Open (or re-open) the Loading execution context for a Group's Trip.
     *
     * Idempotent on BOTH levels, by locating rather than creating:
     *   • one session per (company, warehouse, operational date)
     *   • one vehicle assignment per Trip
     *
     * Retrying therefore returns the same two rows rather than a second session
     * and a second assignment. No idempotency framework is introduced — this is
     * the same lock-then-re-read shape the Distribution services already use.
     *
     * @return array{session: LoadingSession, assignment: VehicleAssignment, trip: Trip}
     */
    public function open(VirtualCapacitySlot $group, Trip $trip, string $actorId): array
    {
        $this->assertConsistent($group, $trip);
        $this->assertManifestStillBelongsToGroup($group, $trip);
        $this->assertManifestIsComplete($group);

        $warehouseId = $trip->operationalWarehouseId();

        if ($warehouseId === null) {
            throw FleetAssignmentException::notInGroupCompany('trip warehouse');
        }

        $pairing = $trip->driverVehicleAssignment;

        if ($pairing === null) {
            // Loading cannot open before the Group has a vehicle and driver —
            // that is the approved order of the workflow, not a new rule.
            throw new RuntimeException(
                'This group has no vehicle and driver yet. Assign them before opening Loading.',
            );
        }

        return DB::transaction(function () use ($group, $trip, $warehouseId, $pairing, $actorId): array {
            $session = $this->resolveSession($group->company_id, $warehouseId, $actorId);
            $assignment = $this->resolveAssignment($session, $trip, $pairing, $actorId);

            return ['session' => $session, 'assignment' => $assignment, 'trip' => $trip];
        });
    }

    /**
     * TASK-1-C §8/§11 — the readiness checklist, as a READ.
     *
     * ┌─ WHY THIS RUNS THE GUARDS INSTEAD OF RE-DESCRIBING THEM ─────────────────┐
     * │ Every check here is the SAME method `open()` calls, invoked and caught.    │
     * │ A screen that computed readiness its own way would eventually disagree     │
     * │ with the thing that actually refuses — showing READY on a Trip that then   │
     * │ fails, or BLOCKED on one that would have opened. Running the real guards    │
     * │ makes that impossible by construction.                                     │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * IT WRITES NOTHING. Each guard is a read plus a throw; catching the throw yields
     * the answer without touching a row, so the panel can be polled freely.
     *
     * The `key` values are stable identifiers for i18n, never class or column names —
     * the operator sees a sentence, not `driverVehicleAssignment`.
     *
     * @return array{ready: bool, checks: list<array{key: string, ok: bool}>, reason: string|null}
     */
    public function readiness(VirtualCapacitySlot $group, Trip $trip): array
    {
        $checks = [];
        $reason = null;

        $run = function (string $key, callable $guard) use (&$checks, &$reason): void {
            try {
                $guard();
                $checks[] = ['key' => $key, 'ok' => true];
            } catch (\Throwable $e) {
                $checks[] = ['key' => $key, 'ok' => false];

                // The FIRST failure is the one reported: it is the one the operator has
                // to deal with, and a wall of consequential errors obscures it.
                $reason ??= $e->getMessage();
            }
        };

        $run('trip_belongs_to_group', fn () => $this->assertConsistent($group, $trip));
        $run('manifest_membership', fn () => $this->assertManifestStillBelongsToGroup($group, $trip));
        $run('manifest_complete', fn () => $this->assertManifestIsComplete($group));

        $run('warehouse_resolved', function () use ($trip): void {
            if ($trip->operationalWarehouseId() === null) {
                throw FleetAssignmentException::notInGroupCompany('trip warehouse');
            }
        });

        // Vehicle and driver are ONE pairing row in this architecture, but the operator
        // reads them as two facts, so they are reported as two — from the same row, not
        // from a second lookup that could disagree with it.
        $pairing = $trip->driverVehicleAssignment;

        $run('vehicle_assigned', function () use ($pairing): void {
            if ($pairing === null) {
                throw new RuntimeException(
                    'This group has no vehicle and driver yet. Assign them before opening Loading.',
                );
            }
        });

        $run('driver_assigned', function () use ($pairing): void {
            if ($pairing === null) {
                throw new RuntimeException(
                    'This group has no vehicle and driver yet. Assign them before opening Loading.',
                );
            }
        });

        $ready = true;

        foreach ($checks as $check) {
            if ($check['ok'] === false) {
                $ready = false;
                break;
            }
        }

        return ['ready' => $ready, 'checks' => $checks, 'reason' => $reason];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * One session per company + warehouse + operational date.
     *
     * Located under a lock before being created, so two operators opening
     * Loading for two Trips in the same warehouse on the same day converge on
     * one session instead of racing into two.
     */
    private function resolveSession(string $companyId, string $warehouseId, string $actorId): LoadingSession
    {
        $today = now()->toDateString();

        $existing = LoadingSession::query()
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->whereDate('operational_date', $today)
            ->whereNull('cancelled_at')
            ->lockForUpdate()
            ->orderBy('created_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->createSession->execute(
            companyId: $companyId,
            warehouseId: $warehouseId,
            operationalDate: $today,
            actorId: $actorId,
        );
    }

    /**
     * One vehicle assignment per Trip.
     *
     * The vehicle and driver identifiers are taken from the CANONICAL pairing
     * ledger, not from the client and not from a second registry. Registration
     * and type are snapshotted from the canonical Vehicle so the Loading record
     * describes the real vehicle rather than whatever a caller asserted.
     */
    private function resolveAssignment(
        LoadingSession $session,
        Trip $trip,
        $pairing,
        string $actorId,
    ): VehicleAssignment {
        $existing = VehicleAssignment::query()
            ->where('trip_id', $trip->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $vehicle = $pairing->vehicle;

        return $this->assignVehicle->execute(
            session: $session,
            // The CROSS-MODULE uuid (VP-1 / D1-C), never the internal bigint.
            vehicleId: (string) ($vehicle->uuid ?? $vehicle->id),
            vehicleRegistration: (string) ($vehicle->plate_number ?? '—'),
            // `type` is the fleet registry's own column (there is no
            // `vehicle_type` on logistics_vehicles — verified against the live
            // schema rather than assumed from the Loading-side column name), and
            // it is cast to the VehicleType BACKED ENUM by the model. Casting an
            // enum object to string is a fatal, so the backing value is taken
            // explicitly rather than relying on coercion.
            vehicleType: $this->vehicleTypeValue($vehicle->type),
            actorId: $actorId,
            // Weight, volume and refrigeration are NOT supplied. They are not
            // ECOS business constraints, and null is passed rather than 0 so the
            // record says "not measured" instead of "carries nothing".
            capacityWeightKg: null,
            capacityVolumeM3: null,
            refrigerated: false,
            tripId: $trip->id,
        );
    }

    /**
     * The string form of the fleet's vehicle type.
     *
     * Accepts the enum the model casts to, a raw string from an unhydrated read,
     * or null — the Loading snapshot column is a plain varchar and must receive
     * a scalar in every one of those cases.
     */
    private function vehicleTypeValue(mixed $type): string
    {
        if ($type instanceof \BackedEnum) {
            return (string) $type->value;
        }

        if (is_string($type) && $type !== '') {
            return $type;
        }

        return 'company_vehicle';
    }

    /**
     * PART 14 — company and warehouse must agree across the whole chain.
     *
     * Checked on the resolved objects rather than on request input, so it cannot
     * be bypassed by sending ids that individually pass a filter applied at
     * different moments.
     */
    /**
     * Refuse to open Loading while the Trip carries an Order that has left its Group.
     *
     * ┌─ WHY THE ADD-TIME GUARD IS NOT ENOUGH ───────────────────────────────────┐
     * │ `TripService::assignOrder()` already refuses an Order that is not a       │
     * │ member of the Trip's Group. That guard runs on ADD ONLY. The manifest is  │
     * │ a snapshot, so an Order can leave the Group afterwards — a zone detach,   │
     * │ a per-order move, or a geography correction that re-resolves its zone —   │
     * │ and the manifest row simply stays. Live ORD-00007 is exactly that: it     │
     * │ entered TRP-001 through Finalize (`assignment_type = auto`) and later     │
     * │ had its city corrected Maadi -> Obour City, which re-resolved its zone    │
     * │ into a zone no Group holds.                                              │
     * │                                                                          │
     * │ Nothing then stopped that row travelling into Loading. This closes it at  │
     * │ the Loading boundary, which is the last point before physical work.       │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * IT FAILS CLOSED. Any offending row refuses the whole open, because a partially
     * valid manifest is not a safe thing to start loading against.
     *
     * NO NEW SOURCE OF TRUTH AND NO NEW PREDICATE. The membership test is the SAME
     * one `assignOrder` uses — `distribution_window_orders.virtual_slot_id` equals the
     * Trip's `virtual_slot_id` — so add-time and load-time can never disagree about
     * what "belongs to this Group" means. No eligibility engine is introduced: this
     * asks about Group membership, not about order status.
     *
     * IT REPAIRS NOTHING. The offending row is reported, never removed. Clearing it is
     * an operator decision through the existing DELETE /trips/{trip}/orders/{order}.
     */
    private function assertManifestStillBelongsToGroup(VirtualCapacitySlot $group, Trip $trip): void
    {
        $strays = DB::table('distribution_trip_orders as tor')
            ->join('orders as o', 'o.id', '=', 'tor.order_id')
            ->leftJoin('distribution_window_orders as dwo', function ($join) use ($group): void {
                $join->on('dwo.order_id', '=', 'tor.order_id')
                    ->where('dwo.virtual_slot_id', '=', $group->id);
            })
            ->where('tor.trip_id', $trip->id)
            ->whereNull('dwo.id')
            ->orderBy('o.order_number')
            ->pluck('o.order_number')
            ->all();

        if ($strays === []) {
            return;
        }

        throw new DistributionException(sprintf(
            'This trip carries %d order(s) that are no longer members of group %s: %s. '
            .'Remove them from the trip before opening loading.',
            count($strays),
            $group->code,
            implode(', ', $strays),
        ));
    }

    /**
     * TASK-1-C §4 — the Trips must represent the WHOLE accepted Group.
     *
     * ┌─ THE OTHER DIRECTION OF THE SAME INVARIANT ──────────────────────────────┐
     * │ `assertManifestStillBelongsToGroup()` catches an Order in the Trip that   │
     * │ left the Group. This catches the inverse: an accepted Group Order that    │
     * │ never reached a Trip. Both are integrity failures and neither is visible   │
     * │ from the other side, so Loading needs both before it commits anything.    │
     * │                                                                          │
     * │ A Group with 10 accepted Orders whose Trips carry 9 must not load: the    │
     * │ tenth would silently stay behind, and nothing downstream would notice.    │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * THE ACCEPTED SET IS FINALIZE'S OWN. It comes from the identical
     * `aggregation->orders(window, null, group, warehouse)` call that
     * GroupFinalizationService used to build the manifests, so this compares the plan
     * against itself rather than inventing a second definition of membership.
     *
     * ACROSS ALL THE GROUP'S TRIPS, not just the one being opened — a Group that split
     * over Trip capacity is complete only when the Trips are taken together.
     *
     * IT REPAIRS NOTHING. The missing Orders are named; adding them is an operator
     * decision through the existing workflow.
     */
    private function assertManifestIsComplete(VirtualCapacitySlot $group): void
    {
        // THE CONTRACT BEGINS AT FINALIZE. §4 states it as "after Finalize, every accepted
        // Group Order must belong to Trip/Trips" — so a Group whose Trips were never
        // finalized has no manifest contract to be incomplete against.
        //
        // This scoping is not a softening. Applying it earlier would block the existing,
        // legitimate flow where a Trip is opened by vehicle assignment before Finalize,
        // and refusing that is a redesign of the Loading contract, not a guard on it.
        $finalized = DB::table('distribution_trips')
            ->where('virtual_slot_id', $group->id)
            ->whereNotNull('finalized_at')
            ->exists();

        if (! $finalized) {
            return;
        }

        $accepted = $this->aggregation->orders(
            $group->distribution_window_id,
            null,
            $group->id,
            ['warehouse_id' => $group->warehouse_id],
        );

        if ($accepted === []) {
            return;
        }

        $manifested = DB::table('distribution_trip_orders as tor')
            ->join('distribution_trips as t', 't.id', '=', 'tor.trip_id')
            ->where('t.virtual_slot_id', $group->id)
            ->pluck('tor.order_id')
            ->map(static fn ($id): string => (string) $id)
            ->flip();

        $missing = [];

        foreach ($accepted as $order) {
            $orderId = (string) ($order['order_id'] ?? '');

            if ($orderId !== '' && ! $manifested->has($orderId)) {
                $missing[] = (string) ($order['order_number'] ?? $orderId);
            }
        }

        if ($missing === []) {
            return;
        }

        sort($missing);

        throw new DistributionException(sprintf(
            'Group %s has %d accepted order(s) that no trip carries: %s. '
            .'Resolve the group before opening loading.',
            $group->code,
            count($missing),
            implode(', ', $missing),
        ));
    }

    /*
     * TASK-1-C §5 — DUPLICATE PROTECTION IS THE DATABASE'S, and no application guard was
     * added because one could never execute.
     *
     * `distribution_trip_orders_order_unique` is a UNIQUE index on `order_id` alone, so an
     * Order can appear in at most one Trip anywhere, ever — not merely once per Group. A
     * duplicate manifest row cannot be inserted; the attempt raises a constraint violation
     * before any service-layer guard would see it.
     *
     * §5 says to use existing database constraints where available, and this is one. A
     * check here would be unreachable code that future readers would assume was doing
     * something. The invariant is proven where it actually lives, by
     * `test_the_manifest_forbids_the_same_order_twice`.
     */

    private function assertConsistent(VirtualCapacitySlot $group, Trip $trip): void
    {
        if ((string) $trip->virtual_slot_id !== (string) $group->id) {
            throw FleetAssignmentException::notInGroupCompany('trip');
        }

        if ($trip->company_id !== null
            && $group->company_id !== null
            && (string) $trip->company_id !== (string) $group->company_id) {
            throw FleetAssignmentException::notInGroupCompany('trip company');
        }
    }
}
