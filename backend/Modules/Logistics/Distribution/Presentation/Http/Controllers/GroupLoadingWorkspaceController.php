<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DistributionAggregationService;
use Modules\Logistics\Distribution\Domain\Services\DistributionWindowService;
use Modules\Logistics\Distribution\Domain\Services\GroupPreparationService;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\LoadingTaskAdjustment;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Services\LoadingCustodyService;
use Modules\Operations\Loading\Domain\Services\StaleQuantityException;
use RuntimeException;

/**
 * TASK-LOADING-GROUP-GRAIN-READ-AND-EXECUTION-UX-002 — the warehouse Loading read.
 *
 * ┌─ WHY THIS EXISTS AT ALL: PERMISSION, NOT BEHAVIOUR ──────────────────────┐
 * │ The Loading Workspace needs the canonical Group product manifest. That     │
 * │ manifest is already served by                                             │
 * │     GET /logistics/distribution/windows/{window}/products?slot_id=…       │
 * │ but that route carries `logistics.distribution.view`, which the live role  │
 * │ matrix shows is NOT held by Warehouse Operator, Warehouse Manager or       │
 * │ Preparation Supervisor — precisely the people who load. Granting them the  │
 * │ Distribution permission would hand them Distribution planning rights they  │
 * │ must not have.                                                            │
 * │                                                                          │
 * │ So this adapter exists for ONE reason: to expose the SAME canonical data   │
 * │ under `operations.preparation.view`, the permission those roles already    │
 * │ hold. It is a permission boundary, not a second implementation.           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO SECOND PROJECTION. Every figure is produced by the existing canonical
 * services, called — never copied:
 *
 *   Groups    DistributionAggregationService::slotSummaries()      (same call `slots()` makes)
 *   Required  DistributionAggregationService::productAggregation() (same call the products route makes)
 *   Prepared  GroupPreparationService::preparedByProduct()
 *   Loaded    Operations\Loading  loading_tasks.quantity_loaded    (the execution source of truth)
 *
 * No snapshot table, no projection store, no event, no listener, no cache. Required
 * stays live-derived, so adding or removing an order is reflected on the next read
 * with nothing to keep in step.
 *
 * ┌─ READ ONLY. THIS CONTROLLER WRITES NOTHING. ─────────────────────────────┐
 * │ No Loading Session, no Vehicle Assignment, no Trip, no Driver and no       │
 * │ Loading Task is created, located-or-created, or mutated here. Opening the  │
 * │ page — or a Group inside it — performs no write of any kind. Execution     │
 * │ continues to run through the existing, untouched write path.              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * PLACEMENT. It sits beside `DriverLoadingController`, the established thin-adapter
 * precedent, which likewise lives in Distribution while reading Operations\Loading
 * models. Distribution already depends on Loading; putting this in Loading instead
 * would make the two modules depend on each other. "Loading-side" is expressed by the
 * ROUTE and the PERMISSION, which is what actually solves the problem.
 */
final class GroupLoadingWorkspaceController extends Controller
{
    public function __construct(
        private readonly DistributionWindowService $windows,
        private readonly DistributionAggregationService $aggregation,
        private readonly GroupPreparationService $groupPrep,
        private readonly LoadingCustodyService $custody,
    ) {}

