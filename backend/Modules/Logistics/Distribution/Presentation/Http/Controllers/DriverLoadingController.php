<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use BackedEnum;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DeliveryService;
use Modules\Logistics\Distribution\Domain\Services\DistributionAggregationService;
use Modules\Logistics\Distribution\Domain\Services\GroupFinalizationService;
use Modules\Logistics\Distribution\Domain\Services\GroupLoadingContextService;
use Modules\Logistics\Distribution\Domain\Services\GroupPreparationService;
use Modules\Logistics\Distribution\Domain\Services\TripService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Operations\Loading\Application\Actions\LoadProductAction;
use Modules\Operations\Loading\Application\Actions\TransferLoadedStockToVehicleAction;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\LoadingTaskAdjustment;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Services\LoadingCustodyService;
use Modules\Operations\Loading\Domain\Services\StaleQuantityException;
use RuntimeException;

/**
 * TASK-DRIVER-WAVE-1-GROUP-LOADING-IMPLEMENTATION-001 (Option 1, owner-approved).
 *
 * The driver-facing Group-as-Shipment loading adapter. It owns NO business
 * logic and NO second engine: the manifest is the canonical Group read
 * (Required = DistributionAggregationService, Prepared = GroupPreparationService,
 * Loaded = the existing loading_tasks), and every write delegates to the existing
 * GroupLoadingContextService (open) + LoadProductAction (record) +
 * VehicleInventoryService (inventory, reached through LoadProductAction).
 *
 * It adds exactly two things, both required and both thin:
 *   (a) IDENTITY/OWNERSHIP — fail-closed, reusing the D-02 pattern: the driver is
 *       resolved from the token via logistics_drivers.user_id, the shipment is the
 *       driver's own active Trip's finalized Group, and everything is fenced to the
 *       actor's company.
 *   (b) GROUP GRAIN — it calls LoadProductAction with NULL pool_entry_id /
 *       preparation_wave_id and quantity_planned = the Group's live Required, so the
 *       driver never touches Pool/Preparation-Wave internals (Group = Shipment).
 *
 * Trip is an internal bridge only (vehicle_assignment.trip_id ← distribution_trips);
 * it is never the driver's business unit and is never mutated here.
 */
final class DriverLoadingController extends Controller
{
    public function __construct(
        private readonly TenantOwnershipResolver $tenant,
        private readonly DistributionAggregationService $aggregation,
        private readonly GroupPreparationService $groupPrep,
        private readonly GroupLoadingContextService $groupLoading,
        private readonly LoadProductAction $loadProduct,
        private readonly LoadingCustodyService $custody,
        private readonly DeliveryService $deliveries,
        private readonly TripService $trips,
        private readonly GroupFinalizationService $groupFinalization,
        private readonly TransferLoadedStockToVehicleAction $custodyTransfer,
    ) {}

    /** GET /api/driver/loading — the current shipment loading manifest (read-only). */
    public function show(): JsonResponse
    {
        $this->driver(); // 403 if the authenticated user is not a driver
        $trip = $this->currentTrip();

        return response()->json(['data' => $this->manifest($trip)]);
    }

