<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Commerce\Orders\Application\Actions\ChangeOrderPaymentMethodAction;
use Modules\Commerce\Orders\Application\Actions\UploadPaymentProofAction;
use Modules\Commerce\Orders\Domain\Exceptions\PaymentMethodChangeRejectedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Delivery\Domain\Enums\FailureReason;
use Modules\Logistics\Distribution\Application\Actions\EnsureStopDeliveryAllocationsAction;
use Modules\Logistics\Distribution\Application\Actions\UploadDeliveryProofAction;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Services\DeliveryService;
use Modules\Logistics\Distribution\Domain\Services\TripService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Operations\Loading\Application\Actions\RecordProductDeliveryAction;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Services\LoadingCustodyService;
use Modules\Operations\Loading\Presentation\Http\Resources\VehicleInventoryItemResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-SHIPPING-DRIVER-CLOSURE-001 §G10 — Driver runtime (thin delegating wrapper).
 *
 * The driver-facing counterpart to the dispatcher Distribution stack. It does NOT
 * own a parallel backend (Section 5): every write delegates to the canonical
 * DeliveryService / TripService, and every read hydrates the canonical Trip /
 * DeliveryStop / Order models. What it owns is (a) IDENTITY — resolving the logged-in
 * driver from the token via logistics_drivers.user_id — and (b) AUTHORIZATION — because
 * it bypasses the dispatcher route middleware, it fail-closed enforces that every
 * trip/stop is BOTH the actor's company AND the resolved driver's own, itself.
 *
 * Financial Settlement is FROZEN (Section 17): the four money endpoints are registered
 * but return 403 and touch no SettlementService. Delivery outcome lives on
 * DeliveryStop.status; this controller never writes Order.status (that is guarded by
 * OrderStatusGuard and must route through the Fulfillment engine — Section 12).
 */