    /**
     * POST /api/loading/groups/{slot}/products/{product}/confirm
     *
     * The warehouse records and confirms what it physically loaded.
     *
     * WRITES ONLY THE WAREHOUSE HALF. The quantity itself goes through
     * `LoadProductAction` — the single writer — so the certified over-load ceiling and
     * absolute-set idempotency still apply untouched. A second identical post is a
     * no-op on the quantity and merely re-stamps the confirmation.
     */
    public function confirmProduct(Request $request, string $slot, string $product): JsonResponse
    {
        $companyId = $this->companyId($request);
        $group = $this->ownedGroup($slot, $companyId);

        $validated = $request->validate([
            'quantity_loaded' => ['required', 'numeric', 'min:0'],
            // What the operator's screen showed. Optional, because the warehouse is the
            // owner of this number — but supplied it makes a concurrent revision by a
            // second operator refuse rather than silently overwrite.
            'expected_loaded_qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $assignment = $this->assignmentForGroup($group);

        if ($assignment === null) {
            return response()->json([
                'message' => 'Loading has not been started for this group yet.',
            ], 422);
        }

        // Required is the canonical live figure, and it is the over-load ceiling at
        // group grain. It is read here, never sent by the client.
        $row = $this->requiredRowFor($group, $product);

        if ($row === null) {
            return response()->json([
                'message' => 'This product is not required by this group.',
            ], 422);
        }

        try {
            $this->custody->confirmLoaded(
                assignment: $assignment,
                productId: $product,
                skuSnapshot: (string) ($row['product_sku'] ?? ''),
                nameSnapshot: (string) ($row['product_name'] ?? ''),
                quantityPlanned: (float) $row['total_quantity'],
                quantityLoaded: (float) $validated['quantity_loaded'],
                actorId: (string) $request->user()?->id,
            );
        } catch (StaleQuantityException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->group($request, $slot);
    }

    /**
     * POST /api/loading/groups/{slot}/adjustments/{adjustment}/resolve
     *
     * The warehouse rules on a driver's request: accept, edit or reject.
     *
     * REJECT is not a no-op — it records the refusal and leaves the canonical quantity
     * untouched, which is the point: a warehouse that recounts and finds its original
     * figure correct can say so without altering a correct number.
     */
    public function resolveAdjustment(Request $request, string $slot, string $adjustment): JsonResponse
    {
        $companyId = $this->companyId($request);
        $group = $this->ownedGroup($slot, $companyId);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:accept,edit,reject'],
            'quantity_loaded' => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var LoadingTaskAdjustment|null $row */
        $row = LoadingTaskAdjustment::query()
            ->where('id', $adjustment)
            ->where('company_id', $companyId)
            ->first();

        if ($row === null) {
            abort(404);
        }

        // The request must belong to a task of THIS group — a foreign adjustment id
        // cannot be resolved by pairing it with a group the actor happens to own.
        if (! $this->adjustmentBelongsToGroup($row, $group)) {
            abort(404);
        }

        try {
            $this->custody->resolveAdjustment(
                adjustment: $row,
                action: (string) $validated['action'],
                revisedQuantity: isset($validated['quantity_loaded']) ? (float) $validated['quantity_loaded'] : null,
                actorId: (string) $request->user()?->id,
            );
        } catch (StaleQuantityException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->group($request, $slot);
    }

    /**
     * GET /api/loading/groups — the operative cycle's Groups.
     *
     * A Group appears here because it holds loading-eligible orders. Vehicle, Driver,
     * Trip and Loading Session are NOT consulted and are NOT preconditions.
     */
    public function groups(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $warehouseId = $this->warehouseId($request);

        // Resolved exactly as the Distribution board resolves it: the WAVE selects the
        // operative window. Re-deriving it from the calendar is the defect
        // resolvePlanningWindow() exists to prevent, so the same call is reused.
        $wave = $this->aggregation->governingPreparationWave($companyId, $warehouseId);
        $waveId = $wave['wave_id'] ?? null;

        $window = $this->windows->resolvePlanningWindow(
            $companyId,
            $waveId,
            $warehouseId,
            CarbonImmutable::now(),
        );

        if ($window === null) {
            // "No window" is a real answer, distinct from "no groups". Collapsing the
            // two would tell an operator to create Groups when no cycle is even open.
            return response()->json([
                'data' => ['resolution' => 'no_planning_window', 'window' => null, 'groups' => []],
            ]);
        }

        $groups = $this->aggregation->slotSummaries($window->id, $warehouseId, $waveId);

        /*
         * Transport for the whole list in TWO queries, not two per Group. The card needs
         * to say whether a Group can be executed yet, and issuing one request per Group
         * from the client would be N round trips for a fact the server can resolve in
         * one pass.
         */
        $slotIds = array_values(array_filter(array_map(
            static fn (array $g): string => (string) ($g['slot_id'] ?? ''),
            $groups,
        )));

        $tripBySlot = [];
        $assignedTripIds = [];

        if ($slotIds !== []) {
            $trips = Trip::query()
                ->whereIn('virtual_slot_id', $slotIds)
                ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle'])
                ->orderBy('id')
                ->get();

            foreach ($trips as $trip) {
                $key = (string) $trip->virtual_slot_id;

                // A Group may hold several Trips when Trip capacity forced a split; the
                // card reports the FIRST, which is the one the detail view opens on.
                if (! isset($tripBySlot[$key])) {
                    $tripBySlot[$key] = $trip;
                }
            }

            if ($trips->isNotEmpty()) {
                // The STATUS is carried, not just the existence: a screen that knows only
                // "an assignment exists" cannot tell loading-in-progress from
                // loading-complete, and would state one while the other is true.
                $assignedTripIds = VehicleAssignment::query()
                    ->whereIn('trip_id', $trips->pluck('id')->all())
                    ->get(['trip_id', 'status'])
                    ->mapWithKeys(static fn ($a): array => [
                        (string) $a->trip_id => (string) ($a->status instanceof \BackedEnum
                            ? $a->status->value
                            : $a->status),
                    ])
                    ->all();
            }
        }

        $groups = array_map(
            function (array $group) use ($tripBySlot, $assignedTripIds): array {
                $trip = $tripBySlot[(string) ($group['slot_id'] ?? '')] ?? null;

                return $group + [
                    'transport' => $this->presentTransport(
                        $trip,
                        $trip === null ? null : ($assignedTripIds[(string) $trip->id] ?? null),
                    ),
                ];
            },
            $groups,
        );

        return response()->json([
            'data' => [
                'resolution' => 'resolved',
                'window' => [
                    'id' => (string) $window->id,
                    'window_date' => (string) $window->window_date,
                ],
                'groups' => $groups,
            ],
        ]);
    }

    /**
     * Transport as the screen reads it. Every part may legitimately be null, and a null
     * is reported as a null — never replaced with a placeholder vehicle, driver or trip.
     *
     * Shared by the list and the detail so the card and the panel cannot describe the
     * same Group differently.
     *
     * @return array<string, mixed>
     */
    private function presentTransport(?Trip $trip, ?string $loadingAssignmentStatus): array
    {
        $pairing = $trip?->driverVehicleAssignment;

        return [
            'trip' => $trip === null ? null : [
                'trip_id' => (string) $trip->uuid,
                'trip_number' => (string) $trip->trip_number,
                'status' => (string) ($trip->status instanceof \BackedEnum
                    ? $trip->status->value
                    : $trip->status),
            ],
            'vehicle' => $pairing?->vehicle === null ? null : [
                'plate_number' => $pairing->vehicle->plate_number,
                'name' => $pairing->vehicle->name,
            ],
            'driver' => $pairing?->driver === null ? null : [
                'full_name' => $pairing->driver->full_name,
                'mobile' => $pairing->driver->mobile,
            ],
            // Whether an execution context exists. Reported, never created.
            'has_loading_assignment' => $loadingAssignmentStatus !== null,
            // WHICH state it is in. Without this the screen can only say "an assignment
            // exists" and would announce "Loading in progress" over a shipment that has
            // already completed. Read-only; no lifecycle is inferred or written here.
            'loading_assignment_status' => $loadingAssignmentStatus,
        ];
    }

    /**
     * GET /api/loading/groups/{slot} — one Group's loading manifest.
     *
     * Products render whether or not transport exists. The transport block is read
     * separately and is allowed to be null in every part.
     */
    public function group(Request $request, string $slot): JsonResponse
    {
        $companyId = $this->companyId($request);

        // Fenced to the acting company. A foreign Group 404s rather than returning an
        // empty manifest, so this cannot be used to probe which Groups exist.
        $group = VirtualCapacitySlot::query()
            ->where('id', $slot)
            ->where('company_id', $companyId)
            ->first();

        if ($group === null) {
            abort(404);
        }

        /*
         * TRANSPORT — READ, never required, never created.
         *
         * The Trip is the bridge to both the operator-visible pairing (vehicle+driver)
         * and the Loading-side VehicleAssignment that owns loaded quantities. All three
         * are legitimately absent for a Group that has not been assigned, and every one
         * of them is reported as null rather than being materialised.
         */
        $trip = Trip::query()
            ->where('virtual_slot_id', $group->id)
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle'])
            ->orderBy('id')
            ->first();

        $assignment = $trip === null
            ? null
            : VehicleAssignment::query()->where('trip_id', $trip->id)->first();

        /*
         * LOADED — the execution source of truth: loading_tasks.quantity_loaded, keyed
         * by (vehicle_assignment, product). This is the SAME source the driver manifest
         * reads, so the two screens cannot report different loaded quantities.
         *
         * ┌─ PREPARED IS NOT LOADED ────────────────────────────────────────────┐
         * │ Prepared means the warehouse separated the stock. Loaded means it     │
         * │ physically went onto the vehicle. They are different facts recorded   │
         * │ by different acts, and Loaded is NEVER derived from Prepared.         │
         * │                                                                      │
         * │ With no vehicle assignment there are no loading_tasks rows, so Loaded │
         * │ is 0.0 — an honest "loading has not started", not a copy of Prepared. │
         * └──────────────────────────────────────────────────────────────────────┘
         *
         * @var array<string, float> $loaded
         * @var array<string, string> $loadingStatus
         */
        /*
         * The whole task is kept, not just its quantity: the manifest now also carries
         * the warehouse confirmation, the driver's own count and confirmation, and the
         * derived workflow state — all of which live on the same row.
         *
         * @var array<string, LoadingTask> $taskByProduct
         * @var array<string, LoadingTaskAdjustment> $openByTask
         */
        $taskByProduct = [];
        $openByTask = [];

        if ($assignment !== null) {
            foreach (LoadingTask::query()->where('vehicle_assignment_id', $assignment->id)->get() as $task) {
                $taskByProduct[(string) $task->product_id] = $task;
            }

            if ($taskByProduct !== []) {
                // One query for the whole manifest's open requests rather than one per
                // product — the warehouse review queue is a list, not a lookup.
                $openRows = LoadingTaskAdjustment::query()
                    ->whereIn('loading_task_id', array_map(
                        static fn (LoadingTask $t): string => (string) $t->id,
                        array_values($taskByProduct),
                    ))
                    ->where('status', LoadingTaskAdjustment::STATUS_OPEN)
                    ->get();

                foreach ($openRows as $openRow) {
                    $openByTask[(string) $openRow->loading_task_id] = $openRow;
                }
            }
        }

        $prepared = $this->groupPrep->preparedByProduct($group->id);

        $products = [];
        $totals = ['required' => 0.0, 'prepared' => 0.0, 'loaded' => 0.0, 'remaining' => 0.0, 'over_prepared' => 0.0];

        foreach ($this->aggregation->productAggregation(
            $group->distribution_window_id,
            null,
            $group->id,
            $group->warehouse_id,
        ) as $row) {
            $productId = (string) $row['product_id'];

            $task = $taskByProduct[$productId] ?? null;
            $open = $task === null ? null : ($openByTask[(string) $task->id] ?? null);

            $required = (float) $row['total_quantity'];
            $prep = (float) ($prepared[$productId] ?? 0.0);
            $load = $task === null ? 0.0 : (float) $task->quantity_loaded;

            /*
             * REMAINING IS REMAINING-TO-LOAD.
             *
             * The preparation projection defines `remaining_qty` as Required − PREPARED,
             * which answers "how much is still to separate". This screen answers a
             * different question — "how much is still to put on the vehicle" — so
             * Remaining here is Required − LOADED, matching the driver manifest exactly.
             * The two are deliberately not the same number and are never mixed.
             */
            $remaining = max(0.0, round($required - $load, 4));

            // Same formula the canonical preparation projection uses: Required can FALL
            // after the floor has already separated stock (an order left the Group, was
            // cancelled or postponed), and Remaining being floored at zero would
            // otherwise hide a pallet that now holds more than the Group needs.
            $overPrepared = max(0.0, round($prep - $required, 4));

            $products[] = [
                'product_id' => $productId,
                'product_name' => $row['product_name'] ?? null,
                'product_sku' => $row['product_sku'] ?? null,
                'unit_code' => $row['unit_code'] ?? null,
                'unit_symbol' => $row['unit_symbol'] ?? null,
                'quantity_required' => $required,
                'quantity_prepared' => $prep,
                'quantity_loaded' => $load,
                'quantity_remaining' => $remaining,
                'over_prepared_qty' => $overPrepared,
                // The canonical loading_tasks status when a load has been recorded, and
                // null when none has. Null is not "pending" — it means no execution row
                // exists yet, which is a different fact.
                'loading_status' => $task === null
                    ? null
                    : (string) ($task->status instanceof \BackedEnum ? $task->status->value : $task->status),

                'loading_task_id' => $task?->id,

                // WAREHOUSE confirmation — who stood behind this number, and when.
                'warehouse_confirmed_at' => $task?->confirmed_at?->toIso8601String(),
                'warehouse_confirmed_by' => $task?->confirmed_by,

                // DRIVER receipt — a different fact by a different actor. NULL means the
                // driver has not counted it yet, which is NOT a counted zero.
                'quantity_driver_received' => $task?->driver_received_qty,
                'driver_confirmed_at' => $task?->driver_confirmed_at?->toIso8601String(),
                'driver_confirmed_by' => $task?->driver_confirmed_by,

                // DERIVED, never stored — see LoadingCustodyService::stateOf().
                'workflow_state' => $task === null
                    ? LoadingCustodyService::STATE_PENDING_LOADING
                    : $this->custody->stateOf($task, $open),

                'open_adjustment' => $open === null ? null : [
                    'id' => (string) $open->id,
                    'driver_reported_qty' => (float) $open->driver_reported_qty,
                    'quantity_before' => (float) $open->quantity_before,
                    'reason' => $open->reason,
                    'requested_at' => $open->recorded_at?->toIso8601String(),
                ],
            ];

            $totals['required'] += $required;
            $totals['prepared'] += $prep;
            $totals['loaded'] += $load;
            $totals['remaining'] += $remaining;
            $totals['over_prepared'] += $overPrepared;
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, 4);
        }

        return response()->json([
            'data' => [
                'group' => [
                    'slot_id' => (string) $group->id,
                    'code' => (string) $group->code,
                    'name' => $group->name,
                    'warehouse_id' => (string) $group->warehouse_id,
                    'window_id' => (string) $group->distribution_window_id,
                ],
                'transport' => $this->presentTransport(
                    $trip,
                    $assignment === null ? null : (string) ($assignment->status instanceof \BackedEnum
                        ? $assignment->status->value
                        : $assignment->status),
                ),
                'totals' => $totals,
                'products' => $products,
            ],
        ]);
    }

    /** The Group, fenced to the acting company. A foreign uuid 404s. */
    private function ownedGroup(string $slot, string $companyId): VirtualCapacitySlot
    {
        $group = VirtualCapacitySlot::query()
            ->where('id', $slot)
            ->where('company_id', $companyId)
            ->first();

        if ($group === null) {
            abort(404);
        }

        return $group;
    }

    /** The Loading-side assignment for this Group's Trip, or null if loading never started. */
    private function assignmentForGroup(VirtualCapacitySlot $group): ?VehicleAssignment
    {
        $trip = Trip::query()
            ->where('virtual_slot_id', $group->id)
            ->orderBy('id')
            ->first();

        if ($trip === null) {
            return null;
        }

        return VehicleAssignment::query()->where('trip_id', $trip->id)->first();
    }

    /**
     * One canonical Required row for a product in this Group.
     *
     * Read server-side so the over-load ceiling can never be set by the client.
     *
     * @return array<string, mixed>|null
     */
    private function requiredRowFor(VirtualCapacitySlot $group, string $productId): ?array
    {
        foreach ($this->aggregation->productAggregation(
            $group->distribution_window_id,
            null,
            $group->id,
            $group->warehouse_id,
        ) as $row) {
            if ((string) $row['product_id'] === $productId) {
                return $row;
            }
        }

        return null;
    }

    private function adjustmentBelongsToGroup(LoadingTaskAdjustment $row, VirtualCapacitySlot $group): bool
    {
        $assignment = $this->assignmentForGroup($group);

        if ($assignment === null) {
            return false;
        }

        return LoadingTask::query()
            ->whereKey($row->loading_task_id)
            ->where('vehicle_assignment_id', $assignment->id)
            ->exists();
    }

    private function companyId(Request $request): string
    {
        $companyId = $request->user()?->company_id;

        if ($companyId === null || $companyId === '') {
            abort(403, 'No company scope for the acting user.');
        }

        return (string) $companyId;
    }

    private function warehouseId(Request $request): ?string
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
        ]);

        return $validated['warehouse_id'] ?? null;
    }
}
