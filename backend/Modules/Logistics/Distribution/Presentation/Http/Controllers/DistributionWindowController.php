<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DistributionAggregationService;
use Modules\Logistics\Distribution\Domain\Services\DistributionCollectionService;
use Modules\Logistics\Distribution\Domain\Services\DistributionWindowService;
use Modules\Logistics\Distribution\Domain\Services\GroupCapacityGuard;
use Modules\Logistics\Distribution\Domain\Services\GroupFinalizationService;
use Modules\Logistics\Distribution\Domain\Services\GroupLoadingContextService;
use Modules\Logistics\Distribution\Domain\Services\GroupPreparationService;
use Modules\Logistics\Distribution\Domain\Services\GroupVehicleAssignmentService;
use Modules\Logistics\Distribution\Domain\Services\ManualAssignmentService;
use Modules\Logistics\Distribution\Domain\Services\RedistributionSuggestionService;
use Modules\Logistics\Distribution\Domain\Services\TripService;
use Modules\Logistics\Drivers\Domain\Exceptions\FleetAssignmentException;
use Modules\Logistics\Drivers\Domain\Exceptions\VehicleAssignmentException;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Drivers\Domain\Models\DriverVehicleAssignment;
use Modules\Logistics\Geography\Domain\Services\OrderCityBinder;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use RuntimeException;

/**
 * Transport adapter for the Distribution Workspace.
 *
 * Holds no business logic: every method resolves the tenant, loads a
 * company-scoped aggregate, and delegates. Authorisation is the existing
 * `permission:logistics.distribution.*` middleware declared on the routes.
 *
 * TENANT SCOPING FAILS CLOSED. An actor with no company sees nothing and can
 * change nothing. Several existing Logistics controllers use
 * `->when($companyId, ...)`, which silently drops the filter when the company is
 * null and therefore returns every tenant's rows; that pattern is deliberately
 * not copied here.
 */
final class DistributionWindowController extends Controller
{
    /**
     * Window-resolution outcome — a TRANSPORT discriminator for the read payload.
     *
     * These are not business lifecycle states and they are not persisted anywhere.
     * `DistributionWindowStatus` remains the only window lifecycle, unchanged; this
     * says whether the read could identify a window at all (TASK-1-A §1).
     */
    private const RESOLUTION_OK = 'resolved';

    private const RESOLUTION_NONE = 'no_planning_window';

    /**
     * The only reason a read can now fail to resolve: this tenant has no existing
     * Distribution Window at all.
     *
     * H1 = Option B removed the other two. A missing Preparation Wave no longer blocks a
     * read (the wave selects a cycle, it does not gate Distribution), and "no warehouse
     * selected" is a CLIENT-side context question the server cannot answer — the endpoint
     * still serves the company-wide read it has always served.
     */
    private const REASON_NO_WINDOW = 'no_window_available';

    /**
     * Group ↔ Trip lifecycle state — a TRANSPORT discriminator, not a stored status.
     *
     * `distribution_virtual_slots` has no status column and gains none: these are
     * derived per request from membership, capacity and the Trip list. Nothing is
     * persisted, so no second state machine is introduced.
     */
    private const STATE_RESOLVED = 'resolved';

    /** Members exceed the Group's PLANNING capacity — Finalize will refuse. */
    private const STATE_CAPACITY_DECISION = 'capacity_decision_required';

    /** Within capacity, no Trip yet. Finalize is the next legitimate step. */
    private const STATE_AWAITING_FINALIZATION = 'awaiting_finalization';

    /** Finalized, but members joined afterwards — the snapshot cannot self-update. */
    private const STATE_ADDED_AFTER_FINALIZATION = 'added_after_finalization';

    /**
     * Why an eligible Order is covered by no Group — a TRANSPORT discriminator.
     *
     * Not a lifecycle state and not persisted: each is derived per request from state
     * that already exists (the Order's warehouse, its Zone, and this Window's
     * zone-to-Group links). No Order status, Group status or new predicate is involved.
     */
    private const BLOCKER_WAREHOUSE = 'warehouse_unassigned';

    /** Warehouse present, but the Order's Zone is attached to no Group in this Window. */
    private const BLOCKER_ZONE = 'zone_not_in_group';

    /** Warehouse and a covered Zone, yet still no Group — not expected, so reported. */
    private const BLOCKER_AWAITING = 'awaiting_group_assignment';

    /**
     * Over the planning capacity, and the operator has explicitly accepted it.
     *
     * Distinct from `capacity_decision_required`: the overflow is still real and still
     * reported, but no decision is outstanding. The planning capacity is unchanged.
     */
    private const STATE_OVERFLOW_APPROVED = 'overflow_approved';

    public function __construct(
        private readonly DistributionWindowService $windows,
        private readonly DistributionCollectionService $collection,
        private readonly DistributionAggregationService $aggregation,
        private readonly RedistributionSuggestionService $redistribution,
        private readonly ManualAssignmentService $manual,
        private readonly OrderCityBinder $cityBinder,
        private readonly GroupPreparationService $groupPreparation,
        private readonly GroupFinalizationService $groupFinalization,
        private readonly GroupVehicleAssignmentService $groupAssignment,
        private readonly GroupLoadingContextService $groupLoading,
        private readonly GroupCapacityGuard $groupCapacity,
        private readonly TripService $trips,
    ) {}

    /** The live Workspace: current Window, Zone rollup and Slot rollup. */
    public function current(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $warehouseId = $this->warehouseId($request);
        $now = CarbonImmutable::now();

        // The WAVE is resolved FIRST, because it is what decides which Window the
        // workspace is planning. Resolving the Window from the calendar instead is
        // the defect this ordering exists to prevent — see
        // DistributionWindowService::resolvePlanningWindow().
        $wave = $this->aggregation->governingPreparationWave($companyId, $warehouseId);

        $window = $this->windows->resolvePlanningWindow(
            $companyId,
            $wave['wave_id'] ?? null,
            $warehouseId,
            $now,
        );

        // ┌─ UNRESOLVED IS A REAL ANSWER — TASK-1-A §1, H1 Option B ─────────────────┐
        // │ Previously a null resolution fell through to today's calendar window,     │
        // │ which the resolver CREATED. The board then rendered zones, groups and     │
        // │ KPIs for a window nobody was planning: empty, and indistinguishable from   │
        // │ a genuinely empty cycle.                                                  │
        // │                                                                          │
        // │ Reaching here now means something much narrower than it used to: this      │
        // │ tenant has NO existing Distribution Window. A missing Preparation Wave no   │
        // │ longer lands here — the wave selects a cycle, it does not gate the read.    │
        // │                                                                          │
        // │ `resolution` is a TRANSPORT discriminator, not a business status: no       │
        // │ lifecycle state was added, no column, no new source of truth. The window   │
        // │ lifecycle enum is untouched.                                              │
        // └──────────────────────────────────────────────────────────────────────────┘
        if ($window === null) {
            return response()->json(['data' => [
                'resolution' => self::RESOLUTION_NONE,
                'resolution_reason' => self::REASON_NO_WINDOW,
                'window' => null,
                'preparation_wave' => $wave,
                'warehouse_id' => $warehouseId,
                // Deliberately empty rather than absent: the shape stays stable so the
                // client renders one unresolved state instead of guessing per field.
                'zones' => [],
                'slots' => [],
            ]]);
        }

        return response()->json(['data' => [
            'resolution' => self::RESOLUTION_OK,
            'resolution_reason' => null,
            'window' => $this->windowPayload($window),
            // The operational cycle the workspace plans against. Distribution runs
            // no schedule of its own: the boundaries are the warehouse's active
            // Preparation Wave, resolved by Preparation's own WaveManager.
            //
            // The operational date is deliberately NOT passed.
            //
            // A preparation cycle SPANS MIDNIGHT — PREP-202608-000003 runs 17:30 on
            // one day to 12:00 the next — so its `planning_date` is yesterday's
            // while it is still the wave that is running. Passing today's calendar
            // date filters out the live wave and blanks the cycle every night after
            // midnight, which is precisely when the warehouse is working.
            //
            // `WaveManager::getActiveWave` documents the omission as the read-side
            // mode: "keeps the legacy any-date behaviour for read-side callers, but
            // now with a deterministic order - newest planning_date first". This is
            // a read-side caller, and the ordering is planning_date, never starts_at.
            'preparation_wave' => $wave,
            'warehouse_id' => $warehouseId,
            'zones' => $this->aggregation->zoneSummaries($window->id, $warehouseId),
            // The wave resolved at the top of this action — not resolved a second time.
            'slots' => $this->aggregation->slotSummaries($window->id, $warehouseId, $wave['wave_id'] ?? null),
        ]]);
    }