final class DriverRuntimeController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly TripService $trips,
        private readonly TenantOwnershipResolver $tenant,
        private readonly LoadingCustodyService $custody,
    ) {}

    // ── Trips ────────────────────────────────────────────────────────────────

    /** Today's assigned work — active trips for THIS driver. Bare array (frontend contract). */
    public function trips(): JsonResponse
    {
        $driver = $this->driver();
        $companyId = $this->tenant->companyId();

        $trips = Trip::query()
            ->where('company_id', $companyId)
            ->whereHas('driverVehicleAssignment', fn ($q) => $q->where('driver_id', $driver->id))
            ->whereNotIn('status', [TripStatus::Closed->value, TripStatus::Cancelled->value])
            // Eager-load the pairing's vehicle so tripSummary can surface the plate
            // without an N+1 — the vehicle identity the driver header reads.
            ->with('driverVehicleAssignment.vehicle')
            ->withCount(['stops', 'exceptions'])
            ->orderByDesc('id')
            ->get();

        return response()->json($trips->map(fn (Trip $t) => $this->tripSummary($t))->all());
    }

    public function trip(string $tripId): JsonResponse
    {
        return response()->json($this->tripSummary($this->ownedTrip($tripId)));
    }

    public function startTrip(Request $request, string $tripId): JsonResponse
    {
        $trip = $this->ownedTrip($tripId);
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'odo_start' => ['nullable', 'numeric', 'min:0'],
        ]);

        /*
         * TASK-TRIP-LIFECYCLE-AND-VEHICLE-CUSTODY-BRIDGE-001 — the departure seam.
         *
         * ┌─ THE GAP THIS CLOSES ────────────────────────────────────────────────┐
         * │ Loading completion leaves the trip at LoadingCompleted. From there    │
         * │ `InProgress` is NOT an allowed transition — the table routes through   │
         * │ DriverAccepted → ReadyForDispatch → Dispatched — and nothing in the    │
         * │ operational flow ever walked those three. So this call always threw,   │
         * │ `dispatched_at` and `trip_started_at` stayed NULL, and Day Settlement's │
         * │ `DATE(COALESCE(trip_started_at, dispatched_at, created_at))` degraded  │
         * │ to the row's creation date — the trip's work vanished from its day.    │
         * └──────────────────────────────────────────────────────────────────────┘
         *
         * The whole walk runs in ONE transaction, and the odometer/GPS stamp happens
         * only AFTER the lifecycle accepts the departure. Previously the stamp was
         * written first, so a refused transition left a trip that had "started" but
         * never left — a state no later step could reconcile.
         */
        try {
            DB::transaction(function () use ($trip, $data): void {
                $this->advanceToDispatched($trip);

                $trip->update(array_filter([
                    'trip_started_at' => now(),
                    'trip_start_lat' => $data['lat'] ?? null,
                    'trip_start_lng' => $data['lng'] ?? null,
                    'odometer_start' => $data['odo_start'] ?? null,
                ], static fn ($v) => $v !== null));

                // Guarded so a re-post by a driver already on the road is a no-op
                // rather than an illegal-transition error.
                if ($trip->status->canTransitionTo(TripStatus::InProgress)) {
                    $this->trips->changeStatus($trip, TripStatus::InProgress, 'Driver started the trip.', (string) Auth::id());
                }
            });
        } catch (DistributionException $e) {
            // A dispatch blocker refused (no orders, inactive assignment, unfit driver
            // or vehicle, unconfirmed custody). The transaction rolled back, so the trip
            // is exactly as it was — not half-departed.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['trip' => $this->tripSummary($trip->refresh())]);
    }

    /**
     * Carry a loaded trip through the EXISTING lifecycle to Dispatched.
     *
     * Every step here is an existing canonical service — `recordDriverAcceptance()`
     * and `changeStatus()` on TripService. Nothing new is invented, no status is
     * force-filled, and `dispatchBlockers()` still has the final word: if the trip
     * has no orders or the assignment/driver/vehicle is unfit, this throws and the
     * driver is told why.
     *
     * THE ACCEPTANCE IS RECORDED, NOT FABRICATED. The actor is the authenticated
     * driver, who is performing the departure; the three flags are read back from
     * facts that driver already established — the per-product custody confirmations
     * behind the Loading Complete gate, and the equipment items they signed for.
     * A product still awaiting confirmation, or an unsigned equipment item, makes
     * the corresponding flag FALSE, which blocks dispatch instead of papering over it.
     */
    private function advanceToDispatched(Trip $trip): void
    {
        if ($trip->status !== TripStatus::LoadingCompleted) {
            return; // Already past the seam (or never reached it) — idempotent.
        }

        $productsConfirmed = $this->allLoadedProductsConfirmed($trip);
        $actorId = ($id = Auth::id()) !== null && is_numeric($id) ? (int) $id : null;

        $this->trips->recordDriverAcceptance(
            $trip,
            products: $productsConfirmed,
            // Product custody IS the loading confirmation: the driver acknowledged the
            // quantity of every item the warehouse loaded onto this vehicle.
            custody: $productsConfirmed,
            equipment: $this->allEquipmentConfirmed($trip),
            actorId: $actorId,
        );

        foreach ([TripStatus::DriverAccepted, TripStatus::ReadyForDispatch, TripStatus::Dispatched] as $step) {
            $this->trips->changeStatus($trip, $step, 'Driver took custody and departed.', (string) Auth::id());
        }
    }

    /**
     * TRUE when no product the warehouse loaded is still awaiting this driver's word.
     *
     * Re-asked here rather than trusted from completion time: the warehouse can revise
     * a loaded quantity after the driver completed loading, which makes an earlier
     * confirmation stale. The answer comes from the ONE custody state machine, so this
     * and the driver's manifest can never disagree.
     */
    private function allLoadedProductsConfirmed(Trip $trip): bool
    {
        foreach (VehicleAssignment::query()->where('trip_id', $trip->id)->pluck('id') as $assignmentId) {
            if ($this->custody->unresolvedLoadedTasks((string) $assignmentId) !== []) {
                return false;
            }
        }

        return true;
    }

    /** TRUE when every equipment/cash item handed out is signed for — vacuously so when none was. */
    private function allEquipmentConfirmed(Trip $trip): bool
    {
        return ! $trip->custodyItems()->where('is_driver_confirmed', false)->exists();
    }

    public function finishTrip(Request $request, string $tripId): JsonResponse
    {
        $trip = $this->ownedTrip($tripId);
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'odo_end' => ['nullable', 'numeric', 'min:0'],
        ]);

        $trip->update(array_filter([
            'trip_finished_at' => now(),
            'trip_finish_lat' => $data['lat'] ?? null,
            'trip_finish_lng' => $data['lng'] ?? null,
            'odometer_end' => $data['odo_end'] ?? null,
        ], static fn ($v) => $v !== null));

        $this->trips->changeStatus($trip, TripStatus::Completed, 'Driver finished the trip.', (string) Auth::id());

        return response()->json(['trip' => $this->tripSummary($trip->refresh())]);
    }

    public function closeTrip(string $tripId): JsonResponse
    {
        $trip = $this->ownedTrip($tripId);
        $this->trips->changeStatus($trip, TripStatus::Closed, 'Driver closed the trip.', (string) Auth::id());

        return response()->json(['trip' => $this->tripSummary($trip->refresh())]);
    }

    /**
     * GPS breadcrumb. There is no canonical per-trip breadcrumb store in Distribution
     * (Section §5c of the design), so this accepts and validates the fix but does not
     * persist it — a real breadcrumb table is a separate contract. 204, no body.
     */
    public function gps(Request $request, string $tripId): JsonResponse
    {
        $this->ownedTrip($tripId); // ownership + validation only
        $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
        ]);

        return response()->json(null, 204);
    }

    // ── Stops ────────────────────────────────────────────────────────────────

    public function stops(string $tripId): JsonResponse
    {
        $trip = $this->ownedTrip($tripId);
        $stops = $trip->stops()->orderBy('sequence')->get();
        // Share the owning trip onto each stop so the PII privacy stage resolves from the
        // already-loaded trip status without an N+1 per stop.
        $stops->each(fn (DeliveryStop $s) => $s->setRelation('trip', $trip));

        return response()->json($stops->map(fn (DeliveryStop $s) => $this->stopSummary($s))->all());
    }

    public function stop(string $tripId, string $stopId): JsonResponse
    {
        $trip = $this->ownedTrip($tripId);
        $stop = $this->stopWithinTrip($trip, $stopId);
        $stop->setRelation('trip', $trip); // feed the PII privacy stage the owning trip status

        return response()->json($this->stopDetail($stop));
    }

    /**
     * TASK-DRIVER-WAVE-2 (Started Delivery, audit §10) — mark this stop as started.
     *
     * Delegates to the existing DeliveryService::startStop: it sets the STOP to
     * InProgress and stamps attempted_at, refuses an already-settled stop, and
     * NEVER writes Order.status (that stays with the Fulfillment engine, exactly
     * as the rest of this controller). Fail-closed ownership via ownedStop().
     */
    public function startDelivery(string $stopId): JsonResponse
    {
        $stop = $this->ownedStop($stopId);

        try {
            $this->deliveries->startStop($stop, (string) Auth::id());
        } catch (DistributionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['stop' => $this->stopSummary($stop->refresh())]);
    }

    /**
     * Record the delivery outcome for a stop. Delegates to DeliveryService
     * (recordAction + completeStop). Payment fields are STRIPPED — money collection is
     * frozen — so only status + notes + delivery GPS reach the domain. Partial delivery
     * (Section 8) is supported via action_type=partial → DeliveryStopStatus::Partial.
     */
    public function stopAction(Request $request, string $stopId): JsonResponse
    {
        $stop = $this->ownedStop($stopId);

        $data = $request->validate([
            'action_type' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'new_delivery_date' => ['nullable', 'date'],
            'corrected_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'corrected_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $actorId = Auth::id();

        // Always record the driver's action (audit trail), money-free.
        $this->deliveries->recordAction($stop, [
            'action_type' => $data['action_type'],
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'new_delivery_date' => $data['new_delivery_date'] ?? null,
            'corrected_lat' => $data['corrected_lat'] ?? null,
            'corrected_lng' => $data['corrected_lng'] ?? null,
        ], $actorId !== null ? (int) $actorId : null);

        // A settling action also closes the stop with the mapped outcome.
        $outcome = $this->outcomeFor($data['action_type']);
        if ($outcome !== null) {
            $this->deliveries->completeStop(
                $stop,
                $outcome,
                array_filter([
                    'notes' => $data['notes'] ?? null,
                    'gps_lat' => $data['corrected_lat'] ?? null,
                    'gps_lng' => $data['corrected_lng'] ?? null,
                ], static fn ($v) => $v !== null),
                $actorId !== null ? (string) $actorId : null,
            );
        }

        return response()->json(['stop' => $this->stopSummary($stop->refresh())]);
    }

    /**
     * TASK-DRIVER-DELIVERY-ALLOCATION-BRIDGE-001 — the driver records the ACTUAL delivered
     * quantity for this stop through the SAME canonical writer the operator uses:
     *   ensure allocations (EnsureStopDeliveryAllocationsAction, the bridge)
     *   → RecordProductDeliveryAction (the SOLE delivered-quantity writer)
     *   → allocation_records.quantity_delivered
     *   → order_lines.delivered_qty (existing projection)
     *   → vehicle-custody `delivered` movement (existing VehicleInventoryService).
     *
     * NO second delivery writer. NO warehouse deduction — that already happened once, at the
     * Warehouse→Vehicle confirm-received transfer; customer delivery only lowers vehicle custody.
     *
     * CUMULATIVE ABSOLUTE semantics: each line's `delivered_qty` is the TOTAL delivered so far
     * (RecordProductDeliveryAction is an absolute set), never an increment — so a retry with the
     * same total is a no-op, and the endpoint refuses a value BELOW what is already delivered.
     * Delivery is refused unless the trip is on the road, and is guarded so it can never exceed
     * the vehicle's on-hand custody (no negative custody). Fail-closed via ownedStop(); the whole
     * stop's lines commit in one transaction.
     */
    public function deliver(
        Request $request,
        string $stopId,
        EnsureStopDeliveryAllocationsAction $ensure,
        RecordProductDeliveryAction $record,
    ): JsonResponse {
        $stop = $this->ownedStop($stopId);

        if (! $stop->trip->status->acceptsDeliveryExecution()) {
            return response()->json(
                ['message' => 'Delivery can only be recorded once the trip is on the road.'],
                422,
            );
        }

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_line_id' => ['required', 'string'],
            'lines.*.delivered_qty' => ['required', 'numeric', 'min:0'],
        ]);

        $actorId = Auth::id();
        $actor = $actorId !== null ? (string) $actorId : 'system';
        $epsilon = 0.00005;

        try {
            DB::transaction(function () use ($stop, $data, $ensure, $record, $actor, $epsilon): void {
                $allocations = $ensure->execute($stop, $actor);

                foreach ($data['lines'] as $entry) {
                    $lineId = (string) $entry['order_line_id'];
                    $cumulative = (float) $entry['delivered_qty'];

                    $allocation = $allocations[$lineId] ?? null;
                    if ($allocation === null) {
                        abort(422, "Order line {$lineId} is not deliverable from this vehicle (no custody / allocation).");
                    }

                    $current = (float) $allocation->quantity_delivered;

                    // Idempotent no-op: the same cumulative total was already recorded.
                    if (abs($cumulative - $current) < $epsilon) {
                        continue;
                    }
                    // Cumulative can only advance — never reduce a delivered total here.
                    if ($cumulative < $current) {
                        abort(422, "Delivered quantity is cumulative and cannot be reduced (line {$lineId}).");
                    }

                    // Guard the DELTA against LIVE vehicle custody so delivery can never exceed
                    // what is physically on the vehicle (no silent negative custody — §5).
                    $delta = $cumulative - $current;
                    $custody = $allocation->vehicle_inventory_item_id !== null
                        ? VehicleInventoryItem::query()->find($allocation->vehicle_inventory_item_id)
                        : null;
                    $onHand = $custody !== null ? (float) $custody->quantity_on_hand : 0.0;
                    if ($delta - $onHand > $epsilon) {
                        abort(422, "Delivered quantity exceeds the vehicle's on-hand custody (line {$lineId}).");
                    }

                    // The SOLE canonical delivered-quantity writer. Absolute set; over-delivery
                    // beyond the allocated (ordered) quantity fails closed inside the action.
                    $record->execute($allocation, $cumulative, $actor, 'driver');
                }

                // Settle the stop as Delivered ONLY when every line is fully delivered. A stop
                // with any remaining quantity is NOT auto-completed (§10).
                $this->settleStopIfFullyDelivered($stop, $actor);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['stop' => $this->stopDetail($stop->refresh())]);
    }

    /**
     * Marks the stop Delivered only when every order line is fully delivered (remaining 0).
     * Re-reads the order lines AFTER the delivered_qty projection has run in this transaction,
     * so fullness is judged from the canonical projected quantities. No-op if the stop is
     * already settled (keeps a repeat delivery idempotent) or the order has no lines.
     */
    private function settleStopIfFullyDelivered(DeliveryStop $stop, string $actor): void
    {
        if ($stop->isSettled()) {
            return;
        }

        $order = Order::query()->with('lines')->where('id', $stop->order_id)->first();
        if ($order === null || $order->lines->isEmpty()) {
            return;
        }

        $allFull = $order->lines->every(function ($line): bool {
            $remaining = (float) $line->quantity
                - (float) ($line->delivered_qty ?? 0)
                - (float) ($line->returned_qty ?? 0)
                - (float) ($line->cancelled_qty ?? 0);

            return $remaining <= 0.00005;
        });

        if ($allFull) {
            $this->deliveries->completeStop($stop, DeliveryStopStatus::Delivered, [], $actor);
        }
    }

    /**
     * LEGACY / UNSAFE — kept only for the shared dispatcher contract; NOT wired to the
     * driver UI (de-exposed in TASK-DRIVER-04 Part C). It accepts client-supplied path
     * STRINGS as "proof" and is superseded for the driver by uploadDeliveryProof() below.
     *
     * FOLLOW-UP — SYSTEMIC DELIVERY POD SECURITY: the dispatcher endpoint that shares
     * DeliveryService::captureProof (and this route) must later be migrated to the secure
     * multipart contract too. It is deliberately NOT treated as secure in the meantime.
     */
    public function proof(Request $request, string $stopId): JsonResponse
    {
        $stop = $this->ownedStop($stopId);
        $data = $request->validate([
            'signature_path' => ['nullable', 'string', 'max:500'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $actorId = Auth::id();
        $this->deliveries->captureProof($stop, $data, $actorId !== null ? (int) $actorId : null);

        return response()->json(null, 204);
    }

    /**
     * TASK-DELIVERY-POD-SECURE-UPLOAD-001 — the SECURE delivery proof-of-delivery upload
     * for the driver. Unlike the legacy proof() above, this ingests REAL multipart files
     * (a signature and/or photos), validated server-side for type and size, and stored on
     * a PRIVATE disk under a server-generated path via UploadDeliveryProofAction — never a
     * client-supplied path. Empty proof (no signature and no photo) is refused. It records
     * only proof evidence: no payment, inventory, custody or delivery-quantity behaviour.
     * Fail-closed via ownedStop() (company + driver ownership re-asserted).
     */
    public function uploadDeliveryProof(Request $request, string $stopId, UploadDeliveryProofAction $action): JsonResponse
    {
        $stop = $this->ownedStop($stopId);

        $request->validate([
            'signature' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // A proof with no evidence is not a proof — the empty-POST hole the legacy
        // contract left open is closed here.
        if (! $request->hasFile('signature') && ! $request->hasFile('photos')) {
            abort(422, 'A delivery proof must include a signature or at least one photo.');
        }

        $actorId = Auth::id();
        $proof = $action->execute(
            $stop,
            $request->file('signature'),
            array_values($request->file('photos') ?? []),
            $request->input('notes'),
            $actorId !== null ? (int) $actorId : null,
        );

        return response()->json([
            'data' => [
                'id' => $proof->id,
                'has_signature' => $proof->hasSignature(),
                'photo_count' => $proof->photoCount(),
                'captured_at' => optional($proof->captured_at)->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * TASK-DELIVERY-POD-SECURE-UPLOAD-001 — tenant-scoped retrieval of a stored POD
     * artifact. Streams ONLY a path RECORDED on this stop's own proof (never a
     * client-supplied path), from the private disk. Fail-closed via ownedStop().
     * `kind` is route-constrained to signature|photo; `index` selects a photo.
     */
    public function downloadDeliveryProof(string $stopId, string $kind, ?int $index = null): StreamedResponse
    {
        $stop = $this->ownedStop($stopId);
        $proof = $stop->proof;

        if ($proof === null) {
            abort(404, 'No delivery proof for this stop.');
        }

        if ($kind === 'signature') {
            $disk = (string) $proof->storage_disk;
            $path = (string) $proof->signature_path;
        } else { // 'photo'
            $photos = $proof->photos ?? [];
            $entry = ($index !== null) ? ($photos[$index] ?? null) : null;
            if ($entry === null) {
                abort(404, 'No such delivery proof photo.');
            }
            $disk = (string) ($entry['disk'] ?? '');
            $path = (string) ($entry['path'] ?? '');
        }

        if ($disk === '' || $path === '' || ! Storage::disk($disk)->exists($path)) {
            abort(404, 'Delivery proof artifact not found.');
        }

        return Storage::disk($disk)->download($path);
    }

    public function raiseException(Request $request, string $stopId): JsonResponse
    {
        $stop = $this->ownedStop($stopId);
        $data = $request->validate([
            'exception_type' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'photos' => ['nullable', 'array'],
        ]);

        $actorId = Auth::id();
        $exception = $this->deliveries->raiseException($stop->trip, [
            'delivery_stop_id' => $stop->id,
            'exception_type' => $data['exception_type'],
            'description' => $data['description'],
        ], $actorId !== null ? (int) $actorId : null);

        return response()->json(['exception' => $exception], 201);
    }

    // ── Exceptions / returns (read + operational write) ──────────────────────

    public function exceptions(string $tripId): JsonResponse
    {
        $trip = $this->ownedTrip($tripId);

        return response()->json($trip->exceptions()->latest('id')->get()->all());
    }

    public function returns(string $tripId): JsonResponse
    {
        $trip = $this->ownedTrip($tripId);

        return response()->json($trip->returns()->latest('id')->get()->all());
    }

    public function addReturn(Request $request, string $tripId): JsonResponse
    {
        $trip = $this->ownedTrip($tripId);
        $data = $request->validate([
            'order_id' => ['required'],
            'product_id' => ['required'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'return_type' => ['required', 'string', 'max:50'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $actorId = Auth::id();
        $return = $this->deliveries->recordReturn($trip, [
            'kind' => 'product',
            'order_id' => $data['order_id'],
            'product_id' => $data['product_id'],
            'product_name' => $data['product_name'] ?? null,
            'disposition' => $data['return_type'],
            'returned_qty' => $data['qty'],
            'reason' => $data['reason'] ?? null,
        ], $actorId !== null ? (int) $actorId : null);

        return response()->json(['return' => $return], 201);
    }

    /**
     * TASK-DRIVER-WAVE-2-PHASE-1 (W2-04) — the CANONICAL failure vocabulary for the
     * driver. Returns the Stack A FailureReason catalogue verbatim (value + label +
     * category + retryable flag). The driver UI records one of THESE values; this
     * exposes the existing enum — it defines NO second vocabulary and changes NO stop
     * lifecycle. Whether the stop lifecycle should ACT on `is_retryable` (re-attempt /
     * reschedule) is an open owner decision and is deliberately NOT wired here.
     */
    public function failureReasons(): JsonResponse
    {
        return response()->json(['data' => FailureReason::catalogue()]);
    }

    /**
     * TASK-DRIVER-WAVE-2-PHASE-1 (W2-03) — upload a payment-transfer proof for this
     * stop's order through the CANONICAL payment_proofs engine (UploadPaymentProofAction:
     * company-scoped, private disk, supersede chain, attached to the Order). UPLOAD ONLY:
     * the driver never verifies, approves, settles, or mutates financial state — that
     * stays with the operator's proof_verify permission. Fail-closed via ownedStop().
     */
    public function uploadPaymentProof(Request $request, string $stopId): JsonResponse
    {
        $stop = $this->ownedStop($stopId);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $order = Order::query()->where('id', $stop->order_id)->first();
        abort_if($order === null, 404, 'No order is linked to this stop.');

        app(UploadPaymentProofAction::class)->execute($order, $request->file('file'));

        // Created in the `uploaded` state; verification is the operator's, never the driver's.
        return response()->json(['data' => ['state' => 'uploaded']], 201);
    }

    /**
     * PATCH /api/driver/stops/{stopId}/payment-method — TASK-DRIVER-APP-PHASE-4-PAYMENT-METHOD-
     * CLOSURE-001. Let the driver change the order's payment method during an active delivery.
     *
     * This is an authorized application bridge into the EXISTING order authority — it does not
     * own the transition. The write + canonical fulfilment re-evaluation live in
     * ChangeOrderPaymentMethodAction (which reuses ReevaluateOrderFulfillmentAction and the
     * PaymentFulfillmentGate — no new engine, no Order.status write). This handler owns only
     * identity, ownership, driver eligibility, and the canonical vocabulary.
     *
     * ELIGIBILITY (§4/§11). payment_method_manual is a SOFT field with no canonical
     * order-state guard, so the driver bridge imposes the delivery-state gate itself: the stop
     * must be out for delivery (in_progress) — not before Start Delivery (pending) and not on a
     * settled/terminal outcome. VOCABULARY (§5): only the five canonical order methods.
     */
    public function changePaymentMethod(Request $request, string $stopId): JsonResponse
    {
        $stop = $this->ownedStop($stopId);

        if ($stop->status !== DeliveryStopStatus::InProgress) {
            return response()->json([
                'message' => 'The payment method can only be changed while the delivery is in progress.',
            ], 422);
        }

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'in:cod,instapay,mobile_wallet,credit_card,bank_transfer'],
        ]);

        $order = Order::query()->where('id', $stop->order_id)->first();
        abort_if($order === null, 404, 'No order is linked to this stop.');

        try {
            app(ChangeOrderPaymentMethodAction::class)->execute($order, $data['payment_method']);
        } catch (PaymentMethodChangeRejectedException $e) {
            // The canonical gate refused the change (e.g. switching a fulfilling order to a
            // proof-required method it cannot demote to collect proof). The write was rolled
            // back — return canonical truth, not the rejected method.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['stop' => $this->stopDetail($stop->refresh())]);
    }

    // ── FROZEN — Financial Settlement (Section 17). Registered, refused. ──────

    public function frozen(): JsonResponse
    {
        return response()->json([
            'message' => 'Financial settlement is frozen and not available from the driver runtime.',
        ], 403);
    }

    /**
     * GET /api/driver/vehicle-inventory — the driver's OWN vehicle stock (read-only).
     *
     * TASK-DRIVER-EXPERIENCE-UX-AND-ORDERS-FLOW-REWORK-001 — the driver needs operational
     * visibility of what the vehicle carries (loaded / delivered / returned / on-hand). This
     * EXPOSES the existing canonical read model to the driver — VehicleInventoryItem +
     * VehicleInventoryItemResource, in the exact summary shape VehicleInventoryController::show
     * already returns — scoped fail-closed to the actor's OWN current vehicle assignment. It
     * owns no calculation and no write: the driver can never edit, add, transfer or reconcile
     * stock, and can never see another driver's inventory. Gated by loading.driver.operate
     * like the rest of /driver/*; it does NOT hold — and does not need — loading.session.view.
     */
    public function vehicleInventory(): JsonResponse
    {
        $driver = $this->driver();

        // The driver's current active shipment trip → its loading vehicle assignment.
        // Resolved from the driver's OWN trips only (driver_id + company), so the assignment
        // can never be another driver's.
        $trip = Trip::query()
            ->where('company_id', $this->tenant->companyId())
            ->whereHas('driverVehicleAssignment', fn ($q) => $q->where('driver_id', $driver->id))
            ->whereNotIn('status', [TripStatus::Closed->value, TripStatus::Cancelled->value])
            ->orderByDesc('id')
            ->first();

        $assignment = $trip === null
            ? null
            : VehicleAssignment::query()->where('trip_id', $trip->id)->first();

        if ($assignment === null) {
            // No active vehicle assignment — an empty, well-formed inventory rather than a
            // 404, so the driver screen renders a clean "nothing loaded yet" state.
            return response()->json(['data' => [
                'summary' => [
                    'vehicle_assignment_id' => null,
                    'assignment_number' => null,
                    'total_quantity_loaded' => 0.0,
                    'total_quantity_delivered' => 0.0,
                    'total_quantity_returned' => 0.0,
                    'total_quantity_on_hand' => 0.0,
                    'products_count' => 0,
                ],
                'items' => [],
            ]]);
        }

        $items = VehicleInventoryItem::query()
            ->where('vehicle_assignment_id', $assignment->id)
            ->orderBy('sku_snapshot')
            ->get();

        return response()->json(['data' => [
            // The same shape VehicleInventoryController::show returns — reused, not redefined.
            'summary' => [
                'vehicle_assignment_id' => $assignment->id,
                'assignment_number' => $assignment->assignment_number,
                'total_quantity_loaded' => (float) $items->sum('quantity_loaded'),
                'total_quantity_delivered' => (float) $items->sum('quantity_delivered'),
                'total_quantity_returned' => (float) $items->sum('quantity_returned'),
                'total_quantity_on_hand' => (float) $items->sum('quantity_on_hand'),
                'products_count' => $items->count(),
            ],
            'items' => VehicleInventoryItemResource::collection($items),
        ]]);
    }

    // ── Identity + ownership guards (fail closed) ────────────────────────────

    private function driver(): Driver
    {
        $driver = Driver::query()->where('user_id', Auth::id())->first();
        abort_if($driver === null, 403, 'The authenticated user is not a driver.');

        return $driver;
    }

    /** A trip that is BOTH the actor's company AND the resolved driver's own. */
    private function ownedTrip(string $tripId): Trip
    {
        $driver = $this->driver();

        return Trip::query()
            ->where('uuid', $tripId)
            ->where('company_id', $this->tenant->companyId())
            ->whereHas('driverVehicleAssignment', fn ($q) => $q->where('driver_id', $driver->id))
            ->firstOrFail();
    }

    private function stopWithinTrip(Trip $trip, string $stopId): DeliveryStop
    {
        return DeliveryStop::query()
            ->where('trip_id', $trip->id)
            ->where(fn ($q) => $q->where('uuid', $stopId)->orWhere('id', $stopId))
            ->firstOrFail();
    }

    /** Resolve a stop from a bare stopId, re-asserting the parent trip is driver+company owned. */
    private function ownedStop(string $stopId): DeliveryStop
    {
        $stop = DeliveryStop::query()
            ->where(fn ($q) => $q->where('uuid', $stopId)->orWhere('id', $stopId))
            ->firstOrFail();

        // Re-run full ownership on the parent trip — never trust the stop id alone.
        $this->ownedTrip((string) $stop->trip->uuid);

        return $stop;
    }

    private function outcomeFor(string $actionType): ?DeliveryStopStatus
    {
        return match ($actionType) {
            'completed', 'delivered' => DeliveryStopStatus::Delivered,
            'partial' => DeliveryStopStatus::Partial,
            'refused', 'not_available', 'wrong_address', 'unreachable', 'failed' => DeliveryStopStatus::Failed,
            default => null, // 'delay' and unknown types record an action but do not settle the stop
        };
    }

    // ── Presenters ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function tripSummary(Trip $trip): array
    {
        $assignment = $trip->driverVehicleAssignment;

        return [
            'id' => $trip->uuid,
            'trip_number' => $trip->trip_number ?? null,
            'status' => $trip->status instanceof BackedEnum ? $trip->status->value : $trip->status,
            'company_id' => $trip->company_id,
            'driver_id' => $assignment?->driver_id,
            'vehicle_id' => $assignment?->vehicle_id,
            // Vehicle IDENTITY (read-only) — the plate/name the canonical Vehicle already
            // carries, reached through the driver↔vehicle pairing. Informational only; the
            // driver never edits it. Null when the pairing or vehicle is absent.
            'vehicle_plate' => $assignment?->vehicle?->plate_number,
            'vehicle_name' => $assignment?->vehicle?->name,
            'stops_count' => $trip->stops_count ?? $trip->stops()->count(),
            'exceptions_count' => $trip->exceptions_count ?? null,
            'trip_started_at' => optional($trip->trip_started_at)->toIso8601String(),
            'trip_finished_at' => optional($trip->trip_finished_at)->toIso8601String(),
        ];
    }

    /**
     * The stop as it appears in a LIST.
     *
     * TASK-DRIVER-01 — this previously returned six scalars and no order at all, so every
     * card in the driver's stop list rendered `Stop #N` with a blank customer, no address
     * and no amount, and the list search (which matches on order number and customer name)
     * could never match anything. It now carries the shared order payload, in its `list`
     * shape: the fields a card needs and nothing more.
     *
     * @return array<string, mixed>
     */
    private function stopSummary(DeliveryStop $stop): array
    {
        return [
            'id' => $stop->uuid ?? $stop->id,
            'trip_id' => $stop->trip_id,
            'order_id' => $stop->order_id,
            'sequence' => $stop->sequence,
            'status' => $stop->status instanceof BackedEnum ? $stop->status->value : $stop->status,
            'delivery_type' => $stop->delivery_type,
            'collected_amount' => (float) ($stop->collected_amount ?? 0),
            'attempted_at' => optional($stop->attempted_at)->toIso8601String(),
            'completed_at' => optional($stop->completed_at)->toIso8601String(),
            'notes' => $stop->notes,
            'order' => $this->orderPayload($stop, withLines: false),
        ];
    }

    /** @return array<string, mixed> */
    private function stopDetail(DeliveryStop $stop): array
    {
        $summary = $this->stopSummary($stop);
        $summary['order'] = $this->orderPayload($stop, withLines: true);

        return $summary;
    }

    /**
     * THE driver-facing representation of a stop's order — one shape, two callers.
     *
     * ┌─ WHY THIS IS ONE METHOD ─────────────────────────────────────────────────┐
     * │ The list and the detail previously disagreed: the detail hydrated an      │
     * │ order, the list sent none, and the frontend type declared the field       │
     * │ non-optional for both. Building a second shape for the list would repeat  │
     * │ that mistake in the other direction, so the list and the detail return    │
     * │ the SAME keys and differ only in whether `lines` is populated.            │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * TENANT-SAFE by construction: `Order` carries a global company scope, so an order
     * outside the actor's company simply resolves to null here. That is on top of the
     * D-02 guarantee that the stop itself was already proven to belong to a trip that is
     * both the actor's company and this driver's own — this is defence in depth, not the
     * boundary.
     *
     * `payment_method` uses the same precedence as `PaymentFulfillmentGate::methodOf()`
     * (manual entry wins over the channel-supplied value), so the driver sees the method
     * the payment contract actually evaluates rather than a second interpretation.
     *
     * @return array<string, mixed>|null
     */
    private function orderPayload(DeliveryStop $stop, bool $withLines): ?array
    {
        // The list needs a COUNT of items, not the items — `withCount` keeps a stop list
        // to one order query per stop instead of loading every line and every product.
        $order = Order::query()
            ->when($withLines, fn ($q) => $q->with('lines.product'), fn ($q) => $q->withCount('lines'))
            ->where('id', $stop->order_id)
            ->first();

        if ($order === null) {
            return null;
        }

        // ── DRIVER-SCOPED CUSTOMER PII GATING (TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §1–§5)
        //
        // Customer identity/location/contact are progressively disclosed by the driver's
        // canonical operational state — NEVER a frontend flag. This redaction is DRIVER-SCOPED:
        // it lives only in the driver runtime payload and does not touch the Order model or any
        // Enterprise/Operations order read (§2). Stages:
        //   A  PRE-DEPARTURE trip (trip does not accept delivery execution) → no PII at all.
        //   B  trip on the road + this stop NOT yet started                 → name/address/location.
        //   C  this stop's delivery started (canonical stop state)          → + phone/contact/notes.
        $stage = $this->driverPrivacyStage($stop);
        $showIdentity = $stage !== 'A';   // customer name, address, geographic location
        $showContact  = $stage === 'C';   // direct-contact + free-text notes (may carry contact)

        $payload = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $showIdentity ? $order->customer_name : null,
            'phone' => $showContact ? $order->billing_phone : null,
            'address' => $showIdentity ? $order->shipping_address : null,
            'governorate' => $showIdentity ? $order->governorate : null,
            'city' => $showIdentity ? $order->city : null,
            'area' => $showIdentity ? $order->area : null,
            'gps' => ($showIdentity && $order->google_maps_lat !== null && $order->google_maps_lng !== null)
                ? ['lat' => (float) $order->google_maps_lat, 'lng' => (float) $order->google_maps_lng]
                : null,
            // Non-PII operational fields — always visible (§1: order number, payment class, value, products).
            'payment_method' => trim((string) ($order->payment_method_manual ?? $order->payment_method ?? '')) ?: null,
            'grand_total' => (float) $order->total,
            'deposit_paid' => (float) ($order->deposit_amount ?? 0),
            'remaining_balance' => (float) ($order->remaining_balance ?? 0),
            'items_count' => (int) ($order->lines_count ?? $order->lines->count()),
            'delivery_notes' => $showContact ? $order->customer_note : null,
        ];

        if (! $withLines) {
            return $payload;
        }

        $payload['lines'] = $order->lines->map(fn ($l) => [
            // order_line_id identifies the line for the delivery API (POST /driver/stops/{id}/deliver);
            // allocation_records are keyed by it, so the driver posts cumulative delivered per line.
            'order_line_id' => $l->id,
            'product_id' => $l->product_id,
            'product_name' => $l->product?->name,
            'ordered_qty' => (float) $l->quantity,
            'unit_price' => (float) $l->unit_price,
            'line_total' => (float) $l->line_total,
            'loaded_qty' => (float) ($l->loaded_qty ?? 0),
            'delivered_qty' => (float) ($l->delivered_qty ?? 0),
            'returned_qty' => (float) ($l->returned_qty ?? 0),
            'remaining_qty' => max(
                0.0,
                (float) $l->quantity - (float) ($l->delivered_qty ?? 0)
                    - (float) ($l->returned_qty ?? 0) - (float) ($l->cancelled_qty ?? 0),
            ),
        ])->all();

        return $payload;
    }

    /**
     * The driver PII disclosure stage for a stop — derived ONLY from canonical lifecycle state
     * (TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §5), never a frontend flag or translated label.
     *
     *   'A' — the parent trip does NOT accept delivery execution (pre-departure). No customer PII.
     *   'B' — trip accepts delivery execution AND this stop is not yet started. Identity + location.
     *   'C' — this stop's delivery has started (canonical stop state ≠ pending). Full contact.
     *
     * Mirrors the same `TripStatus::acceptsDeliveryExecution()` gate the delivery guard uses, so
     * PII disclosure and delivery execution can never disagree.
     */
    private function driverPrivacyStage(DeliveryStop $stop): string
    {
        $trip = $stop->relationLoaded('trip') ? $stop->trip : $stop->trip()->first();
        $tripStatus = $trip?->status;

        $acceptsDelivery = $tripStatus instanceof TripStatus && $tripStatus->acceptsDeliveryExecution();
        if (! $acceptsDelivery) {
            return 'A';
        }

        $stopStatus = $stop->status instanceof BackedEnum ? $stop->status->value : (string) $stop->status;

        return $stopStatus === DeliveryStopStatus::Pending->value ? 'B' : 'C';
    }
}
