<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Logistics\Distribution\Domain\Enums\PaymentType;
use Modules\Logistics\Distribution\Domain\Enums\SettlementStatus;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\PaymentCollection;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;
use Modules\Logistics\Distribution\Domain\Services\SettlementService;
use Modules\Logistics\Distribution\Presentation\Http\Resources\PaymentCollectionResource;
use Modules\Logistics\Distribution\Presentation\Http\Resources\TripSettlementResource;

class SettlementController extends Controller
{
    public function __construct(
        private readonly SettlementService $settlements,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json([
            'payment_types' => PaymentType::options(),
            'settlement_statuses' => SettlementStatus::options(),
        ]);
    }

    // ── Payment collection ───────────────────────────────────────────────────

    public function payments(string $tripId): AnonymousResourceCollection
    {
        return PaymentCollectionResource::collection(
            $this->resolveTrip($tripId)->paymentCollections()->get(),
        );
    }

    public function recordPayment(Request $request, string $tripId, int $stopId): JsonResponse
    {
        $validated = $request->validate([
            'payment_type' => ['required', Rule::in(PaymentType::values())],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            // TASK-DRIVER-02 — `image_path` is a CLIENT-SUPPLIED string, not an upload, and
            // it is NOT a payment proof. Nothing verifies it, nothing stored the file it
            // names, and it can never satisfy `PaymentFulfillmentGate` — the canonical
            // `payment_proofs` lifecycle remains the only proof source.
            //
            // Reconciling this field with that lifecycle is a redesign and is STOPPED for an
            // owner decision (see the D-02 report §12). What is fixed here is the part that
            // needs no decision: the value is constrained to a plain relative storage path,
            // so it cannot carry a URL scheme (`javascript:`, `data:`, `http:` → stored XSS
            // / external fetch when rendered as an image source), an absolute path, a UNC
            // path, or `..` traversal. This is validation hardening ONLY — it does not make
            // the field trustworthy and must not be read as proof of anything.
            'image_path' => ['nullable', 'string', 'max:500', 'regex:/^(?!\/|\\\\|[A-Za-z]+:)(?!.*\.\.)[\w\-.\/]+$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $stop = DeliveryStop::where('trip_id', $this->resolveTrip($tripId)->id)->findOrFail($stopId);

        try {
            $payment = $this->settlements->recordPayment($stop, $validated, $request->user()?->id);
        } catch (DistributionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new PaymentCollectionResource($payment))->response()->setStatusCode(201);
    }

    public function verifyPayment(Request $request, string $tripId, int $paymentId): PaymentCollectionResource
    {
        $payment = PaymentCollection::where('trip_id', $this->resolveTrip($tripId)->id)->findOrFail($paymentId);

        return new PaymentCollectionResource(
            $this->settlements->verifyPayment($payment, $request->user()?->id),
        );
    }

    public function rejectPayment(Request $request, string $tripId, int $paymentId): PaymentCollectionResource
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $payment = PaymentCollection::where('trip_id', $this->resolveTrip($tripId)->id)->findOrFail($paymentId);

        return new PaymentCollectionResource(
            $this->settlements->rejectPayment($payment, $validated['notes'] ?? null, $request->user()?->id),
        );
    }

    // ── Settlement ───────────────────────────────────────────────────────────

    public function show(string $tripId): JsonResponse|TripSettlementResource
    {
        $settlement = TripSettlement::where('trip_id', $this->resolveTrip($tripId)->id)->first();

        if ($settlement === null) {
            return response()->json(['message' => 'No settlement has been opened for this trip.'], 404);
        }

        return new TripSettlementResource($settlement);
    }

    /** Derives the totals from the payment ledger and opens the settlement. */
    public function open(string $tripId): JsonResponse|TripSettlementResource
    {
        try {
            $settlement = $this->settlements->openSettlement($this->resolveTrip($tripId));
        } catch (DistributionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new TripSettlementResource($settlement);
    }

    public function submitCash(Request $request, string $tripId): JsonResponse|TripSettlementResource
    {
        $validated = $request->validate([
            'driver_cash_submitted' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $settlement = $this->settlements->submitDriverCash(
                $this->findSettlement($tripId),
                (float) $validated['driver_cash_submitted'],
                $validated['notes'] ?? null,
            );
        } catch (DistributionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new TripSettlementResource($settlement);
    }

    public function reconcile(Request $request, string $tripId): JsonResponse|TripSettlementResource
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        try {
            $settlement = $this->settlements->reconcile($this->findSettlement($tripId), $validated['notes'] ?? null);
        } catch (DistributionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new TripSettlementResource($settlement);
    }

    public function dispute(Request $request, string $tripId): JsonResponse|TripSettlementResource
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        try {
            $settlement = $this->settlements->dispute($this->findSettlement($tripId), $validated['notes'] ?? null);
        } catch (DistributionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new TripSettlementResource($settlement);
    }

    public function finalize(Request $request, string $tripId): JsonResponse|TripSettlementResource
    {
        try {
            $settlement = $this->settlements->finalize(
                $this->findSettlement($tripId),
                $request->user()?->id,
                $request->user()?->name,
            );
        } catch (DistributionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new TripSettlementResource($settlement);
    }

    /** Read-only financial summary for the trip. */
    public function summary(string $tripId): JsonResponse
    {
        return response()->json(
            $this->settlements->financialSummary($this->resolveTrip($tripId)),
        );
    }

    /**
     * Resolve a trip by its public UUID identifier, WITHIN THE ACTING COMPANY.
     *
     * ┌─ SECURITY FIX — TASK-DRIVER-02 ─────────────────────────────────────────┐
     * │ This was `Trip::where('uuid', $tripId)->firstOrFail()` with no company    │
     * │ scope. `Trip` has no global tenant scope, so a uuid was a bearer token:   │
     * │ every method on this controller flows through here, which meant ANY       │
     * │ authenticated holder of `logistics.distribution.*` could read another     │
     * │ company's payment ledger and cash position, record a payment against it,  │
     * │ verify it, and finalize its settlement.                                   │
     * │                                                                          │
     * │ NOT A NEW MECHANISM. This is the identical fix already applied to the     │
     * │ sibling `TripController::resolveTrip()`, copied verbatim so the two       │
     * │ cannot drift. `DeliveryController::resolveTrip()` carried the same defect │
     * │ and is fixed in the same pass — the audit found three call sites, not one.│
     * │                                                                          │
     * │ FAIL-CLOSED and NOT-FOUND, never 403: a foreign trip must read as         │
     * │ non-existent so the endpoint cannot be used to probe which uuids are real.│
     * └──────────────────────────────────────────────────────────────────────────┘
     */
    private function resolveTrip(string $tripId): Trip
    {
        return Trip::where('uuid', $tripId)
            ->where('company_id', $this->companyId())
            ->firstOrFail();
    }

    /**
     * The acting company, or a hard failure.
     *
     * Never returns null. The `->when($companyId, …)` idiom used elsewhere in Logistics
     * silently DROPS the filter when the company is null and therefore returns every
     * tenant's rows; `TripController` documents that pattern as deliberately not copied,
     * and it is not copied here either.
     */
    private function companyId(): string
    {
        $companyId = request()->user()?->company_id;

        if ($companyId === null || $companyId === '') {
            abort(403, 'No company scope for the acting user.');
        }

        return (string) $companyId;
    }

    private function findSettlement(string $tripId): TripSettlement
    {
        return TripSettlement::where('trip_id', $this->resolveTrip($tripId)->id)->firstOrFail();
    }
}