    /**
     * Run the collection sweep now. Idempotent — safe to call repeatedly.
     *
     * Three steps, in this order, because the order is what makes the result
     * correct rather than merely eventually-correct:
     *
     *   1. BIND  — resolve free-text addresses to canonical Cities. Must precede
     *              collection: `attach()` stamps an Order's Zone once, at the
     *              moment it is collected, so an Order collected before its City
     *              is known is pinned to "unzoned" and can never be re-collected.
     *   2. COLLECT — the existing automatic collection, unchanged.
     *   3. RECONCILE — re-resolve Zones for assignments that were already in the
     *              window from an earlier run, made before their City was bound.
     *              Step 1 cannot help them; only this can.
     *
     * Binding is delegated to Geography, which owns `logistics_cities`. Nothing
     * in the Distribution services writes to `orders` — that invariant is
     * preserved deliberately.
     *
     * D1-A CONSISTENCY. Step 3 repairs the window the WORKSPACE is planning, not
     * today's calendar window. Once `current()` anchors on the Preparation Wave,
     * a reconcile aimed at `windowFor(today)` would repair a window nobody is
     * looking at and leave the visible one's unzoned rows unzoned forever — the
     * Refresh button would silently stop working. This is the same strictly
     * bounded repair as before, pointed at the same window the reader sees: it
     * fills `distribution_zone_id` on rows that are NULL and nothing else. No
     * assignment is created, none is moved between windows, and no Group identity
     * changes. Step 2 (`collectForCompany`) keeps its own §16 ingestion rule
     * untouched.
     */
    public function collect(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $warehouseId = $this->warehouseId($request);
        $now = CarbonImmutable::now();

        $binding = $this->cityBinder->bindForCompany($companyId);

        $created = $this->collection->collectForCompany($companyId, $now);

        $wave = $this->aggregation->governingPreparationWave($companyId, $warehouseId);

        $window = $this->windows->resolvePlanningWindow(
            $companyId,
            $wave['wave_id'] ?? null,
            $warehouseId,
            $now,
        );

        // No planning window means there is nothing for step 3 to repair. Creating one
        // here would manufacture the very empty window §1 removed — and reconciling a
        // window nobody is planning repairs nothing. Steps 1 and 2 already ran and are
        // reported as they happened.
        $rezoned = $window === null
            ? 0
            : $this->collection->reconcileUnzoned($companyId, $window->id);

        return response()->json(['data' => [
            'collected' => count($created),
            'cities_bound' => $binding['bound'],
            'cities_unresolved' => $binding['unresolved'],
            'city_failure_reasons' => $binding['reasons'],
            'rezoned' => $rezoned,
        ]]);
    }

    /**
     * The Map tab's data — Zones, Groups and plotted Orders for one Window.
     *
     * Read-only visualisation. Every coordinate is a real captured
     * `orders.google_maps_lat/lng`; Orders without one are returned unplotted and
     * flagged, never given a substitute position. Same `permission:...view` and the
     * same tenant + warehouse scoping as every other read on this controller.
     */
    public function map(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);
        $warehouseId = $this->warehouseId($request);

