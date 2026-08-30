<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Logistics\Distribution\Application\Actions\RecordDriverTripMovementAction;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementCategory;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementDirection;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Models\DriverTripMovement;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DRIVER TRIP EXPENSES — TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §30–§43.
 *
 * The driver's own CURRENT active-custody operational movements: fuel / road toll / other expense
 * (cash out) and advances (cash in). READ + CREATE only — the driver never approves or settles
 * (§35/§40). Identity, company, driver and trip are ALWAYS resolved server-side from the
 * authenticated driver's single active custody (§36/§43); no driver_id/company_id/trip_id from the
 * client is trusted. Gated by `loading.driver.operate` on the route group, exactly like the rest of
 * the driver runtime.
 */
final class DriverTripExpenseController extends Controller
{
    public function __construct(
        private readonly RecordDriverTripMovementAction $record,
        private readonly TenantOwnershipResolver $tenant,
    ) {}

    /** GET /api/driver/trip-expenses — the current custody trip's movements + approved totals. */
    public function index(): JsonResponse
    {
        [$driver, $companyId] = $this->context();
        $trip = $this->currentCustodyTrip($driver->id, $companyId);

        if ($trip === null) {
            // §45 — an explicit "no active custody" state, never an error and never fake zeros.
            return response()->json(['data' => [
                'has_active_custody' => false,
                'trip' => null,
                'items' => [],
                'totals' => $this->emptyTotals(),
            ]]);
        }

        $movements = DriverTripMovement::query()
            ->where('company_id', $companyId)
            ->where('driver_id', $driver->id)
            ->where('trip_id', $trip->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => [
            'has_active_custody' => true,
            'trip' => ['id' => $trip->uuid, 'trip_number' => $trip->trip_number],
            'items' => $movements->map(fn (DriverTripMovement $m): array => $this->payload($m))->all(),
            'totals' => $this->totals($movements),
        ]]);
    }

    /** POST /api/driver/trip-expenses — record ONE pending movement for the current custody trip. */
    public function store(Request $request): JsonResponse
    {
        [$driver, $companyId] = $this->context();

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(DriverTripMovementCategory::values())],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'note' => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date'],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $trip = $this->currentCustodyTrip($driver->id, $companyId);
        // §43 — a movement requires an eligible ACTIVE operational custody; planning/loading shells
        // and closed trips cannot receive one. The server is authoritative, not the client.
        abort_if($trip === null, 422, 'You have no active trip custody to record an expense against.');

        $occurredAt = isset($data['occurred_at']) && $data['occurred_at'] !== null
            ? new DateTimeImmutable((string) $data['occurred_at'])
            : new DateTimeImmutable('now');

        $movement = $this->record->execute(
            companyId: (string) $companyId,
            driverId: (int) $driver->id,
            tripId: (int) $trip->id,
            category: DriverTripMovementCategory::from((string) $data['category']),
            amount: (float) $data['amount'],
            note: $data['note'] ?? null,
            occurredAt: $occurredAt,
            receipt: $request->file('receipt'),
            actorId: (string) Auth::id(),
        );

        return response()->json(['data' => $this->payload($movement)], 201);
    }

    /** GET /api/driver/trip-expenses/{movementId}/receipt — tenant + driver scoped download. */
    public function downloadReceipt(string $movementId): StreamedResponse
    {
        [$driver, $companyId] = $this->context();

        $movement = DriverTripMovement::query()
            ->where('id', $movementId)
            ->where('company_id', $companyId)
            ->where('driver_id', $driver->id)
            ->firstOrFail();

        abort_unless($movement->hasReceipt(), 404, 'This movement has no receipt.');

        // Only the server-recorded path is ever used — the client cannot name a storage path.
        return Storage::disk($movement->storage_disk ?? 'local')->download((string) $movement->receipt_path);
    }

    // ── Identity + custody resolution (fail closed) ──────────────────────────────

    /** @return array{0: Driver, 1: string|null} */
    private function context(): array
    {
        $driver = Driver::query()->where('user_id', Auth::id())->first();
        abort_if($driver === null, 403, 'The authenticated user is not a driver.');

        return [$driver, $this->tenant->companyId()];
    }

    /**
     * The driver's single ACTIVE operational custody trip (§43) — a trip past loading and not yet
     * terminal, in this company. Uses the canonical `TripStatus::isCustodyEligible()` window, so it
     * cannot disagree with the rest of the custody lifecycle.
     */
    private function currentCustodyTrip(int $driverId, ?string $companyId): ?Trip
    {
        if ($companyId === null) {
            return null;
        }

        return Trip::query()
            ->where('company_id', $companyId)
            ->whereHas('driverVehicleAssignment', fn ($q) => $q->where('driver_id', $driverId))
            ->whereIn('status', TripStatus::custodyEligibleValues())
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function payload(DriverTripMovement $m): array
    {
        $category = $m->category instanceof DriverTripMovementCategory ? $m->category : DriverTripMovementCategory::from((string) $m->category);
        $direction = $m->direction instanceof DriverTripMovementDirection ? $m->direction : DriverTripMovementDirection::from((string) $m->direction);
        $status = $m->status instanceof DriverTripMovementStatus ? $m->status : DriverTripMovementStatus::from((string) $m->status);

        return [
            'id' => $m->id,
            'category' => $category->value,
            'direction' => $direction->value,
            'is_expense' => $category->isExpense(),
            'amount' => (float) $m->amount,
            'note' => $m->note,
            'status' => $status->value,
            'occurred_at' => optional($m->occurred_at)->toIso8601String(),
            'created_at' => optional($m->created_at)->toIso8601String(),
            'has_receipt' => $m->hasReceipt(),
        ];
    }

    /**
     * The driver-scoped Net-Cash read contract (§41/§42). ONLY approved (or settled) movements
     * count toward the operational totals; pending/rejected never do. This is a read model for a
     * future Driver Closing calculation — it posts nothing and settles nothing here.
     *
     * @param  \Illuminate\Support\Collection<int, DriverTripMovement>  $movements
     * @return array<string, mixed>
     */
    private function totals($movements): array
    {
        $approvedExpenses = 0.0;   // approved cash-OUT (fuel/toll/other) — the Expense total (§41)
        $approvedAdvances = 0.0;   // approved cash-IN (advances) — NOT an expense (§32)
        $pendingCount = 0;

        foreach ($movements as $m) {
            $status = $m->status instanceof DriverTripMovementStatus ? $m->status : DriverTripMovementStatus::from((string) $m->status);
            $direction = $m->direction instanceof DriverTripMovementDirection ? $m->direction : DriverTripMovementDirection::from((string) $m->direction);

            if ($status === DriverTripMovementStatus::Pending) {
                $pendingCount++;
            }
            if (! $status->countsTowardTotals()) {
                continue;
            }
            if ($direction === DriverTripMovementDirection::CashOut) {
                $approvedExpenses += (float) $m->amount;
            } else {
                $approvedAdvances += (float) $m->amount;
            }
        }

        return [
            // Advance is deliberately NOT netted into expenses (§32/§41).
            'approved_expenses' => round($approvedExpenses, 2),
            'approved_advances' => round($approvedAdvances, 2),
            'pending_count' => $pendingCount,
            // Net operational cash movement from approved movements (cash-in minus cash-out).
            // Physical-cash-collected and opening balance are owned elsewhere and NOT invented here.
            'net_movement' => round($approvedAdvances - $approvedExpenses, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyTotals(): array
    {
        return ['approved_expenses' => 0.0, 'approved_advances' => 0.0, 'pending_count' => 0, 'net_movement' => 0.0];
    }
}
