<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\Cash\Domain\Models\CashAccount;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripCashHandover;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;
use Modules\Logistics\Distribution\Domain\Services\CashHandoverService;
use Modules\Logistics\Distribution\Presentation\Http\Resources\TripCashHandoverResource;

/**
 * Treasury physical cash handover — the second-actor confirmation step
 * (TASK-ECOS-DRIVER-SETTLEMENT-TREASURY-FINAL-IMPLEMENTATION-002).
 *
 * Controller responsibilities only: validate, authorize, resolve scoped
 * entities, invoke the application service, return its result. No accounting
 * math lives here — {@see CashHandoverService} owns it.
 */
class CashHandoverController extends Controller
{
    public function __construct(private readonly CashHandoverService $handovers) {}

    /**
     * Everything the confirmation screen needs, in one server-authoritative
     * read: the expected amount, the driver's declaration (if any), any
     * existing handover, and the company's available destination cash
     * accounts (reusing the same CashAccount authority
     * Modules\Finance\Presentation\Http\Controllers\CashController::accounts()
     * exposes, queried directly here rather than requiring a second
     * authenticated call across a Finance-only permission boundary).
     */
    public function show(Request $request, string $tripId): JsonResponse
    {
        $trip = $this->resolveTrip($request, $tripId);
        $settlement = TripSettlement::where('trip_id', $trip->id)->first();

        if ($settlement === null) {
            return response()->json(['message' => 'No settlement has been opened for this trip.'], 404);
        }

        $handover = TripCashHandover::where('trip_settlement_id', $settlement->id)->first();

        return response()->json(['data' => [
            'expected_cash' => $this->handovers->expectedCash($trip),
            'driver_declared_cash' => $settlement->driver_cash_submitted !== null
                ? (float) $settlement->driver_cash_submitted
                : null,
            'handover' => $handover !== null ? new TripCashHandoverResource($handover) : null,
            'cash_accounts' => CashAccount::query()
                ->where('company_id', $trip->company_id)
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
                ->map(fn (CashAccount $a): array => ['id' => $a->uuid, 'code' => $a->code, 'name' => $a->name]),
        ]]);
    }

    public function confirm(Request $request, string $tripId): JsonResponse
    {
        $validated = $request->validate([
            'received_cash' => ['required', 'numeric', 'min:0'],
            'cash_account_id' => ['required', 'string'], // CashAccount uuid
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $trip = $this->resolveTrip($request, $tripId);
        $settlement = TripSettlement::where('trip_id', $trip->id)->first();

        if ($settlement === null) {
            return response()->json(['message' => 'No settlement has been opened for this trip.'], 404);
        }

        try {
            $handover = $this->handovers->confirmReceipt(
                $settlement,
                (float) $validated['received_cash'],
                (string) $validated['cash_account_id'],
                (int) $request->user()->id,
                $validated['notes'] ?? null,
            );
        } catch (DistributionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (FinanceException $e) {
            // Covers, among others, an unmapped 'driver_cash_clearing' AccountRole and
            // a closed fiscal period (JournalEngine::assertOpenPeriod) — both refuse
            // loudly rather than posting incorrectly. §21: no raw exception leaks.
            return response()->json(['message' => 'Finance posting was refused: '.$e->getMessage()], 422);
        }

        return (new TripCashHandoverResource($handover))->response()->setStatusCode(201);
    }

    /**
     * Resolve a trip by its public UUID identifier, WITHIN THE ACTING COMPANY —
     * the identical pattern {@see SettlementController::resolveTrip()} carries
     * (TASK-DRIVER-02): a foreign trip reads as 404, never 403, so this
     * endpoint cannot be used to probe which trip uuids exist in another company.
     */
    private function resolveTrip(Request $request, string $tripId): Trip
    {
        $companyId = $request->user()?->company_id;
        if ($companyId === null || $companyId === '') {
            abort(403, 'No company scope for the acting user.');
        }

        return Trip::where('uuid', $tripId)->where('company_id', (string) $companyId)->firstOrFail();
    }
}