    /**
     * POST /api/driver/loading/products/{productId} — record the ACTUAL loaded
     * quantity for one product of the shipment (Group grain).
     */
    public function loadProduct(Request $request, string $productId): JsonResponse
    {
        $data = $request->validate([
            'quantity_loaded' => ['required', 'numeric', 'min:0'],
        ]);

        $trip = $this->currentTripOrFail();
        $group = $this->ownedGroup($trip);
        $assignment = $this->openAssignment($group, $trip);

        $rows = $this->groupProductRows($group);
        $row = $rows[$productId] ?? null;
        abort_if($row === null, 422, 'This product is not part of your shipment.');

        try {
            // Group grain: no pool entry / preparation wave; planned = live Required.
            // Over-load (loaded > planned) is refused inside the action (422).
            $this->loadProduct->execute(
                assignment: $assignment,
                poolEntryId: null,
                productId: $productId,
                skuSnapshot: (string) ($row['product_sku'] ?? ''),
                nameSnapshot: (string) ($row['product_name'] ?? ''),
                preparationWaveId: null,
                quantityPlanned: (float) $row['total_quantity'],
                quantityLoaded: (float) $data['quantity_loaded'],
                loadedBy: (string) Auth::id(),
            );
        } catch (QueryException $e) {
            // A DATABASE fault is not a business rejection. `QueryException` extends
            // `PDOException` extends `RuntimeException`, so the catch below used to
            // swallow it, answer 422, and echo the raw SQL — table, column and
            // constraint names — to a mobile client (TASK-DRIVER-02). Rethrow so it is
            // logged and handled as the server fault it is, with no schema disclosure.
            throw $e;
        } catch (RuntimeException $e) {
            // Genuine business refusals only (e.g. over-load, correction guards).
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->markLoading($assignment);

        return response()->json(['data' => $this->manifest($trip)]);
    }

    /**
     * POST /api/driver/loading/complete — persist this shipment's loading completion.
     *
     * Completion is ASSIGNMENT-scoped (this driver's vehicle), NOT session-scoped:
     * a LoadingSession is shared per warehouse+day across several trips, so the
     * session-level CompleteLoadingAction would wrongly complete other drivers.
     * The vehicle_assignment carries its own LoadingComplete state + loading_completed_at.
     */
    /**
     * POST /api/driver/loading/products/{productId}/confirm
     *
     * The driver acknowledges what they physically received.
     *
     * IT DOES NOT MOVE `quantity_loaded`. The driver owns their own count and their own
     * confirmation; the warehouse's number is changed only by the warehouse. A driver
     * who disagrees uses `requestAdjustment()` instead.
     *
     * `expected_loaded_qty` is what the driver's screen showed. If the warehouse has
     * revised since, this refuses with 409 rather than confirming a number the driver
     * never saw.
     */
    public function confirmReceived(Request $request, string $productId): JsonResponse
    {
        $data = $request->validate([
            'received_qty' => ['required', 'numeric', 'min:0'],
            'expected_loaded_qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $trip = $this->currentTripOrFail();
        $task = $this->ownedTask($trip, $productId);

        try {
            // The driver confirmation and the warehouse→vehicle custody transfer are ONE
            // atomic step (TASK-DRIVER-CUSTODY-INVENTORY-TRANSFER-001). The confirmation is
            // persisted only once the canonical Stock Ledger movement succeeds: if the
            // transfer is refused — e.g. insufficient stock with allow_negative_stock=false
            // — the whole thing rolls back and the receipt is NOT falsely completed. The
            // confirmation locks the task row, so the lock is held across the transfer and
            // a concurrent confirm cannot double-deduct.
            DB::transaction(function () use ($task, $data): void {
                $confirmed = $this->custody->confirmReceived(
                    task: $task,
                    receivedQty: (float) $data['received_qty'],
                    expectedLoadedQty: isset($data['expected_loaded_qty']) ? (float) $data['expected_loaded_qty'] : null,
                    actorId: (string) Auth::id(),
                );

                $this->custodyTransfer->execute($confirmed);
            });
        } catch (QueryException $e) {
            throw $e;
        } catch (StaleQuantityException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->manifest($trip)]);
    }

    /**
     * POST /api/driver/loading/products/{productId}/adjustment
     *
     * The driver reports a different quantity and asks the warehouse to review.
     *
     * A REQUEST, NOT A CHANGE. `quantity_loaded` keeps its value until the warehouse
     * accepts, edits or rejects. Pressing twice returns the same open request rather
     * than opening a rival one.
     */
    public function requestAdjustment(Request $request, string $productId): JsonResponse
    {
        $data = $request->validate([
            'reported_qty' => ['required', 'numeric', 'min:0'],
            'expected_loaded_qty' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $trip = $this->currentTripOrFail();
        $task = $this->ownedTask($trip, $productId);

        try {
            $this->custody->requestAdjustment(
                task: $task,
                reportedQty: (float) $data['reported_qty'],
                expectedLoadedQty: isset($data['expected_loaded_qty']) ? (float) $data['expected_loaded_qty'] : null,
                reason: $data['reason'] ?? null,
                actorId: (string) Auth::id(),
            );
        } catch (QueryException $e) {
            throw $e;
        } catch (StaleQuantityException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->manifest($trip)]);
    }

    /**
     * The loading task for one product on THIS driver's own trip.
     *
     * Ownership is re-derived from the token every time (`currentTripOrFail` resolves
     * the driver, then their own active trip), so a product id from another driver's
     * shipment cannot be reached by guessing it.
     */
    private function ownedTask(Trip $trip, string $productId): LoadingTask
    {
        $assignment = VehicleAssignment::query()->where('trip_id', $trip->id)->first();

        abort_if($assignment === null, 422, 'Loading has not been started for this shipment yet.');

        $task = LoadingTask::query()
            ->where('vehicle_assignment_id', $assignment->id)
            ->where('product_id', $productId)
            ->first();

        abort_if($task === null, 404, 'This product is not on the shipment manifest.');

        return $task;
    }

    public function complete(): JsonResponse
    {
        $trip = $this->currentTripOrFail();
        $group = $this->ownedGroup($trip);
        $assignment = $this->openAssignment($group, $trip);

        $status = $assignment->status instanceof VehicleAssignmentStatus
            ? $assignment->status
            : VehicleAssignmentStatus::from((string) $assignment->status);

        // Move Pending → Loading first so the completion respects the existing
        // vehicle-assignment state machine (Pending → Loading → LoadingComplete).
        if ($status === VehicleAssignmentStatus::Pending) {
            $this->markLoading($assignment);
            $status = VehicleAssignmentStatus::Loading;
        }

        if ($status === VehicleAssignmentStatus::LoadingComplete) {
            return response()->json(['data' => $this->manifest($trip)]); // idempotent
        }

        abort_unless(
            $status === VehicleAssignmentStatus::Loading,
            422,
            "Loading cannot be completed from status '{$status->value}'.",
        );

        /*
         * CUSTODY GATE — TASK-LOADING-DRIVER-COMPLETE-GATE-001.
         *
         * ┌─ THE DEFECT THIS CLOSES ─────────────────────────────────────────────┐
         * │ Completion previously consulted only the VehicleAssignment status     │
         * │ machine, so a driver could finish loading while a product the         │
         * │ warehouse had loaded was still `awaiting_driver_confirmation` — the    │
         * │ shipment closed with custody nobody had acknowledged.                  │
         * └──────────────────────────────────────────────────────────────────────┘
         *
         * The answer comes from the ONE custody state machine, so this gate and the
         * manifest the driver is reading can never disagree. A disabled button is a
         * courtesy; THIS is the protection — the server refuses regardless of client.
         */
        $unresolved = $this->custody->unresolvedLoadedTasks((string) $assignment->id);

        if ($unresolved !== []) {
            return response()->json([
                'message' => 'Loading cannot be completed until all loaded items are confirmed by the driver.',
                // Machine-readable so the client can localise its own reason rather than
                // echoing an English sentence.
                'pending_confirmations' => count($unresolved),
            ], 422);
        }

        /*
         * CANONICAL COMPLETION → ORDERS BRIDGE — TASK-DRIVER-LOADING-COMPLETION-ORDERS-BRIDGE-001.
         *
         * Loading completion is the seam where a loaded shipment becomes deliverable
         * work. This method used to flip the VehicleAssignment to LoadingComplete and
         * stop, so the Group's orders never became distribution_trip_orders, no delivery
         * stops were written, and the driver's Orders page had nothing to read.
         *
         * The bridge completes the EXISTING approved lifecycle through EXISTING canonical
         * services only — no new order/stop logic, no direct inserts, no invented split:
         *
         *   1. VehicleAssignment → LoadingComplete
         *   2. GroupFinalizationService::finalize($group) — the canonical Group→Trip
         *      finalization. It materialises distribution_trip_orders (with the certified
         *      per-trip capacity split) and ADOPTS this trip, the one already carrying the
         *      vehicle, then seals it Planning → Loading.
         *   3. DeliveryService::generateStops($trip) — one delivery stop per trip order.
         *   4. TripService::changeStatus → LoadingCompleted — the existing Loading→… contract.
         *
         * ONE transaction, so a half-finalized shipment can never persist. Every step is
         * idempotent: finalize returns unchanged once the Group is finalized (a repeat
         * never double-assigns), generateStops skips existing (trip_id, order_id) rows,
         * and changeStatus no-ops when the trip is already LoadingCompleted. The outer
         * complete() also short-circuits once the assignment is LoadingComplete, so this
         * bridge runs at most once per shipment.
         */
        $actorId = ($id = Auth::id()) !== null && is_numeric($id) ? (int) $id : null;

        try {
            DB::transaction(function () use ($assignment, $group, $trip, $actorId): void {
                $assignment->update([
                    'status' => VehicleAssignmentStatus::LoadingComplete->value,
                    'loading_completed_at' => now(),
                    'updated_by' => (string) Auth::id(),
                ]);

                // Finalize the Group through the canonical service — THIS is what fills
                // distribution_trip_orders, respecting the existing capacity split. No
                // second finalization: it is idempotent for an already-finalized Group.
                $this->groupFinalization->finalize($group, $actorId);

                // finalize ran on its own Trip instance; re-read ours so its new
                // trip_orders and Loading status are visible to the steps below.
                $trip->refresh();

                // Materialise delivery stops from the now-populated trip_orders.
                $this->deliveries->generateStops($trip);

                // Advance the trip along the existing lifecycle. Guarded on the legal
                // source states so completion never couples to an unexpected trip state;
                // changeStatus no-ops if already there and refuses an illegal jump.
                if ($trip->status->canTransitionTo(TripStatus::LoadingCompleted)) {
                    $this->trips->changeStatus(
                        $trip,
                        TripStatus::LoadingCompleted,
                        reason: 'Driver completed loading.',
                        actor: (string) Auth::id(),
                    );
                }
            });
        } catch (DistributionException $e) {
            // A finalization prerequisite refused (no eligible orders, over-capacity
            // without approval, over-prepared). Surface it as a business refusal, not a
            // 500; the whole transaction — the LoadingComplete flip included — has rolled
            // back, so the shipment is left exactly as it was.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->manifest($trip)]);
    }

    // ── Manifest (canonical Group read; no new aggregation) ───────────────────

    /** @return array<string, mixed> */
    private function manifest(?Trip $trip): array
    {
        if ($trip === null) {
            return ['shipment' => null, 'items' => []];
        }

        $group = $this->ownedGroup($trip);
        $rows = $this->groupProductRows($group);
        $prepared = $this->groupPrep->preparedByProduct($group->id);

        $assignment = VehicleAssignment::query()->where('trip_id', $trip->id)->first();
        $loaded = [];
        $statusByProduct = [];
        /** @var array<string, LoadingTask> $taskByProduct */
        $taskByProduct = [];
        /** @var array<string, LoadingTaskAdjustment> $openByTask */
        $openByTask = [];

        if ($assignment !== null) {
            foreach (LoadingTask::query()->where('vehicle_assignment_id', $assignment->id)->get() as $task) {
                $loaded[(string) $task->product_id] = (float) $task->quantity_loaded;
                $taskByProduct[(string) $task->product_id] = $task;
                $statusByProduct[(string) $task->product_id] = (string) (
                    $task->status instanceof BackedEnum ? $task->status->value : $task->status
                );
            }

            if ($taskByProduct !== []) {
                // NOT `$rows` — that name already holds the Group's product rows above,
                // and reusing it here emptied the entire manifest the moment a task
                // existed. Named distinctly so the two can never collide again.
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

        $items = [];
        foreach ($rows as $productId => $row) {
            $required = (float) $row['total_quantity'];
            $load = (float) ($loaded[$productId] ?? 0.0);
            $task = $taskByProduct[$productId] ?? null;
            $open = $task === null ? null : ($openByTask[(string) $task->id] ?? null);

            $items[] = [
                'product_id' => $productId,
                'product_name' => $row['product_name'] ?? null,
                'quantity_required' => $required,
                'quantity_prepared' => (float) ($prepared[$productId] ?? 0.0),
                'quantity_loaded' => $load,
                'quantity_remaining' => max(0.0, $required - $load),
                'status' => $statusByProduct[$productId] ?? 'pending',

                'loading_task_id' => $task?->id,
                'warehouse_confirmed_at' => $task?->confirmed_at?->toIso8601String(),

                // The driver's OWN count. NULL means not yet counted — not a counted zero.
                'quantity_driver_received' => $task?->driver_received_qty,
                'driver_confirmed_at' => $task?->driver_confirmed_at?->toIso8601String(),

                // Signed on purpose: negative means the driver received LESS than the
                // warehouse recorded, which is the case that needs attention.
                'difference' => $task?->driver_received_qty === null
                    ? null
                    : round((float) $task->driver_received_qty - $load, 4),

                // DERIVED from the quantities and timestamps — never stored.
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
        }

        return [
            'shipment' => [
                'driver_name' => Auth::user()?->name,
                'orders_count' => DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->count(),
                'loading_complete' => $assignment !== null
                    && ($assignment->status instanceof VehicleAssignmentStatus
                        ? $assignment->status
                        : VehicleAssignmentStatus::from((string) $assignment->status)) === VehicleAssignmentStatus::LoadingComplete,
            ],
            'items' => $items,
        ];
    }

    /**
     * The Group's product rows keyed by product id — the canonical live aggregation
     * over the Group's orders (Required + product name/sku snapshots). Reused for the
     * manifest AND to source the snapshots a load record needs.
     *
     * @return array<string, array<string, mixed>>
     */
    private function groupProductRows(VirtualCapacitySlot $group): array
    {
        $out = [];
        foreach ($this->aggregation->productAggregation(
            $group->distribution_window_id,
            null,
            $group->id,
            $group->warehouse_id,
        ) as $row) {
            $out[(string) $row['product_id']] = $row;
        }

        return $out;
    }

    // ── Identity + ownership guards (fail closed, D-02) ───────────────────────

    private function driver(): Driver
    {
        $driver = Driver::query()->where('user_id', Auth::id())->first();
        abort_if($driver === null, 403, 'The authenticated user is not a driver.');

        return $driver;
    }

    /** The driver's own current shipment Trip (active, this company), or null. */
    private function currentTrip(): ?Trip
    {
        $driver = $this->driver();

        return Trip::query()
            ->where('company_id', $this->tenant->companyId())
            ->whereHas('driverVehicleAssignment', fn ($q) => $q->where('driver_id', $driver->id))
            ->whereNotIn('status', [TripStatus::Closed->value, TripStatus::Cancelled->value])
            ->orderByDesc('id')
            ->first();
    }

    private function currentTripOrFail(): Trip
    {
        $trip = $this->currentTrip();
        abort_if($trip === null, 404, 'You have no shipment assigned for loading.');

        return $trip;
    }

    /** The Trip's finalized Group, re-fenced to the actor's company (defence in depth). */
    private function ownedGroup(Trip $trip): VirtualCapacitySlot
    {
        /** @var VirtualCapacitySlot|null $group */
        $group = $trip->group;
        abort_if($group === null, 404, 'Your shipment has no group.');
        abort_unless(
            (string) $group->company_id === (string) $this->tenant->companyId(),
            403,
            'This shipment belongs to another company.',
        );

        return $group;
    }

    /** Resolve (idempotently opening) the vehicle assignment for the driver's shipment. */
    private function openAssignment(VirtualCapacitySlot $group, Trip $trip): VehicleAssignment
    {
        try {
            /** @var array{assignment: VehicleAssignment} $context */
            $context = $this->groupLoading->open($group, $trip, (string) Auth::id());
        } catch (QueryException $e) {
            // Same reasoning as loadProduct(): never reclassify a DB fault as a
            // readiness refusal, and never echo its message to the client.
            throw $e;
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return $context['assignment'];
    }

    private function markLoading(VehicleAssignment $assignment): void
    {
        $status = $assignment->status instanceof VehicleAssignmentStatus
            ? $assignment->status
            : VehicleAssignmentStatus::from((string) $assignment->status);

        if ($status === VehicleAssignmentStatus::Pending) {
            $assignment->update([
                'status' => VehicleAssignmentStatus::Loading->value,
                'loading_started_at' => $assignment->loading_started_at ?? now(),
                'updated_by' => (string) Auth::id(),
            ]);
        }
    }
}