        return response()->json([
            // The governing Wave is passed so the map's Group overlay isolates to
            // THIS Wave's Groups (plus un-waved ones), exactly as slots()/current()
            // already do — TASK-FINAL-SYNC §GAP-4.
            'data' => $this->aggregation->mapData(
                $w->id,
                $warehouseId,
                $this->activeWaveId($this->companyId($request), $warehouseId),
            ),
        ]);
    }

    public function zones(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);

        return response()->json([
            'data' => $this->aggregation->zoneSummaries($w->id, $this->warehouseId($request)),
        ]);
    }

    public function slots(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);

        $warehouseId = $this->warehouseId($request);

        return response()->json([
            'data' => $this->aggregation->slotSummaries(
                $w->id,
                $warehouseId,
                $this->activeWaveId($this->companyId($request), $warehouseId),
            ),
        ]);
    }

    /** Orders for a Window, optionally narrowed to one Zone or one Slot. */
    public function orders(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);

        $validated = $request->validate([
            'zone_id' => ['nullable', 'integer'],
            'slot_id' => ['nullable', 'uuid'],
            'governorate_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'uuid'],
            'order_status' => ['nullable', 'string', 'max:50'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'distribution_status' => ['nullable', 'string', 'max:50'],
            'late' => ['nullable', 'boolean'],
            // No enum exists for orders.payment_method, so the value is validated
            // as a bounded string rather than against an invented whitelist.
            'payment_method' => ['nullable', 'string', 'max:50'],
            // Received-at range, platform convention. Inclusive at both ends;
            // end_date must not precede start_date.
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Platform sorting convention. Unknown values fall back to the default
            // in the read model rather than erroring, matching sibling endpoints.
            'sort_by' => ['nullable', 'string', 'max:50'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        // Every filter composes server-side in one query. zone_id/slot_id keep
        // their original behaviour, so existing callers are unaffected.
        return response()->json(['data' => $this->aggregation->orders(
            $w->id,
            isset($validated['zone_id']) ? (int) $validated['zone_id'] : null,
            isset($validated['slot_id']) ? (string) $validated['slot_id'] : null,
            [
                'governorate_id' => isset($validated['governorate_id']) ? (int) $validated['governorate_id'] : null,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'order_status' => $validated['order_status'] ?? null,
                'payment_status' => $validated['payment_status'] ?? null,
                'distribution_status' => $validated['distribution_status'] ?? null,
                'late' => array_key_exists('late', $validated) && $validated['late'] !== null
                    ? filter_var($validated['late'], FILTER_VALIDATE_BOOLEAN)
                    : null,
                'payment_method' => $validated['payment_method'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'sort_by' => $validated['sort_by'] ?? null,
                'sort_dir' => $validated['sort_dir'] ?? null,
            ],
        )]);
    }

    /**
     * Orders that arrived after this Window's cutoff and were never collected
     * into it — the Manager's late-order triage list.
     *
     * Read-only. The mutation that pulls one in stays the existing
     * POST /windows/{window}/late-orders; no second mutation was created.
     */
    public function lateOrders(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);

        return response()->json(['data' => $this->aggregation->lateOrders($w)]);
    }

    /**
     * Aggregate product demand — the figure Loading consumes.
     *
     * `total_quantity` is REQUIRED, and it stays exactly what it has always been:
     * the canonical `productAggregation` result, live, never stored.
     *
     * WHEN — AND ONLY WHEN — a `slot_id` narrows this to ONE Distribution Group,
     * three more fields are added per row, because only then is there a Group to
     * attribute Prepared to:
     *
     *   prepared_qty       what the warehouse has recorded as separated for THIS Group
     *   remaining_qty      max(0, required − prepared), DERIVED here, never stored
     *   over_prepared_qty  max(0, prepared − required), also derived
     *
     * `over_prepared_qty` exists because Remaining is floored at 0, which would
     * otherwise hide the case that matters most: Required FELL after the floor had
     * already separated stock — an order left the Group, was cancelled, or was
     * postponed. Reporting only `remaining = 0` there would tell the operator
     * "nothing to do" about a pallet that now holds more than the Group needs.
     *
     * The frontend calculates NOTHING. Both derived figures are computed here, on
     * the same row as the number they derive from, so a second arithmetic engine
     * cannot come into existence in the client — the rule LP-1 established and this
     * extension keeps.
     *
     * Without `slot_id` the payload is byte-identical to before, so the window-wide
     * and zone-wide callers are unaffected.
     */
    public function products(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);

        $zoneId = $request->query('zone_id');
        $slotId = $request->query('slot_id');

        if ($slotId === null) {
            return response()->json(['data' => $this->aggregation->productAggregation(
                $w->id,
                $zoneId === null ? null : (int) $zoneId,
                null,
                $this->warehouseId($request),
            )]);
        }

        // Resolved, not trusted: `slot()` proves the Group belongs to this Window,
        // which `window()` has already proved belongs to the acting company. A
        // foreign Group uuid 404s here rather than returning an empty list, so it
        // cannot be used to probe existence.
        return response()->json(['data' => $this->groupLoadingPreparation(
            $this->slot($w, (string) $slotId),
        )]);
    }

    /**
     * POST /windows/{window}/slots/{slot}/finalize
     *
     * Finalize the Group into its transport execution object(s).
     *
     *     Group (plan) ──Finalize──► Trip(s) (execute)
     *
     * IDEMPOTENT: a second call returns the Trips the first produced. The check runs
     * inside the Group's row lock, so two concurrent Finalizes cannot both create.
     *
     * WRITES NO ORDER STATUS AND NO INVENTORY. Finalize produces a plan-to-execution
     * handover and nothing else; orders reach `out_for_delivery` only through the
     * existing Dispatch path, which is also the inventory-mutation boundary.
     *
     * PERMISSION: `logistics.distribution.update`, on the route — the same permission
     * every existing Trip mutation already carries (`PATCH /trips/{id}/status`,
     * `/trips/{id}/assignment`). Finalize is the moment planning becomes transport,
     * so it belongs to the logistics actor, not the warehouse one. No new permission.
     */
    public function finalizeGroup(Request $request, string $window, string $slot): JsonResponse
    {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        // TASK-1-B-A2 — the SAME route, the SAME service, one optional flag.
        //
        // A dedicated approval endpoint was avoidable and therefore avoided: approving
        // the overflow is not a workflow of its own, it is a qualifier on the Finalize
        // the operator is already performing. Reusing this route also means the approval
        // inherits the exact authorization boundary Finalize already has
        // (`permission:logistics.distribution.update`) — no new permission, and no second
        // place where "who may finalize" is decided.
        $approveOverflow = $request->boolean('approve_overflow');

        try {
            $trips = $this->groupFinalization->finalize($s, $request->user()?->id, $approveOverflow);
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        return response()->json(['data' => $this->presentGroupTrips($trips)]);
    }

    /**
     * GET /windows/{window}/awaiting-group
     *
     * ┌─ THE DEFECT THIS CLOSES ─────────────────────────────────────────────────┐
     * │ An eligible Order can sit in a Window, carry a Zone, and still belong to   │
     * │ NO Group — because a Group only holds the Zones an operator attached to    │
     * │ it. Nothing said so. Worse, every Group-side read is warehouse-scoped, so  │
     * │ an Order with `assigned_warehouse_id = NULL` matched no warehouse and      │
     * │ vanished from the board entirely.                                          │
     * │                                                                          │
     * │ Live today: 5 Orders have no Group. Two have no warehouse (ORD-00013,      │
     * │ ORD-00014), two sit in Zones attached to no Group (DZ-0008, DZ-0009), and  │
     * │ one cannot be zoned at all. None of them appeared anywhere.               │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * READ ONLY. It classifies and returns; it writes nothing, assigns nothing and
     * repairs nothing.
     *
     * WHY A DEDICATED READ AND NOT AN EXTENSION. `GET /windows/current` is consumed by
     * five tabs and `finalizeGroup` shares its Trip presenter; the per-slot
     * reconciliation read answers a different question (one Group vs its Trip). This
     * asks a Window-wide question — which Orders no Group covers — so it gets its own
     * read rather than overloading a certified payload.
     *
     * ELIGIBILITY IS NOT REDEFINED HERE. The Order set comes from
     * `DistributionAggregationService::orders()`, the same call the Groups board and
     * Finalize use, carrying the same `constrainToLoadingEligible` predicate. That is
     * deliberate and it is the ONE deviation from the brief's wording, which named
     * `OrderStatus::fulfilmentEligible()`: the two differ by `ready_for_dispatch`, and
     * three of the five live Orders are in exactly that status. Using the narrower
     * predicate would have hidden them — and would have produced a set that disagrees
     * with the Group counts on the same screen, which is the inconsistency this whole
     * workstream exists to remove. No new predicate is introduced either way.
     *
     * WAREHOUSE-NULL ORDERS ARE ALWAYS INCLUDED, even when a warehouse is supplied.
     * They belong to no warehouse by definition, so a warehouse filter would drop the
     * very rows that most need attention — the defect above. Company scoping is
     * unaffected: the Window is resolved through the controller's own tenant helper, so
     * one company can never see another's Orders.
     */
    public function ordersAwaitingGroup(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);
        $warehouseId = $this->warehouseId($request);

        // Company-scoped by the Window, deliberately NOT warehouse-filtered in the query:
        // the filtering below keeps warehouse-null rows that a warehouse filter would drop.
        $orders = $this->aggregation->orders($w->id);

        // Zone -> Group coverage for this Window, in ONE query. Used as a set lookup, so
        // the classification below costs no query per Order.
        $zonesInAGroup = DB::table('distribution_slot_zones as sz')
            ->join('distribution_virtual_slots as s', 's.id', '=', 'sz.virtual_slot_id')
            ->where('s.distribution_window_id', $w->id)
            ->pluck('sz.distribution_zone_id')
            ->flip();

        $rows = [];
        $counts = [
            self::BLOCKER_WAREHOUSE => 0,
            self::BLOCKER_ZONE => 0,
            self::BLOCKER_AWAITING => 0,
        ];

        foreach ($orders as $order) {
            // Already covered by a Group — not an exception.
            if (($order['virtual_slot_id'] ?? null) !== null) {
                continue;
            }

            // The aggregate already exposes the Order's own warehouse; nothing is
            // re-queried and no warehouse is ever inferred here.
            $warehouse = $order['warehouse_id'] ?? null;
            $zoneId = $order['zone_id'] ?? null;

            // A warehouse-set Order belonging to another warehouse is not this
            // operator's exception. A warehouse-NULL Order belongs to nobody, so it is
            // always shown — see the docblock.
            if ($warehouseId !== null && $warehouse !== null && (string) $warehouse !== $warehouseId) {
                continue;
            }

            // THE ROOT BLOCKER, most actionable first. An Order appears in exactly one
            // bucket; anything else true about it travels as `secondary_reason`.
            if ($warehouse === null) {
                $blocker = self::BLOCKER_WAREHOUSE;
            } elseif ($zoneId === null || ! $zonesInAGroup->has($zoneId)) {
                $blocker = self::BLOCKER_ZONE;
            } else {
                // Warehouse, Zone, and the Zone IS in a Group — yet no slot. Not expected;
                // reported rather than hidden, because it would mean an ingestion gap.
                $blocker = self::BLOCKER_AWAITING;
            }

            $counts[$blocker]++;

            $rows[] = [
                'order_id' => $order['order_id'],
                'order_number' => $order['order_number'],
                'order_status' => $order['order_status'],
                'customer_name' => $order['customer_name'] ?? null,
                'total' => $order['total'] ?? null,
                'payment_state' => $order['payment_state'] ?? null,
                'payment_method_effective' => $order['payment_method_effective'] ?? null,
                'products_count' => $order['products_count'] ?? null,
                'city_name' => $order['city_name'] ?? null,
                'governorate_name' => $order['governorate_name'] ?? null,
                'zone_id' => $zoneId,
                'zone_name' => $order['zone_name'] ?? null,
                'warehouse_id' => $warehouse,
                'warehouse_name' => $order['warehouse_name'] ?? null,
                'blocker' => $blocker,
                // The EXISTING zone-level classifier, carried through unchanged. It answers
                // "why is there no Zone", which is a different question from "why is there
                // no Group", so it travels as the secondary detail instead of being
                // recomputed or overwritten.
                'secondary_reason' => $order['unassigned_reason'] ?? null,
            ];
        }

        return response()->json(['data' => [
            'summary' => [
                'total' => count($rows),
                'warehouse_unassigned' => $counts[self::BLOCKER_WAREHOUSE],
                'zone_not_in_group' => $counts[self::BLOCKER_ZONE],
                'awaiting_group_assignment' => $counts[self::BLOCKER_AWAITING],
            ],
            'orders' => $rows,
            // The SAME rows, rolled up by Zone. Derived here rather than queried again so
            // the two grains can never disagree: if the order list says 2 orders in
            // DZ-0003, the zone card says 2. See zonesWithoutGroup().
            'zones' => $this->zonesWithoutGroup($rows),
        ]]);
    }

    /**
     * The ROOT gap, one level up: Zones that hold work but belong to no Group.
     *
     * ┌─ WHY ZONE LEVEL AT ALL ──────────────────────────────────────────────────┐
     * │ The order-level surface tells the operator that 5 Orders are stranded. It  │
     * │ does not tell them WHY, and it invites triaging the same problem five      │
     * │ times. The cause is configuration: a Group holds only the Zones an         │
     * │ operator attached to it, and three Zones holding live work are attached to │
     * │ none. Fixing one Zone clears every Order behind it.                       │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * DERIVED FROM THE ORDER ROWS, NOT RE-QUERIED. It folds the array the caller just
     * built, so it adds no query, cannot drift from the order list, and inherits the
     * same canonical predicate the Groups board and Finalize use. No second aggregation
     * and no new eligibility rule.
     *
     * WHY NOT `zoneSummaries()`, which is the obvious candidate: it applies the NARROWER
     * `constrainToEligible` predicate and is warehouse-scoped, so it would drop the three
     * `ready_for_dispatch` Orders and every warehouse-null Order — which between them are
     * the entire population this surface exists to show.
     *
     * A WAREHOUSE-NULL ORDER NEVER HIDES ITS ZONE. DZ-0003's only two Orders both lack a
     * warehouse; the Zone still appears, and carries `orders_needing_warehouse` so the UI
     * can say "no Group, and these also need a warehouse" instead of collapsing two
     * different blockers into one.
     *
     * AN ORDER WITH NO ZONE PRODUCES NO ROW. There is no synthetic "unzoned" Zone: a Zone
     * that does not exist cannot be configured, and inventing one would offer the operator
     * an action that leads nowhere. Those Orders stay visible in the order-level list.
     *
     * @param  list<array<string, mixed>>  $rows  the classified Orders awaiting a Group
     * @return list<array<string, mixed>>
     */
    private function zonesWithoutGroup(array $rows): array
    {
        $zones = [];

        foreach ($rows as $row) {
            $zoneId = $row['zone_id'] ?? null;

            // No Zone, no Zone card — see the docblock.
            if ($zoneId === null) {
                continue;
            }

            $key = (string) $zoneId;

            if (! isset($zones[$key])) {
                $zones[$key] = [
                    'zone_id' => $zoneId,
                    'zone_name' => $row['zone_name'] ?? null,
                    'orders_waiting' => 0,
                    'orders_needing_warehouse' => 0,
                    // Distinct governorates present among the waiting Orders. A Zone spans
                    // cities, so this reports what is actually there rather than implying
                    // a Zone has one address.
                    'governorates' => [],
                    'warehouses' => [],
                ];
            }

            $zones[$key]['orders_waiting']++;

            if (($row['blocker'] ?? null) === self::BLOCKER_WAREHOUSE) {
                $zones[$key]['orders_needing_warehouse']++;
            }

            $governorate = $row['governorate_name'] ?? null;
            if ($governorate !== null && ! in_array($governorate, $zones[$key]['governorates'], true)) {
                $zones[$key]['governorates'][] = $governorate;
            }

            $warehouse = $row['warehouse_name'] ?? null;
            if ($warehouse !== null && ! in_array($warehouse, $zones[$key]['warehouses'], true)) {
                $zones[$key]['warehouses'][] = $warehouse;
            }
        }

        // Busiest first: the Zone blocking the most work is the one worth configuring next.
        usort(
            $zones,
            static fn (array $a, array $b): int => $b['orders_waiting'] <=> $a['orders_waiting']
                ?: strcmp((string) ($a['zone_name'] ?? ''), (string) ($b['zone_name'] ?? '')),
        );

        return array_values($zones);
    }

    /**
     * GET /windows/{window}/slots/{slot}/trips
     *
     * The Group's transport execution objects, for the Group card.
     */
    public function groupTrips(Request $request, string $window, string $slot): JsonResponse
    {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        $trips = Trip::query()
            ->where('virtual_slot_id', $s->id)
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle'])
            ->withCount('tripOrders')
            ->orderBy('id')
            ->get()
            ->all();

        /*
         * TASK-1-C §8/§11 — readiness travels WITH the trips, on the endpoint the panel
         * already calls. A separate readiness endpoint would be a second Loading surface
         * (§9 forbids one) and would let the two answers drift apart.
         *
         * `readiness()` runs the very guards `open()` runs, so what the panel shows and
         * what Start Loading does can never disagree. It writes nothing.
         */
        $readiness = array_map(
            fn (Trip $trip): array => [
                'trip_id' => (string) $trip->uuid,
            ] + $this->groupLoading->readiness($s, $trip),
            $trips,
        );

        return response()->json([
            'data' => $this->presentGroupTrips($trips),
            'readiness' => $readiness,
        ]);
    }

    /**
     * GET /windows/{window}/slots/{slot}/reconciliation
     *
     * ┌─ WHY THIS EXISTS ────────────────────────────────────────────────────────┐
     * │ A Group plans; a Trip executes. `distribution_trip_orders` is an          │
     * │ EXECUTION MANIFEST — a snapshot taken at Finalize — and Group membership   │
     * │ lives in `distribution_window_orders.virtual_slot_id`. The approved        │
     * │ contract deliberately does NOT synchronise them, and a certified test      │
     * │ (DistributionGroupTripTest::…_is_idempotent) enforces that a repeated      │
     * │ Finalize leaves the manifest row count unchanged.                          │
     * │                                                                          │
     * │ The consequence was that the two counts could differ with nothing on any   │
     * │ screen explaining why. This endpoint makes the difference VISIBLE. It      │
     * │ changes no behaviour, writes nothing, and adds no source of truth: both    │
     * │ sides are read from their existing owners and the two set differences are  │
     * │ computed here, server-side, so the client never has to invent a second     │
     * │ idea of membership.                                                       │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * WHY THE DIFFERENCE IS NOT `group_orders - trip_orders`. Subtraction is wrong
     * whenever a manifest row is no longer a Group member: on live DG-001 the naive
     * arithmetic gives 7 − 3 = 4 while the true set difference is 5 unassigned plus 1
     * exception, because ORD-00007 occupies a manifest slot without being a member.
     * Two independent sets, so two independent differences.
     *
     * GROUP MEMBERSHIP IS READ FROM THE CANONICAL AGGREGATE — the same
     * `DistributionAggregationService::orders()` call, with the same loading-eligible
     * predicate, that Finalize itself uses and that produces the count on the Group
     * card. No second predicate is introduced, and no order appears here merely for
     * being fulfilment-eligible: it must be a member of THIS Group.
     *
     * CANCELLED TRIPS ARE EXCLUDED, matching Finalize's own idempotency read: a Group
     * whose only Trip was cancelled holds no live execution, so its former manifest
     * must not make its orders look assigned.
     */
    public function groupReconciliation(Request $request, string $window, string $slot): JsonResponse
    {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        // ── Group side: the canonical member rows, rendered as supplied ──────────
        $members = $this->aggregation->orders($w->id, null, $s->id, ['warehouse_id' => $s->warehouse_id]);

        $memberIds = [];
        foreach ($members as $row) {
            $memberIds[(string) $row['order_id']] = true;
        }

        // ── Trip side: the live manifest of this Group's non-cancelled Trips ─────
        $trips = Trip::query()
            ->where('virtual_slot_id', $s->id)
            ->where('status', '!=', TripStatus::Cancelled->value)
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle'])
            ->withCount('tripOrders')
            ->orderBy('id')
            ->get();

        $manifest = DB::table('distribution_trip_orders as tor')
            ->join('distribution_trips as t', 't.id', '=', 'tor.trip_id')
            ->join('orders as o', 'o.id', '=', 'tor.order_id')
            ->whereIn('tor.trip_id', $trips->pluck('id')->all())
            ->orderBy('o.order_number')
            ->get([
                'tor.order_id',
                'o.order_number',
                'o.status as order_status',
                't.trip_number',
                'tor.assignment_type',
                'tor.assigned_at',
                'tor.zone_code_snapshot',
            ]);

        $manifestIds = [];
        foreach ($manifest as $row) {
            $manifestIds[(string) $row->order_id] = true;
        }

        // ── The two differences ──────────────────────────────────────────────────
        $unassigned = array_values(array_filter(
            $members,
            static fn (array $row): bool => ! isset($manifestIds[(string) $row['order_id']]),
        ));

        $exceptions = [];
        foreach ($manifest as $row) {
            if (isset($memberIds[(string) $row->order_id])) {
                continue;
            }

            $exceptions[] = [
                'order_id' => (string) $row->order_id,
                'order_number' => (string) $row->order_number,
                'order_status' => (string) $row->order_status,
                'trip_number' => (string) $row->trip_number,
                'assignment_type' => (string) $row->assignment_type,
                'assigned_at' => $row->assigned_at,
                'zone_code_snapshot' => $row->zone_code_snapshot,
            ];
        }

        // ── THE STATE, DECIDED SERVER-SIDE ──────────────────────────────────────
        //
        // "Not assigned to a trip" is NOT an operational state, and presenting it as
        // one was the defect this replaces. The same raw difference means four
        // different things depending on where the Group is in its lifecycle, and each
        // one asks the operator for something different:
        //
        //   capacity_decision_required  over planning capacity — Finalize will refuse
        //   awaiting_finalization       within capacity, no Trip yet — Finalize next
        //   added_after_finalization    finalized, but members joined afterwards
        //   resolved                    every member is on a Trip
        //
        // Derived here so the client cannot disagree with the write path about which
        // situation it is looking at. `capacity_orders` is NOT modified and NOT
        // reinterpreted: NULL still means unconstrained, exactly as
        // GroupCapacityGuard and Finalize already read it.
        $capacity = $s->capacity_orders;
        $overflow = ($capacity === null || count($members) <= $capacity)
            ? 0
            : count($members) - $capacity;

        // An APPROVED overflow is its own state: still over the planning capacity, but no
        // longer awaiting a decision. Reported separately so the screen never has to
        // choose between showing the overflow and showing that it was accepted.
        $approvedOverflow = $overflow > 0 && $s->hasApprovedOverflowFor(count($members));

        if ($overflow > 0 && $approvedOverflow) {
            $state = self::STATE_OVERFLOW_APPROVED;
        } elseif ($overflow > 0) {
            $state = self::STATE_CAPACITY_DECISION;
        } elseif ($trips->isEmpty()) {
            $state = self::STATE_AWAITING_FINALIZATION;
        } elseif (count($unassigned) > 0) {
            $state = self::STATE_ADDED_AFTER_FINALIZATION;
        } else {
            $state = self::STATE_RESOLVED;
        }

        return response()->json(['data' => [
            'state' => $state,
            'capacity' => [
                // Rendered as supplied. NULL maximum = unconstrained, never zero.
                // `maximum` is the PLANNING capacity and is never the approved figure:
                // an approved Group with 25 orders still reports a maximum of 20.
                'maximum' => $capacity,
                'current' => count($members),
                'remaining' => $capacity === null ? null : max(0, $capacity - count($members)),
                'overflow' => $overflow,
                'overflow_approved' => $approvedOverflow,
                'overflow_approved_orders' => $s->overflow_approved_orders,
                'overflow_approved_at' => $s->overflow_approved_at?->toIso8601String(),
            ],
            'summary' => [
                'group_orders' => count($members),
                'trip_orders' => $manifest->count(),
                'unassigned_orders' => count($unassigned),
                'exception_orders' => count($exceptions),
            ],
            'trips' => $this->presentGroupTrips($trips->all()),
            // Rendered as supplied by the canonical order aggregate — the client adds
            // no field and derives no status of its own.
            'unassigned_orders' => $unassigned,
            'exceptions' => $exceptions,
        ]]);
    }

    /**
     * GET /windows/{window}/slots/{slot}/fleet-options
     *
     * The Vehicle and Driver selectors for the assignment drawer.
     *
     * S-6: both lists are read THROUGH the Eloquent models, so their tenant global
     * scopes apply and a foreign company's fleet is not merely hidden by a filter
     * the client could drop — it is unreachable. The endpoint therefore cannot be
     * used to enumerate another tenant's vehicles or drivers.
     *
     * D4: only vehicles with a usable `capacity_orders` are offered, and each
     * carries its capacity so the drawer can show the fit WITHOUT computing it —
     * the authoritative comparison happens server-side at assign time.
     */
    public function groupFleetOptions(Request $request, string $window, string $slot): JsonResponse
    {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        // Wave-isolated for the SAME reason slots()/current() are — the fleet drawer's
        // order count must be read from THIS Wave's Group, not a same-window Group left
        // by another Wave (TASK-FINAL-SYNC §GAP-4). A null waveId behaves as before.
        $groupOrders = 0;
        foreach ($this->aggregation->slotSummaries(
            $w->id,
            $s->warehouse_id,
            $this->activeWaveId($this->companyId($request), $s->warehouse_id),
        ) as $summary) {
            if (($summary['slot_id'] ?? null) === $s->id) {
                $groupOrders = (int) ($summary['orders_count'] ?? 0);
                break;
            }
        }

        $vehicleModels = Vehicle::query()
            ->where('capacity_orders', '>', 0)
            ->orderBy('plate_number')
            ->get();

        // TASK-DISTRIBUTION-VEHICLE-DRIVER-PAIRING-FILTER-FIX-001 — the drivers a
        // vehicle may actually be run with, read from the CANONICAL pairing ledger
        // (`logistics_driver_vehicle_assignments`, active_flag = 1 while live).
        //
        // This is ADDITIVE: the flat `drivers` list below is unchanged, because it
        // is a certified contract (a fresh unpaired driver must still be published
        // there, and a foreign company's fleet must still be empty). The selector
        // narrows to `driver_ids`; nothing else that reads this payload changes.
        //
        // Driver uuids are resolved THROUGH the Driver model, so its tenant global
        // scope applies — a pairing pointing at another company's driver resolves
        // to nothing and is omitted rather than leaked (S-6, fail-closed).
        //
        // TASK-DISTRIBUTION-DRIVER-AVAILABILITY-FIX-001 — and a pairing already
        // engaged by a live trip on another Group is dropped here (see the helper),
        // so the drawer never offers a driver/vehicle that is busy elsewhere.
        $eligibleDriverUuids = $this->activeDriverUuidsByVehicleId($vehicleModels->pluck('id')->all(), $s->id);

        $vehicles = $vehicleModels
            ->map(fn (Vehicle $v): array => [
                // The uuid is the CROSS-MODULE reference (D1-C). The bigint id is
                // never published to the client.
                'id' => $v->uuid,
                'plate_number' => $v->plate_number,
                'name' => $v->name,
                'status' => $v->status instanceof BackedEnum ? $v->status->value : $v->status,
                'capacity_orders' => (int) $v->capacity_orders,
                // Server-decided fit, so the drawer never does this arithmetic.
                'fits_group' => $groupOrders <= (int) $v->capacity_orders,
                // Drivers ACTIVELY paired to this vehicle. Empty = none assigned,
                // which the drawer states explicitly instead of offering the world.
                'driver_ids' => $eligibleDriverUuids[$v->id] ?? [],
            ])
            ->all();

        $drivers = Driver::query()
            ->where('status', Driver::STATUS_ACTIVE)
            ->orderBy('full_name')
            ->get()
            ->map(fn (Driver $d): array => [
                'id' => $d->uuid,
                'full_name' => $d->full_name,
                'driver_code' => $d->driver_code,
                'mobile' => $d->mobile,
            ])
            ->all();

        return response()->json([
            'data' => [
                'group_orders' => $groupOrders,
                'vehicles' => $vehicles,
                'drivers' => $drivers,
            ],
        ]);
    }

    /**
     * Active driver uuids per vehicle bigint id, from the canonical pairing ledger.
     *
     * `active_flag` is 1 while a pairing is live and NULL once released, and the
     * unique indexes guarantee at most one active driver per vehicle — so each
     * list holds at most one entry today. It is still returned as a LIST because
     * the selector's contract is "the drivers eligible for this vehicle", and a
     * shape that assumed exactly one would have to change if that rule ever did.
     *
     * @param  list<int>  $vehicleIds
     * @return array<int, list<string>>
     */
    private function activeDriverUuidsByVehicleId(array $vehicleIds, ?string $currentGroupId): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $pairings = DriverVehicleAssignment::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereNotNull('active_flag')
            ->get(['id', 'driver_id', 'vehicle_id']);

        if ($pairings->isEmpty()) {
            return [];
        }

        // TASK-DISTRIBUTION-DRIVER-AVAILABILITY-FIX-001 — a pairing already engaged
        // by a live (non-terminal) trip on ANOTHER Group is not offered here. This
        // calls the SAME predicate the write guard enforces, so the drawer can never
        // present a combination the assign endpoint would reject. A pairing engaged
        // on THIS group's own trip is the idempotent case and is deliberately kept,
        // so re-opening the drawer still shows the group's current selection.
        $engaged = array_flip($this->trips->assignmentsEngagedElsewhere(
            $pairings->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            $currentGroupId,
        ));

        // Tenant-scoped: a driver outside the actor's company resolves to nothing
        // and is therefore never published as eligible.
        $uuidByDriverId = Driver::query()
            ->whereIn('id', $pairings->pluck('driver_id')->unique()->all())
            ->where('status', Driver::STATUS_ACTIVE)
            ->pluck('uuid', 'id');

        $map = [];

        foreach ($pairings as $pairing) {
            // Busy on another Group — the pairing consumes its availability there.
            if (isset($engaged[(int) $pairing->id])) {
                continue;
            }

            $uuid = $uuidByDriverId[$pairing->driver_id] ?? null;

            if ($uuid !== null) {
                $map[(int) $pairing->vehicle_id][] = (string) $uuid;
            }
        }

        return $map;
    }

    /**
     * POST /windows/{window}/slots/{slot}/assign-vehicle
     *
     * D3-D: writes through the canonical pairing ledger, never around it.
     * D4-C: rejects server-side when the group's orders exceed the vehicle's
     * capacity — the frontend disabling a button is not the guard.
     */
    public function assignGroupVehicle(Request $request, string $window, string $slot): JsonResponse
    {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        $validated = $request->validate([
            // Shape only. Ownership and existence are decided by the resolver,
            // because a raw `exists:` rule bypasses the tenant global scope.
            'vehicle_id' => ['required', 'string', 'max:64'],
            'driver_id' => ['required', 'string', 'max:64'],
        ]);

        try {
            $result = $this->groupAssignment->assign(
                $s,
                $validated['vehicle_id'],
                $validated['driver_id'],
                $request->user()?->id,
            );
        } catch (DistributionException $e) {
            return $this->rejected($e);
        } catch (VehicleAssignmentException|FleetAssignmentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'trip' => $this->presentGroupTrips([$result['trip']])[0] ?? null,
                'group_orders' => $result['group_orders'],
                'vehicle_capacity' => $result['vehicle_capacity'],
                'remaining_capacity' => $result['remaining_capacity'],
            ],
        ]);
    }

    /**
     * POST /windows/{window}/slots/{slot}/trips/{trip}/loading
     *
     * Open the Loading execution context for a Group's Trip, and return it.
     *
     * ONE call, because opening Loading and reading its context are the same
     * operator action: the session and vehicle assignment are LOCATED if they
     * already exist and created only if they do not, so pressing this twice
     * yields the same two rows rather than a second session.
     *
     * CAPACITY IS AN ORDER COUNT. No weight, volume or refrigeration figure is
     * sent, computed or validated anywhere in this path.
     *
     * Required / Prepared / Remaining are reused verbatim from the canonical
     * Group projection (`groupLoadingPreparation`) — Loading does not recompute
     * them and does not reach for the Preparation Wave to reconstruct the
     * Group's demand.
     */
    public function openGroupLoading(
        Request $request,
        string $window,
        string $slot,
        string $trip,
    ): JsonResponse {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        // Tenant + ownership: the Trip must belong to THIS Group, resolved from
        // the Group rather than looked up globally.
        //
        // Addressed by UUID, which is the public Trip identifier everywhere else
        // in this API (`presentGroupTrips` emits `trip_id => $t->uuid`, and
        // TripController resolves `where('uuid', $id)`). The internal bigint is
        // never published, so accepting it here would have created a second way
        // to name a Trip.
        $t = Trip::query()
            ->where('virtual_slot_id', $s->id)
            ->where('uuid', $trip)
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle'])
            ->first();

        if ($t === null) {
            return response()->json(['message' => 'Trip not found for this group.'], 404);
        }

        try {
            $context = $this->groupLoading->open($s, $t, (string) $request->user()->id);
        } catch (FleetAssignmentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $session = $context['session'];
        $assignment = $context['assignment'];
        $pairing = $t->driverVehicleAssignment;

        return response()->json([
            'data' => [
                // ── Header context the Loading operator needs ──────────────
                'group' => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => $s->name,
                    'warehouse_id' => $s->warehouse_id,
                    // The ONE capacity dimension in ECOS. Null means the group
                    // is unconstrained by order count, not "zero orders".
                    'capacity_orders' => $s->capacity_orders,
                ],
                'trip' => [
                    // The UUID, matching every other Trip read in this API
                    // (`presentGroupTrips`, `TripController`) and matching what
                    // this endpoint's own route accepts. The internal bigint is
                    // never published — publishing it here would have created a
                    // second way to name a Trip, and a client that round-tripped
                    // this value back into the route would have got a 404.
                    'id' => $t->uuid,
                    'trip_number' => $t->trip_number,
                    'status' => $t->status instanceof BackedEnum ? $t->status->value : $t->status,
                ],
                'vehicle' => $pairing?->vehicle === null ? null : [
                    'id' => $pairing->vehicle->uuid,
                    'plate_number' => $pairing->vehicle->plate_number,
                    'capacity_orders' => (int) $pairing->vehicle->capacity_orders,
                ],
                'driver' => $pairing?->driver === null ? null : [
                    'id' => $pairing->driver->uuid,
                    'full_name' => $pairing->driver->full_name,
                    'mobile' => $pairing->driver->mobile,
                ],
                'loading' => [
                    'session_id' => $session->id,
                    'session_number' => $session->session_number,
                    'session_status' => $session->status,
                    'assignment_id' => $assignment->id,
                    'assignment_number' => $assignment->assignment_number,
                    'assignment_status' => $assignment->status,
                ],
                // ── Required / Prepared / Remaining, canonical ──────────────
                'products' => $this->groupLoadingPreparation($s),
            ],
        ]);
    }

    /**
     * One shape for a Group's Trip, used by both the finalize response and the read.
     *
     * Vehicle and Driver are READ THROUGH the canonical pairing
     * (`logistics_driver_vehicle_assignments`) — they are not stored on the Trip and
     * are certainly not stored on the Group. The UI displaying them does not make
     * either object their owner.
     *
     * @param  list<Trip>  $trips
     * @return list<array<string, mixed>>
     */
    private function presentGroupTrips(array $trips): array
    {
        return array_map(static function (Trip $t): array {
            $pairing = $t->driverVehicleAssignment;

            return [
                'trip_id' => $t->uuid,
                'trip_number' => $t->trip_number,
                'name' => $t->name,
                'status' => $t->status instanceof BackedEnum ? $t->status->value : $t->status,
                'capacity' => (int) $t->capacity,
                'orders_count' => (int) ($t->trip_orders_count ?? $t->orders_count),
                // Trip::remainingCapacity() — the Trip's OWN existing rule, exposed
                // rather than reimplemented, so the screen and assignOrder's capacity
                // refusal can never disagree. Trip capacity remains independent of
                // Group capacity; nothing here derives one from the other.
                'remaining_capacity' => $t->remainingCapacity(),
                'finalized_at' => $t->finalized_at?->toIso8601String(),
                'dispatched_at' => $t->dispatched_at?->toIso8601String(),
                // Null until a pairing is attached — which is a later, separate act.
                'driver_vehicle_assignment_id' => $t->driver_vehicle_assignment_id,
                'vehicle' => $pairing?->vehicle === null ? null : [
                    'id' => $pairing->vehicle->id,
                    'plate_number' => $pairing->vehicle->plate_number,
                    'name' => $pairing->vehicle->name,
                ],
                'driver' => $pairing?->driver === null ? null : [
                    'id' => $pairing->driver->id,
                    'full_name' => $pairing->driver->full_name,
                    'mobile' => $pairing->driver->mobile,
                ],
            ];
        }, $trips);
    }

    /**
     * The Loading Preparation rows for ONE Group — the single presenter.
     *
     * Used by the GET and echoed by the PUT, so a read and a write can never present
     * a different idea of the same Group. This mirrors the pattern Preparation's own
     * demand endpoints use (`presentProductDemand`, shared by the list and the
     * Prepared write) for exactly that reason.
     *
     * Warehouse scope comes from the GROUP's own `warehouse_id` (NOT NULL since
     * Part 5B), never from a request parameter — so the Part 5B boundary is
     * structural here rather than dependent on what the caller sent.
     *
     * @return list<array<string, mixed>>
     */
    private function groupLoadingPreparation(VirtualCapacitySlot $group): array
    {
        $rows = $this->aggregation->productAggregation(
            $group->distribution_window_id,
            null,
            $group->id,
            $group->warehouse_id,
        );

        $prepared = $this->groupPreparation->preparedByProduct($group->id);

        $out = array_map(
            static function (array $row) use ($prepared): array {
                $required = (float) $row['total_quantity'];
                // A product with no row has never been touched. It renders as 0
                // without a row having to be created to say so.
                $done = (float) ($prepared[(string) $row['product_id']] ?? 0.0);

                return $row + [
                    'prepared_qty' => $done,
                    'remaining_qty' => max(0.0, round($required - $done, 4)),
                    'over_prepared_qty' => max(0.0, round($done - $required, 4)),
                ];
            },
            $rows,
        );

        // ── PREPARED-ONLY ROWS ───────────────────────────────────────────────
        //
        // A product the Group NO LONGER requires but has already prepared. It
        // happens whenever Required falls under a recorded quantity: a Zone is
        // detached, an order is moved out, cancelled, or postponed by Preparation.
        //
        // The contract says such a record is retained rather than deleted — the
        // stock is physically on the Group's pallet and someone has to deal with
        // it. Retaining it in storage while omitting it from the read would be the
        // worst of both: the row survives and nobody can see it. So it is appended
        // with Required 0, which is what makes `over_prepared_qty` visible instead
        // of being permanently unreachable behind a floored Remaining.
        //
        // Deliberately NOT deleted, and deliberately NOT auto-transferred to
        // whichever Group now holds the order — moving stock is a physical act, and
        // the system must not assert one that has not happened.
        $orphans = array_diff_key(
            $prepared,
            array_flip(array_map(static fn (array $r): string => (string) $r['product_id'], $rows)),
        );

        if ($orphans !== []) {
            $names = DB::table('products as p')
                ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
                ->whereIn('p.id', array_keys($orphans))
                ->select(['p.id', 'p.name', 'p.sku', 'u.code as unit_code', 'u.symbol as unit_symbol'])
                ->get()
                ->keyBy('id');

            foreach ($orphans as $productId => $done) {
                $meta = $names[$productId] ?? null;

                $out[] = [
                    'product_id' => (string) $productId,
                    'product_name' => $meta?->name,
                    'product_sku' => $meta?->sku,
                    'unit_code' => $meta?->unit_code,
                    'unit_symbol' => $meta?->unit_symbol,
                    'total_quantity' => 0.0,
                    'prepared_qty' => (float) $done,
                    'remaining_qty' => 0.0,
                    'over_prepared_qty' => (float) $done,
                ];
            }
        }

        return $out;
    }

    /**
     * PUT /windows/{window}/slots/{slot}/preparation/{product}
     *
     * Set — not increment — how much of one Product this Group has prepared.
     *
     * ABSOLUTE SET is what makes a retry safe: replaying the identical request
     * writes the identical number. There is no idempotency key, because none is
     * needed and none exists in this platform.
     *
     * The ceiling `prepared <= Required` is NOT enforced here. Required is live, so
     * validating it in the controller would read it outside the lock and let the
     * Group shrink before the write lands. `max` below is deliberately absent for
     * the same reason — the authoritative ceiling is recomputed inside
     * GroupPreparationService's transaction, under the Group's row lock.
     *
     * PERMISSION: `operations.preparation.update`, on the route. That is a
     * deliberate cross-module choice, made on evidence rather than on which module
     * owns the URL: the live role matrix shows Warehouse Operator, Warehouse
     * Manager, Preparation Supervisor and Branch Manager hold it while NOT holding
     * `logistics.distribution.update` — and Driver and Dispatcher hold the latter.
     * Gating on the Distribution permission would lock out every role that does this
     * work and admit two that do not. The codebase already gates by actor rather
     * than by module: `PUT preparation/waves/{w}/missing-materials/{m}/expected-incoming`
     * is a Preparation route carrying `purchasing.expected_incoming.update`.
     */
    public function setGroupPreparation(
        Request $request,
        string $window,
        string $slot,
        string $product,
    ): JsonResponse {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        $validated = $request->validate([
            // No `max:` — the ceiling is live and belongs inside the lock (above).
            // No `exists:products,id` — an existence rule on a table this actor may
            // not own is a cross-tenant oracle; the same reason `warehouse_id` is
            // validated as a bare uuid at :248 and checked tenant-scoped afterwards.
            // Here the check is stronger still: a product this Group does not require
            // is refused by the service regardless of whether it exists.
            'prepared_qty' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->groupPreparation->record(
                $s,
                $product,
                (float) $validated['prepared_qty'],
                $request->user()?->id,
            );
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        // Echo the Group's whole Loading Preparation list, from the same presenter the
        // GET uses, so the client re-renders from the server's arithmetic rather than
        // patching its own row. Required may legitimately have moved since the client
        // last read it, and this is the moment it finds out.
        return response()->json(['data' => $this->groupLoadingPreparation($s)]);
    }

    /** Over-capacity Slots with candidate Orders and candidate destinations. */
    public function overflows(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);

        return response()->json([
            'data' => $this->redistribution->overflows($w->id, $this->warehouseId($request)),
        ]);
    }

    /** Create a Virtual Capacity Slot. Never creates or references a Vehicle. */
    public function storeSlot(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);

        $validated = $request->validate([
            // REQUIRED. A Distribution Group is owned by exactly one warehouse, and
            // ownership is explicit — never inferred from the zone, the geography,
            // the latest wave, or whatever warehouse the operator happened to have
            // selected somewhere else. A group created without an owner is the
            // cross-warehouse defect this Part exists to close.
            'warehouse_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:100'],
            'capacity_orders' => ['nullable', 'integer', 'min:0'],
            'capacity_stops' => ['nullable', 'integer', 'min:0'],
            'capacity_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'capacity_volume_m3' => ['nullable', 'numeric', 'min:0'],
        ]);

        // The warehouse must belong to the acting company. Without this a caller
        // could name another tenant's warehouse and create a group pointing at it.
        $ownsWarehouse = DB::table('warehouses')
            ->where('id', $validated['warehouse_id'])
            ->where('company_id', $w->company_id)
            ->exists();

        if (! $ownsWarehouse) {
            // Reported as not-found rather than forbidden: a warehouse outside the
            // tenant boundary must not be confirmed to exist.
            abort(404, 'Warehouse not found.');
        }

        $slot = VirtualCapacitySlot::query()->create([
            'company_id' => $w->company_id,
            'distribution_window_id' => $w->id,
            ...$validated,
        ]);

        return response()->json(['data' => $slot], 201);
    }

    /**
     * Edit a Distribution Group's configuration — its name and its maximum orders.
     *
     * CONFIGURATION ONLY. The warehouse is NOT editable: a Group's owner is fixed
     * at creation, and letting it move would hand another warehouse's Orders to
     * this Group by editing one field. `code` is not editable either — it is how
     * the Group is identified on every other surface, including its Trips.
     *
     * `capacity_orders` is the ONE capacity axis (decision D4-C). The stops, weight
     * and volume columns are not accepted here: nothing enforces them, and offering
     * them would invite an operator to set a limit that silently does nothing.
     *
     * Sending `capacity_orders: null` removes the maximum, which is the existing
     * "unconstrained" contract rather than a limit of zero.
     */
    public function updateSlot(Request $request, string $window, string $slot): JsonResponse
    {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            // min:1, not min:0. A maximum of zero is a Group that can never hold an
            // order and could never be finalized; "no limit" is expressed as null.
            'capacity_orders' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        try {
            $updated = DB::transaction(function () use ($s, $validated): VirtualCapacitySlot {
                // Locked and re-read before the comparison, so a concurrent add
                // cannot slip in between "count the orders" and "save the limit"
                // and leave the Group saved below its own occupancy.
                /** @var VirtualCapacitySlot $locked */
                $locked = VirtualCapacitySlot::query()->lockForUpdate()->findOrFail($s->id);

                if (array_key_exists('capacity_orders', $validated)) {
                    $this->groupCapacity->assertCapacityFitsCurrentOrders(
                        $locked,
                        $validated['capacity_orders'],
                    );
                }

                $locked->forceFill($validated)->save();

                return $locked;
            });
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        return response()->json(['data' => $this->slotPayload($updated)]);
    }

    /**
     * One Group's configuration and live capacity, from the canonical read model.
     *
     * `remaining_orders` is derived here the same way `slotSummaries()` derives it,
     * through the same guard the write paths use, so the Settings panel and the
     * enforcement can never disagree about how much room a Group has.
     */
    private function slotPayload(VirtualCapacitySlot $slot): array
    {
        $current = $this->groupCapacity->currentOccupancy($slot);

        return [
            'slot_id' => $slot->id,
            'code' => $slot->code,
            'name' => $slot->name,
            'warehouse_id' => $slot->warehouse_id,
            'capacity_orders' => $slot->capacity_orders,
            'orders_count' => $current,
            'remaining_orders' => $this->groupCapacity->remainingCapacity($slot),
        ];
    }

    /** Put a Zone into a Slot; already-collected Orders follow immediately. */
    public function assignZoneToSlot(Request $request, string $window, string $slot): JsonResponse
    {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        $validated = $request->validate([
            'zone_id' => ['required', 'integer', 'exists:distribution_zones,id'],
        ]);

        try {
            $this->manual->assignZoneToSlot($w, (int) $validated['zone_id'], $s);
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        // Echo the groups of the SLOT'S OWN warehouse, not the whole company — the
        // caller just acted on one warehouse's plan and must not receive another's.
        return response()->json([
            'data' => $this->aggregation->slotSummaries($w->id, $s->warehouse_id),
        ]);
    }

    /**
     * Remove a Zone from a Distribution Group.
     *
     * The Zone's orders leave the group's totals. Nothing about the Orders
     * themselves changes, and the other warehouses' claims on the same Zone are
     * untouched — a Zone is shared geography.
     */
    public function detachZoneFromSlot(Request $request, string $window, string $slot, int $zone): JsonResponse
    {
        $w = $this->window($request, $window);
        $s = $this->slot($w, $slot);

        try {
            $this->manual->detachZone($w, $zone, $s->warehouse_id);
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        return response()->json([
            'data' => $this->aggregation->slotSummaries($w->id, $s->warehouse_id),
        ]);
    }

    /** Move a Zone between two Groups of the SAME warehouse. */
    public function moveZoneBetweenSlots(Request $request, string $window, string $slot): JsonResponse
    {
        $w = $this->window($request, $window);
        $destination = $this->slot($w, $slot);

        $validated = $request->validate([
            'zone_id' => ['required', 'integer', 'exists:distribution_zones,id'],
            'from_slot_id' => ['required', 'uuid'],
        ]);

        $source = $this->slot($w, (string) $validated['from_slot_id']);

        try {
            $this->manual->moveZone($w, (int) $validated['zone_id'], $source, $destination);
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        return response()->json([
            'data' => $this->aggregation->slotSummaries($w->id, $destination->warehouse_id),
        ]);
    }

    /** Manual Zone change. Permitted after cutoff. */
    public function changeZone(Request $request, string $assignment): JsonResponse
    {
        $a = $this->assignment($request, $assignment);

        $validated = $request->validate([
            'zone_id' => ['required', 'integer', 'exists:distribution_zones,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $updated = $this->manual->changeOrderZone(
                $a,
                (int) $validated['zone_id'],
                $this->actorId($request),
                $validated['reason'] ?? null,
            );
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        return response()->json(['data' => $updated->fresh()]);
    }

    /**
     * Manual Slot change. Permitted after cutoff.
     *
     * Approving a redistribution suggestion is exactly this call with the
     * suggested Slot — suggestions carry no separate approval machinery because
     * they carry no state to approve.
     */
    public function changeSlot(Request $request, string $assignment): JsonResponse
    {
        $a = $this->assignment($request, $assignment);

        $validated = $request->validate([
            'slot_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $slot = null;

        if (($validated['slot_id'] ?? null) !== null) {
            $slot = $this->slot($a->window, (string) $validated['slot_id']);
        }

        try {
            $updated = $this->manual->changeOrderSlot(
                $a,
                $slot,
                $this->actorId($request),
                $validated['reason'] ?? null,
            );
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        return response()->json(['data' => $updated->fresh()]);
    }

    /**
     * Manual Slot change for SEVERAL Orders — all of them or none.
     *
     * WHY THE PATH IS NOT `/assignments/batch/slot`
     * That would collide with `/assignments/{assignment}/slot` — the literal segment
     * "batch" is a valid `{assignment}` value, so which route answered would depend on
     * registration order. `batch-slot` cannot be mistaken for an id, and matches the
     * kebab-case convention already used by `late-orders` and `assign-vehicle`.
     *
     * TENANCY IS THE SAME HELPER, PER ORDER. Each id goes through `assignment()`, which
     * scopes to the caller's company and 404s otherwise — so a batch containing one
     * foreign Order fails before the service is reached, and nothing is written. No
     * batch-specific tenancy rule exists to get out of step with the single-Order path.
     *
     * DUPLICATES ARE REFUSED HERE TOO, on the raw payload, so a repeated id is rejected
     * for what it is rather than after being resolved into two identical models.
     */
    public function changeSlotBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Bounded deliberately: an operator selects a screenful, and an unbounded
            // list would hold the destination Group's row lock for as long as it took.
            'assignment_ids' => ['required', 'array', 'min:1', 'max:200'],
            'assignment_ids.*' => ['required', 'uuid'],
            'slot_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var list<string> $ids */
        $ids = array_map('strval', $validated['assignment_ids']);

        if (count(array_unique($ids)) !== count($ids)) {
            return response()->json(
                ['message' => 'The same order was selected more than once.'],
                422,
            );
        }

        $assignments = [];

        foreach ($ids as $id) {
            $assignments[] = $this->assignment($request, $id);
        }

        // Resolved against the first Order's Window. A selection spanning two Windows is
        // refused by the service, which is where that rule already lives.
        $slot = null;

        if (($validated['slot_id'] ?? null) !== null) {
            $slot = $this->slot($assignments[0]->window, (string) $validated['slot_id']);
        }

        try {
            $result = $this->manual->changeOrderSlotBatch(
                $assignments,
                $slot,
                $this->actorId($request),
                $validated['reason'] ?? null,
            );
        } catch (DistributionException $e) {
            // 422 with the server's own reason. Nothing moved — the whole operation ran
            // inside one transaction, so there is no partial success to report.
            return $this->rejected($e);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Manual Late-Order Assignment (§17).
     *
     * Pulls a late Order into this Window even though its cutoff has passed. The
     * Order stays inside Distribution — this is not a dispatch bypass.
     */
    public function assignLateOrder(Request $request, string $window): JsonResponse
    {
        $w = $this->window($request, $window);

        $validated = $request->validate([
            'order_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $assignment = $this->manual->assignLateOrder(
                $w,
                (string) $validated['order_id'],
                $this->actorId($request),
                $validated['reason'] ?? null,
            );
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        return response()->json(['data' => $assignment], 201);
    }

    // ── Tenant-scoped resolution ─────────────────────────────────────────────

    /**
     * The warehouse this read is scoped to, or null for the company-wide view.
     *
     * D-P5-1 made Distribution warehouse-scoped. The parameter is OPTIONAL because
     * every one of these endpoints has shipped without it: making it required
     * would break callers that are already certified. Omitting it keeps the
     * company-wide DATA exactly as before — but it can no longer produce a
     * company-wide CYCLE, because choosing one warehouse's wave to speak for all
     * of them is the guess D-P5-1 exists to end.
     */
    private function warehouseId(Request $request): ?string
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
        ]);

        return $validated['warehouse_id'] ?? null;
    }

    /**
     * The acting company, or a hard failure.
     *
     * Never returns null: a null company must not degrade into "see everything".
     */
    private function companyId(Request $request): string
    {
        $companyId = $request->user()?->company_id;

        if ($companyId === null || $companyId === '') {
            abort(403, 'No company scope for the acting user.');
        }

        return (string) $companyId;
    }

    /**
     * The Wave the ACTIVE board is scoped to, or null when it cannot be narrowed.
     *
     * ┌─ THE DATE IS DELIBERATELY NOT PASSED ────────────────────────────────────┐
     * │ This uses `governingPreparationWave()`, the same read-side resolver the    │
     * │ workspace header already uses. A preparation cycle SPANS MIDNIGHT — one    │
     * │ runs 17:30 on one day to 12:00 the next — so the live wave's              │
     * │ `planning_date` is yesterday's while it is still the wave that is running. │
     * │ Scoping by today's calendar date would find no wave every night after     │
     * │ midnight, exactly when the warehouse is working. The controller documents  │
     * │ this at `current()`, and this helper obeys the same rule.                  │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * NULL IS AN HONEST ANSWER, not a fallback. A Wave is per warehouse, so with no
     * warehouse selected the board spans several and there is no single Wave to scope to —
     * inventing one would be guessing which Wave is active. Null simply leaves the day's
     * Groups unnarrowed; closed Groups are already excluded either way, so an ended Wave's
     * work can never present itself as today's.
     */
    private function activeWaveId(string $companyId, ?string $warehouseId): ?string
    {
        if ($warehouseId === null) {
            return null;
        }

        $wave = $this->aggregation->governingPreparationWave($companyId, $warehouseId);

        return $wave['wave_id'] ?? null;
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (int) $id;
    }

    /**
     * Load a Window inside the tenant boundary.
     *
     * A Window belonging to another company is reported as 404, not 403: its
     * existence is not something a foreign tenant is entitled to learn.
     */
    private function window(Request $request, string $windowId): DistributionWindow
    {
        $companyId = $this->companyId($request);

        $window = DistributionWindow::query()
            ->where('id', $windowId)
            ->where('company_id', $companyId)
            ->first();

        if ($window === null) {
            abort(404);
        }

        return $window;
    }

    private function slot(DistributionWindow $window, string $slotId): VirtualCapacitySlot
    {
        $slot = VirtualCapacitySlot::query()
            ->where('id', $slotId)
            ->where('distribution_window_id', $window->id)
            ->first();

        if ($slot === null) {
            abort(404);
        }

        return $slot;
    }

    private function assignment(Request $request, string $assignmentId): DistributionWindowOrder
    {
        $companyId = $this->companyId($request);

        $assignment = DistributionWindowOrder::query()
            ->where('id', $assignmentId)
            ->where('company_id', $companyId)
            ->first();

        if ($assignment === null) {
            abort(404);
        }

        return $assignment;
    }

    /** @return array<string, mixed> */
    private function windowPayload(DistributionWindow $window): array
    {
        return [
            'id' => $window->id,
            'window_date' => $window->window_date->toDateString(),
            'opens_at' => $window->opens_at->toIso8601String(),
            'closes_at' => $window->closes_at->toIso8601String(),
            'status' => $window->status->value,
            'status_label' => $window->status->label(),
            'accepts_automatic_ingestion' => $window->status->acceptsAutomaticIngestion(),
            'accepts_manual_assignment' => $window->status->acceptsManualAssignment(),
            'next_window_id' => $window->next_window_id,
        ];
    }

    /** Render a domain rule violation as 422, matching the module's convention. */
    private function rejected(DistributionException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}
