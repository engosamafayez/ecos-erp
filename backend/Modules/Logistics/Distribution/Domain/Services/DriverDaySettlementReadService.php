<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementCategory;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementDirection;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementStatus;
use Modules\Logistics\Distribution\Domain\Enums\PaymentType;
use Modules\Logistics\Distribution\Domain\Enums\SettlementStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\DriverTripMovement;
use Modules\Logistics\Distribution\Domain\Models\PaymentCollection;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripReturn;
use Modules\Operations\Loading\Domain\Enums\ReconciliationStatus;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliation;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliationLine;

/**
 * READ-ONLY per-driver / per-day settlement rollup
 * (TASK-OPERATIONS-DRIVER-DAY-SETTLEMENT-UI-001, extended by
 * TASK-OPERATIONS-DRIVER-CLOSING-PAGE-ENHANCEMENT-001).
 *
 * This is NOT a settlement engine and owns NO money logic of its own. Every money
 * figure is DERIVED, per trip, from the canonical {@see SettlementService::financialSummary()}
 * and then SUMMED across the driver's trips for the day. Every goods figure is DERIVED
 * from the canonical vehicle-custody engine ({@see VehicleInventoryItem}) and the canonical
 * end-of-shift reconciliation ({@see VehicleShiftReconciliation}/Line) — see
 * [[returns_reconciliation_authority]]. There is no new table, no new status machine, and
 * nothing here writes.
 *
 * THE DRIVER-DAY GRAIN — a row is one `(driver_vehicle_assignment_id, operational_day)`.
 * `distribution_trips` has no scheduled_date, so the operational day is anchored on
 * `DATE(COALESCE(trip_started_at, dispatched_at, created_at))`. Custody-handoff eligibility
 * (§2): a driver appears as soon as goods leave the warehouse — a trip that has custody or
 * has begun delivery — even when Delivered = 0.
 *
 * ACTIVE vs HISTORY (§3) — Active = driver-days whose trips are not all Closed (open
 * custody / unsettled); History = driver-days whose trips are all Closed (canonically
 * finalized), date-filtered / paginated / sorted server-side.
 *
 * THE RECONCILIATION LINK — the custody/reconciliation grain is the OPERATIONS
 * {@see VehicleAssignment} (which carries `trip_id`), NOT the logistics
 * driver_vehicle_assignment that keys the day row. A Trip reaches its custody through
 * `VehicleAssignment::whereIn('trip_id', …)`, the same idiom {@see goodsRemaining()} uses.
 * Reconciliation is operator-OPENED, so a driver-day with no opened shift reports an honest
 * "not reconciled" state rather than fabricated zeros.
 *
 * TENANCY — every query is fail-closed to the acting company id supplied by the
 * controller. `Trip`, `PaymentProof`, `VehicleAssignment`, `VehicleInventoryItem` and the
 * reconciliation tables carry no global tenant scope, so the explicit
 * `where('company_id', …)` is the only thing standing between companies here.
 */
class DriverDaySettlementReadService
{
    /** Aggregated per-driver money-settlement states (the canonical SettlementStatus rollup). */
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_SETTLED = 'settled';

    /**
     * Derived OPERATIONAL closing stages (§13). These are a read-only rollup label over
     * canonical facts (settlement status + reconciliation status + delivery/custody state) —
     * NOT a new persisted lifecycle. They map existing facts; they never write.
     */
    public const STAGE_OPEN_CUSTODY = 'open_custody';

    public const STAGE_IN_OPERATION = 'in_operation';

    public const STAGE_READY_FOR_RETURN = 'ready_for_return';

    public const STAGE_WAREHOUSE_COUNTING = 'warehouse_counting';

    public const STAGE_NEEDS_REVIEW = 'needs_review';

    public const STAGE_READY_FOR_CLOSING = 'ready_for_closing';

    public const STAGE_CLOSED = 'closed';

    /** Quantities are decimal(18,4); compare below that resolution. */
    private const EPSILON = 0.00005;

    /** The operational-day anchor, reused across every query. */
    private const DAY_EXPR = 'DATE(COALESCE(trip_started_at, dispatched_at, created_at))';

    public function __construct(
        private readonly SettlementService $settlements,
    ) {}

    // ── Board scopes (§3) ───────────────────────────────────────────────────────

    /**
     * The single-day board — one row per driver-day for the date. Kept for the
     * date-anchored view and back-compat; Active/History are the operational tabs.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function daySummary(string $companyId, string $date, array $filters = []): array
    {
        $trips = Trip::query()
            ->where('company_id', $companyId)
            ->whereNotNull('driver_vehicle_assignment_id')
            ->whereRaw(self::DAY_EXPR.' = ?', [$date])
            ->when(
                ($filters['shipping_company_id'] ?? null) !== null && ($filters['shipping_company_id'] ?? '') !== '',
                fn ($q) => $q->where('shipping_company_id', $filters['shipping_company_id']),
            )
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle', 'settlement'])
            ->get();

        $rows = $this->buildRows($companyId, $trips);
        $filtered = $this->applyListFilters($rows, $filters)->values();

        return [
            'scope' => 'day',
            'date' => $date,
            'kpis' => $this->kpis($rows),
            'drivers' => $this->sortRows($filtered, $filters['sort'] ?? 'driver', $filters['dir'] ?? 'asc')->values()->all(),
        ];
    }

    /**
     * The ACTIVE board (§3) — every driver-day with open custody / an unsettled trip,
     * across all dates. A driver-day stays here until every one of its trips is Closed
     * (canonically finalized). Not date-bounded: active custody must remain visible
     * regardless of any historical date filter.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function activeBoard(string $companyId, array $filters = []): array
    {
        // Open OPERATIONAL custodies only (TASK-...-SINGLE-ACTIVE-CUSTODY-CLOSURE-001, §3): a trip
        // is Active once REAL goods custody has been handed to the driver — i.e. loading completed
        // (all loaded products driver-confirmed) — and until it is closed. Planning/loading shells,
        // mere assignments and calendar dates never qualify. The status set is the canonical
        // custody-eligibility gate (TripStatus::isCustodyEligible).
        $trips = Trip::query()
            ->where('company_id', $companyId)
            ->whereNotNull('driver_vehicle_assignment_id')
            ->whereIn('status', TripStatus::custodyEligibleValues())
            ->when(
                ($filters['shipping_company_id'] ?? null) !== null && ($filters['shipping_company_id'] ?? '') !== '',
                fn ($q) => $q->where('shipping_company_id', $filters['shipping_company_id']),
            )
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle', 'settlement'])
            ->get();

        // Grain = ONE row per open Trip/Custody (§7) — NOT per calendar day. A driver holding more
        // than one open custody (legacy corruption) is surfaced as needs-review, never deduped (§13).
        $rows = $this->flagDuplicateOpenCustody($this->buildRows($companyId, $trips));

        $filtered = $this->applyListFilters($rows, $filters)->values();

        return [
            'scope' => 'active',
            'kpis' => $this->kpis($rows),
            'drivers' => $this->sortRows($filtered, $filters['sort'] ?? 'date', $filters['dir'] ?? 'desc')->values()->all(),
        ];
    }

    /**
     * The HISTORY board (§3, §17, §20) — permanently-closed driver-days, filtered by the
     * settlement finalized date in the company range, paginated and sorted server-side.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function historyBoard(
        string $companyId,
        string $from,
        string $to,
        int $page,
        int $perPage,
        string $sort,
        string $dir,
        array $filters = [],
    ): array {
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        $page = max(1, $page);

        // Closed, finalized driver-bearing trips whose settlement was finalized inside the
        // range. Closed trips are terminal, so a history group's trips are all closed. All
        // narrowing (date range, carrier, driver/vehicle search) happens here on the SERVER;
        // grouping, sorting and pagination happen below on the server — never in the browser.
        $trips = Trip::query()
            ->where('company_id', $companyId)
            ->whereNotNull('driver_vehicle_assignment_id')
            ->where('status', TripStatus::Closed->value)
            ->whereHas('settlement', function ($s) use ($from, $to): void {
                $s->where('status', SettlementStatus::Finalized->value)
                    ->whereNotNull('finalized_at')
                    ->whereRaw('DATE(finalized_at) >= ?', [$from])
                    ->whereRaw('DATE(finalized_at) <= ?', [$to]);
            })
            ->when(
                ($filters['shipping_company_id'] ?? null) !== null && ($filters['shipping_company_id'] ?? '') !== '',
                fn ($q) => $q->where('shipping_company_id', $filters['shipping_company_id']),
            )
            ->when(
                trim((string) ($filters['search'] ?? '')) !== '',
                fn ($q) => $q->whereHas('driverVehicleAssignment', function ($a) use ($filters): void {
                    $needle = '%'.trim((string) $filters['search']).'%';
                    $a->where(function ($w) use ($needle): void {
                        $w->whereHas('driver', fn ($d) => $d->where('full_name', 'like', $needle))
                            ->orWhereHas('vehicle', fn ($v) => $v->where('plate_number', 'like', $needle));
                    });
                }),
            )
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle', 'settlement'])
            ->get();

        $rows = $this->sortRows($this->buildRows($companyId, $trips), $sort, $dir);

        $total = $rows->count();
        $paged = $rows->forPage($page, $perPage)->values();

        return [
            'scope' => 'history',
            'range' => ['from' => $from, 'to' => $to],
            'kpis' => $this->kpis($rows),
            'drivers' => $paged->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ];
    }

    // ── Detail drill-down (§16) ─────────────────────────────────────────────────

    /**
     * The drill-down for one driver's day: overview, financial rollup, collections
     * breakdown, vehicle-custody + product reconciliation, damage, shortage, timeline,
     * closing readiness, and the supporting order / transfer / return detail.
     *
     * @return array<string, mixed>
     */
    public function driverDay(string $companyId, string $date, int $assignmentId): array
    {
        $trips = Trip::query()
            ->where('company_id', $companyId)
            ->where('driver_vehicle_assignment_id', $assignmentId)
            ->whereRaw(self::DAY_EXPR.' = ?', [$date])
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle', 'settlement'])
            ->orderBy('id')
            ->get();

        if ($trips->isEmpty()) {
            abort(404, 'No trips found for this driver on the selected date.');
        }

        $assignment = $trips->first()->driverVehicleAssignment;
        $driver = $assignment?->driver;
        $vehicle = $assignment?->vehicle;

        $tripIds = $trips->pluck('id')->all();

        // Money SSOT — the canonical per-trip engine, keyed by trip id.
        $summaries = [];
        foreach ($trips as $trip) {
            $summaries[$trip->id] = $this->settlements->financialSummary($trip);
        }

        $stops = DeliveryStop::query()
            ->whereIn('trip_id', $tripIds)
            ->orderBy('trip_id')
            ->orderBy('sequence')
            ->get();

        $returns = TripReturn::query()->whereIn('trip_id', $tripIds)->get();

        $ordersCount = $stops->count();
        $deliveredCount = $stops->filter(fn (DeliveryStop $s): bool => $s->status === DeliveryStopStatus::Delivered)->count();
        $partialCount = $stops->filter(fn (DeliveryStop $s): bool => $s->status === DeliveryStopStatus::Partial)->count();
        $failedCount = $stops->filter(fn (DeliveryStop $s): bool => $s->status === DeliveryStopStatus::Failed)->count();

        // The reconciliation / custody grain (operations assignments for these trips).
        $recon = $this->reconciliationForTrips($companyId, $tripIds);

        $allNonRejected = PaymentCollection::query()
            ->whereIn('trip_id', $tripIds)
            ->where('status', '!=', PaymentCollection::STATUS_REJECTED)
            ->with('stop')
            ->get();

        $transferCollections = PaymentCollection::query()
            ->whereIn('trip_id', $tripIds)
            ->whereIn('payment_type', [PaymentType::BankTransfer->value, PaymentType::Card->value])
            ->with('stop')
            ->latest()
            ->get();

        // APPROVED transfers are the verified subset only.
        $approvedTransfers = round(
            (float) $transferCollections
                ->where('status', PaymentCollection::STATUS_VERIFIED)
                ->sum(fn (PaymentCollection $c): float => (float) $c->amount),
            2,
        );

        $submitted = $trips
            ->map(fn (Trip $t): ?float => $t->settlement?->driver_cash_submitted !== null
                ? (float) $t->settlement->driver_cash_submitted
                : null)
            ->filter(fn (?float $v): bool => $v !== null);
        $actualCash = $submitted->isEmpty() ? null : round((float) $submitted->sum(), 2);

        $difference = $this->aggregateDifference($summaries);

        $orderIds = collect($stops->pluck('order_id')->all())
            ->merge($transferCollections->map(fn (PaymentCollection $c) => $c->stop?->order_id)->all())
            ->filter()->unique()->values()->all();
        $ordersById = $this->ordersById($companyId, $orderIds);
        $proofsByOrder = $this->activeProofsByOrder(
            $companyId,
            $transferCollections->map(fn (PaymentCollection $c) => $c->stop?->order_id)->filter()->unique()->values()->all(),
        );

        // Delivered sales — the value of orders that actually reached the customer
        // (Delivered stops). Derived from the canonical Order.total, never re-priced here.
        $deliveredSales = round((float) $stops
            ->filter(fn (DeliveryStop $s): bool => $s->status === DeliveryStopStatus::Delivered)
            ->sum(fn (DeliveryStop $s): float => (float) ($ordersById->get($s->order_id)?->total ?? 0.0)), 2);

        $settlementStatus = $this->aggregateSettlementStatus(
            array_map(static fn (array $s): ?string => $s['settlement_status'], $summaries),
        );
        $stopsOutstanding = (int) array_sum(array_column($summaries, 'stops_outstanding'));
        $progress = $this->settlementProgress(
            array_map(static fn (array $s): ?string => $s['settlement_status'], $summaries),
        );
        $closingStage = $this->deriveClosingStage(
            $settlementStatus,
            $recon['status'],
            $stopsOutstanding,
            $ordersCount,
            $deliveredCount + $partialCount + $failedCount,
            $recon['unresolved_variance'],
            $progress['all_reconciled'],
            $progress['any_submitted'],
        );

        // Operational cash movements (approved only) + net physical cash. The `movements` block also
        // carries the reviewable list (Pending shown to Operations) — §7/§12/§13/§14.
        $movements = $this->driverMovements($companyId, $tripIds);
        $cashCollected = round((float) array_sum(array_column($summaries, 'cash_collected')), 2);
        $netCash = round($cashCollected + $movements['approved_cash_in'] - $movements['approved_expenses'], 2);

        return [
            'date' => $date,
            'driver' => [
                'id' => $driver?->id,
                'name' => $driver?->full_name,
                'vehicle_id' => $vehicle?->id,
                'vehicle_plate' => $vehicle?->plate_number,
            ],
            'settlement_status' => $settlementStatus,
            'closing_stage' => $closingStage,
            'overview' => [
                'orders' => $ordersCount,
                'delivered' => $deliveredCount,
                'partial' => $partialCount,
                'failed' => $failedCount,
                'returns' => $returns->count(),
                'delivery_pct' => $this->deliveryPct($deliveredCount, $ordersCount),
                'trips' => $trips->count(),
            ],
            'financial' => [
                'cash_expected' => round((float) array_sum(array_column($summaries, 'cash_expected')), 2),
                'approved_transfers' => $approvedTransfers,
                'actual_cash' => $actualCash,
                'difference' => $difference,
                'is_balanced' => $difference === null ? null : abs($difference) < 0.01,
                // Operational cash — canonical DriverTripMovement (approved only). Real, not "Not available".
                'cash_collected' => $cashCollected,
                'expenses' => $movements['approved_expenses'],
                'cash_in' => $movements['approved_cash_in'],
                'net_cash' => $netCash,
            ],
            'movements' => $movements,
            'collections' => $this->collectionsBreakdown($allNonRejected, $summaries, $deliveredSales, $actualCash, $stops),
            'custody_summary' => $recon['summary'],
            'product_reconciliation' => $recon['products'],
            'damage' => $recon['damage'],
            'shortage_review' => $recon['shortage'],
            'closing_readiness' => $this->closingReadiness($summaries, $stopsOutstanding, $recon, $difference, (int) $movements['pending_count']),
            'timeline' => $this->timeline($trips, $recon['reconciliations']),
            'trips' => $trips->map(fn (Trip $t): array => $this->tripRow($t, $summaries[$t->id]))->values()->all(),
            'orders' => $stops->map(fn (DeliveryStop $stop): array => [
                'order_id' => $stop->order_id,
                'order_number' => $ordersById->get($stop->order_id)?->order_number,
                'customer_name' => $ordersById->get($stop->order_id)?->customer_name,
                'order_value' => $ordersById->get($stop->order_id) !== null
                    ? round((float) $ordersById->get($stop->order_id)->total, 2)
                    : null,
                'payment_method' => $ordersById->get($stop->order_id)?->payment_method,
                'status' => $stop->status->value,
            ])->values()->all(),
            'transfers' => $transferCollections->map(function (PaymentCollection $c) use ($ordersById, $proofsByOrder): array {
                $orderId = $c->stop?->order_id;
                $proof = $orderId !== null ? $proofsByOrder->get($orderId) : null;

                return [
                    'order_id' => $orderId,
                    'order_number' => $orderId !== null ? $ordersById->get($orderId)?->order_number : null,
                    'customer_name' => $orderId !== null ? $ordersById->get($orderId)?->customer_name : null,
                    'amount' => round((float) $c->amount, 2),
                    'payment_type' => $c->payment_type->value,
                    'payment_label' => $c->payment_type->label(),
                    'collection_status' => $c->status,
                    'proof' => $proof !== null ? ['id' => $proof->id, 'state' => $proof->state->value] : null,
                ];
            })->values()->all(),
            'returns' => $returns->map(fn (TripReturn $r): array => [
                'order_id' => $r->order_id,
                'product_name' => $r->product_name,
                'kind' => $r->kind->value,
                'returned_qty' => (float) $r->returned_qty,
                'warehouse_confirmed_qty' => $r->warehouse_confirmed_qty !== null ? (float) $r->warehouse_confirmed_qty : null,
                'driver_liable' => (bool) $r->driver_liable,
                'confirmed' => $r->isConfirmed(),
            ])->values()->all(),
            'goods_remaining' => $this->goodsRemaining($companyId, $tripIds),
        ];
    }

    // ── Row building ────────────────────────────────────────────────────────────

    /**
     * Build one board row per (driver_vehicle_assignment_id, operational_day) from a set
     * of eager-loaded trips. Money is summed from the canonical per-trip engine; goods,
     * damage and shortage from the canonical custody + reconciliation engines.
     *
     * @param  Collection<int, Trip>  $trips
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRows(string $companyId, Collection $trips): Collection
    {
        if ($trips->isEmpty()) {
            return collect();
        }

        $tripIds = $trips->pluck('id')->all();
        $stopBreakdown = $this->stopBreakdownByTrip($tripIds);
        $returnsByTrip = $this->returnsCountByTrip($tripIds);
        $reconByTrip = $this->reconciliationAggregatesByTrip($companyId, $tripIds);
        $valueByTrip = $this->orderValueBreakdownByTrip($tripIds);
        $movementSums = $this->movementSumsByTrip($companyId, $tripIds);

        return $trips
            // ONE row per open Trip/Custody — the canonical operational identity (§7). NOT grouped
            // by calendar day, so a custody spanning midnight stays the SAME single row (§8), and
            // two genuine open custodies never collapse into one (§13).
            ->groupBy(fn (Trip $t): string => (string) $t->id)
            ->map(function (Collection $group) use ($stopBreakdown, $returnsByTrip, $reconByTrip, $valueByTrip, $movementSums): array {
                $trip = $group->first();
                $assignment = $trip->driverVehicleAssignment;
                $driver = $assignment?->driver;
                $vehicle = $assignment?->vehicle;
                $assignmentId = (int) $trip->driver_vehicle_assignment_id;
                $opDay = $this->tripDay($trip);

                $summaries = $group->map(fn (Trip $t): array => $this->settlements->financialSummary($t));

                $orders = (int) $summaries->sum(fn (array $s): int => (int) $s['stops_total']);
                $delivered = (int) $group->sum(fn (Trip $t): int => (int) ($stopBreakdown[$t->id]['delivered'] ?? 0));
                $partial = (int) $group->sum(fn (Trip $t): int => (int) ($stopBreakdown[$t->id]['partial'] ?? 0));
                $failed = (int) $group->sum(fn (Trip $t): int => (int) ($stopBreakdown[$t->id]['failed'] ?? 0));
                $returns = (int) $group->sum(fn (Trip $t): int => (int) $returnsByTrip->get($t->id, 0));

                // Canonical order-value breakdown (Order.total by delivery outcome). Total Sales uses
                // actual delivered value, NOT total assigned order value.
                $ordersValue = round((float) $group->sum(fn (Trip $t): float => (float) ($valueByTrip[$t->id]['orders_value'] ?? 0.0)), 2);
                $deliveredValue = round((float) $group->sum(fn (Trip $t): float => (float) ($valueByTrip[$t->id]['delivered_value'] ?? 0.0)), 2);
                $failedValue = round((float) $group->sum(fn (Trip $t): float => (float) ($valueByTrip[$t->id]['failed_value'] ?? 0.0)), 2);

                // Custody / reconciliation aggregate across the group's trips.
                $damaged = 0.0;
                $shortage = 0.0;
                $onHand = 0.0;
                $reconStatuses = [];
                $unresolvedVariance = false;
                foreach ($group as $t) {
                    $agg = $reconByTrip[$t->id] ?? null;
                    if ($agg === null) {
                        continue;
                    }
                    $damaged += $agg['damaged'];
                    $shortage += $agg['shortage'];
                    $onHand += $agg['on_hand'];
                    $unresolvedVariance = $unresolvedVariance || $agg['unresolved_variance'];
                    if ($agg['status'] !== null) {
                        $reconStatuses[] = $agg['status'];
                    }
                }

                // Operational cash movements (approved only). Net Cash = physical cash collected +
                // approved cash-in (advances) − approved cash-out (expenses). No opening balance is
                // invented (§14); advances are never folded into expenses (§4/§13).
                $cashCollected = round((float) $summaries->sum(fn (array $s): float => (float) $s['cash_collected']), 2);
                $mv = $movementSums[(int) $trip->id] ?? ['expenses' => 0.0, 'cash_in' => 0.0, 'pending' => 0];
                $expenses = round((float) $mv['expenses'], 2);
                $cashIn = round((float) $mv['cash_in'], 2);
                $netCash = round($cashCollected + $cashIn - $expenses, 2);

                $rawStatuses = $summaries->map(fn (array $s): ?string => $s['settlement_status'])->all();
                $settlementStatus = $this->aggregateSettlementStatus($rawStatuses);
                $progress = $this->settlementProgress($rawStatuses);
                $stopsOutstanding = (int) $summaries->sum(fn (array $s): int => (int) $s['stops_outstanding']);
                $reconStatus = $this->worstReconciliationStatus($reconStatuses);

                $finalizedAt = $group
                    ->map(fn (Trip $t) => $t->settlement?->finalized_at)
                    ->filter()
                    ->max();

                return [
                    'assignment_id' => $assignmentId,
                    'operational_date' => $opDay,
                    // Canonical custody identity (§7) — the row IS this one Trip/Custody.
                    'trip_id' => $trip->uuid,
                    'trip_number' => $trip->trip_number,
                    'trip_status' => $trip->status->value,
                    'custody_started_at' => $this->custodyStartedAt($trip),
                    'duplicate_open_custody' => false,
                    'finalized_at' => $finalizedAt !== null ? $finalizedAt->format('c') : null,
                    'driver_id' => $driver?->id,
                    'driver_name' => $driver?->full_name,
                    'vehicle_id' => $vehicle?->id,
                    'vehicle_plate' => $vehicle?->plate_number,
                    'trip_ids' => $group->pluck('uuid')->values()->all(),
                    'orders' => $orders,
                    'delivered' => $delivered,
                    'partial' => $partial,
                    'failed' => $failed,
                    'delivery_pct' => $this->deliveryPct($delivered, $orders),
                    'returns' => $returns,
                    'cash_expected' => round((float) $summaries->sum(fn (array $s): float => (float) $s['cash_expected']), 2),
                    'transfers' => round((float) $summaries->sum(fn (array $s): float => (float) $s['bank_transfers_pending']), 2),
                    'difference' => $this->aggregateDifference($summaries->all()),
                    // Canonical operational value columns (final workspace table — CTO UX correction).
                    'orders_value' => $ordersValue,
                    'delivered_value' => $deliveredValue,
                    'failed_value' => $failedValue,
                    'total_sales' => $deliveredValue,
                    'transfers_paid' => round((float) $summaries->sum(fn (array $s): float => (float) $s['bank_transfers_pending'] + (float) $s['already_paid']), 2),
                    // Operational cash-movement columns (TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001).
                    'cash_collected' => $cashCollected,
                    'expenses' => $expenses,
                    'cash_in' => $cashIn,
                    'net_cash' => $netCash,
                    'pending_movements' => (int) $mv['pending'],
                    'damaged_qty' => round($damaged, 4),
                    'shortage_qty' => round(max(0.0, $shortage), 4),
                    'goods_on_hand' => round($onHand, 4),
                    'reconciliation_status' => $reconStatus,
                    'settlement_status' => $settlementStatus,
                    'closing_stage' => $this->deriveClosingStage(
                        $settlementStatus,
                        $reconStatus,
                        $stopsOutstanding,
                        $orders,
                        $delivered + $partial + $failed,
                        $unresolvedVariance,
                        $progress['all_reconciled'],
                        $progress['any_submitted'],
                    ),
                ];
            })
            ->values();
    }

    /**
     * The best available custody-start timestamp for display. The row IDENTITY is the trip itself,
     * so this is a reporting dimension only — a custody spanning midnight keeps the same row (§8).
     */
    private function custodyStartedAt(Trip $trip): ?string
    {
        $ts = $trip->trip_started_at ?? $trip->dispatched_at ?? $trip->created_at;

        return $ts !== null ? $ts->format('c') : null;
    }

    /**
     * A driver holding more than one OPEN operational custody is an invariant violation — legacy
     * data that predates the write-side guard, or genuine corruption. Surface EVERY such row as
     * needs-review with an explicit flag; never latest()/first()/DISTINCT it away or hide it (§13).
     * The write-side invariant (TripService::assertDriverHasNoOtherOpenCustody) prevents NEW ones.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function flagDuplicateOpenCustody(Collection $rows): Collection
    {
        $countByDriver = $rows
            ->filter(fn (array $r): bool => ($r['driver_id'] ?? null) !== null)
            ->groupBy(fn (array $r) => $r['driver_id'])
            ->map->count();

        return $rows->map(function (array $r) use ($countByDriver): array {
            $driverId = $r['driver_id'] ?? null;
            $duplicate = $driverId !== null && ($countByDriver[$driverId] ?? 0) > 1;
            $r['duplicate_open_custody'] = $duplicate;
            if ($duplicate) {
                $r['closing_stage'] = self::STAGE_NEEDS_REVIEW;
            }

            return $r;
        })->values();
    }

    /**
     * The canonical operational KPIs, aggregated over the currently-visible rows (the Active board's
     * open custodies). Every figure is a REAL aggregate of the canonical per-row values already
     * computed in buildRows; delivery_rate is a derived percentage. `total_expenses` / `net_cash`
     * are now REAL sums of the canonical DriverTripMovement authority (approved movements only) —
     * TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 §12/§14. A real zero is EGP 0.00, never
     * "Not available"; advances (cash-in) are excluded from expenses (§13).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float|null>
     */
    private function kpis(Collection $rows): array
    {
        $totalOrders = (int) $rows->sum(fn (array $r): int => (int) $r['orders']);
        $totalDelivered = (int) $rows->sum(fn (array $r): int => (int) $r['delivered']);

        return [
            'total_orders' => $totalOrders,
            'total_delivered' => $totalDelivered,
            'total_failed' => (int) $rows->sum(fn (array $r): int => (int) $r['failed']),
            'delivery_rate' => $totalOrders > 0 ? (int) round($totalDelivered / $totalOrders * 100) : 0,
            'total_sales' => round((float) $rows->sum(fn (array $r): float => (float) $r['total_sales']), 2),
            'total_transfers_paid' => round((float) $rows->sum(fn (array $r): float => (float) $r['transfers_paid']), 2),
            // Approved cash-out expenses and net physical cash (cash collected + approved cash-in −
            // approved cash-out), summed across visible custodies. Canonical, no longer "Not available".
            'total_expenses' => round((float) $rows->sum(fn (array $r): float => (float) ($r['expenses'] ?? 0)), 2),
            'net_cash' => round((float) $rows->sum(fn (array $r): float => (float) ($r['net_cash'] ?? 0)), 2),
            'total_cash_in' => round((float) $rows->sum(fn (array $r): float => (float) ($r['cash_in'] ?? 0)), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function tripRow(Trip $trip, array $summary): array
    {
        return [
            'id' => $trip->uuid,
            'trip_number' => $trip->trip_number,
            'settlement_status' => $summary['settlement_status'],
            'cash_expected' => round((float) $summary['cash_expected'], 2),
            'difference' => $summary['discrepancy'] !== null ? round((float) $summary['discrepancy'], 2) : null,
            'stops_total' => (int) $summary['stops_total'],
            'stops_outstanding' => (int) $summary['stops_outstanding'],
        ];
    }

    // ── Collections (§6, §7) ─────────────────────────────────────────────────────

    /**
     * The operational commercial summary, split by canonical payment type. Expected Collection is
     * the SUM of the immutable per-stop handoff snapshots ({@see DeliveryService::generateStops()}),
     * i.e. what was collectible from each customer at the moment the order entered custody — NOT a
     * read-time recompute from the now-mutable order state. It is available only when every stop
     * carries a snapshot; a stop that predates the snapshot yields "unavailable", never a partial or
     * backfilled figure. Only physical cash is reconciled at settlement (see cash_expected).
     *
     * @param  Collection<int, PaymentCollection>  $collections
     * @param  array<int|string, array<string, mixed>>  $summaries
     * @param  Collection<int, DeliveryStop>  $stops
     * @return array<string, mixed>
     */
    private function collectionsBreakdown(Collection $collections, array $summaries, float $deliveredSales, ?float $actualCash, Collection $stops): array
    {
        $sumType = fn (PaymentType $type): float => round(
            (float) $collections->where('payment_type', $type)->sum(fn (PaymentCollection $c): float => (float) $c->amount),
            2,
        );

        $cash = $sumType(PaymentType::Cash);
        $bank = $sumType(PaymentType::BankTransfer);
        $card = $sumType(PaymentType::Card);
        $alreadyPaid = $sumType(PaymentType::AlreadyPaid);
        $totalCollected = round($cash + $bank + $card + $alreadyPaid, 2);

        // Expected Collection = Σ of the per-stop handoff snapshots. Available ONLY when every stop
        // carries one; a null (pre-snapshot / historical) stop makes the whole figure unavailable
        // rather than a misleading partial sum — never backfilled from current order state.
        $snapshots = $stops->map(fn (DeliveryStop $s) => $s->expected_collection_at_handoff);
        $expectedAvailable = $stops->isNotEmpty() && $snapshots->every(fn ($v): bool => $v !== null);
        $expectedCollection = $expectedAvailable
            ? round((float) $snapshots->sum(fn ($v): float => (float) $v), 2)
            : null;

        // What customers actually paid during the trip (physical cash + electronic), excluding the
        // prepaid "already_paid" portion that was never collectible, against the handoff expectation.
        $collectedFromCustomers = round($cash + $bank + $card, 2);
        $collectionDifference = $expectedCollection !== null
            ? round($collectedFromCustomers - $expectedCollection, 2)
            : null;

        return [
            'cash' => $cash,
            'bank_transfer' => $bank,
            'card' => $card,
            'already_paid' => $alreadyPaid,
            'total_collected' => $totalCollected,
            'delivered_sales' => $deliveredSales,
            'actual_collected' => $totalCollected,
            'cash_expected' => round((float) array_sum(array_column($summaries, 'cash_expected')), 2),
            'actual_cash' => $actualCash,
            'expected_collection' => $expectedCollection,
            'expected_collection_available' => $expectedAvailable,
            'collection_difference' => $collectionDifference,
        ];
    }

    // ── Reconciliation / custody surfacing (§8, §9, §11, §12) ────────────────────

    /**
     * Full reconciliation drill-down for a driver-day's trips: the per-product lines, the
     * aggregate custody summary, the damage list and the shortage/variance list, plus the
     * loaded reconciliation headers (for the timeline). When no shift reconciliation has
     * been opened, custody figures fall back to the vehicle-inventory engine and the
     * warehouse-counted fields report an honest "not reconciled" state.
     *
     * @param  list<int>  $tripIds
     * @return array<string, mixed>
     */
    private function reconciliationForTrips(string $companyId, array $tripIds): array
    {
        $assignmentIds = $this->opsAssignmentIds($companyId, $tripIds);

        /** @var Collection<int, VehicleShiftReconciliation> $reconciliations */
        $reconciliations = $assignmentIds === []
            ? collect()
            : VehicleShiftReconciliation::query()
                ->where('company_id', $companyId)
                ->whereIn('vehicle_assignment_id', $assignmentIds)
                ->with('lines')
                ->get();

        /** @var Collection<int, VehicleInventoryItem> $custodyItems */
        $custodyItems = $assignmentIds === []
            ? collect()
            : VehicleInventoryItem::query()
                ->where('company_id', $companyId)
                ->whereIn('vehicle_assignment_id', $assignmentIds)
                ->get();

        $lines = $reconciliations->flatMap(fn (VehicleShiftReconciliation $r) => $r->lines);
        $reconciledItemIds = $lines->pluck('vehicle_inventory_item_id')->filter()->unique()->all();
        $hasReconciliation = $lines->isNotEmpty();

        // Tenant-safe product-name lookup, built from the company-scoped custody items
        // (a reconciliation line stores only a sku_snapshot, not a display name).
        $namesByProduct = $custodyItems
            ->groupBy('product_id')
            ->map(fn (Collection $g): string => (string) $g->first()->name_snapshot);
        $nameFor = fn (?string $productId, ?string $sku): string => $productId !== null && $namesByProduct->has($productId)
            ? $namesByProduct->get($productId)
            : (string) ($sku ?? '—');

        // Per-product reconciliation rows (§9). Reconciliation lines win; custody items with
        // no line are surfaced from the custody engine and marked as not-yet-reconciled.
        $products = $lines->map(function (VehicleShiftReconciliationLine $l) use ($nameFor): array {
            $expected = (float) $l->quantity_returned_expected;
            $accepted = (float) $l->quantity_accepted;
            $damaged = (float) $l->quantity_damaged;
            $variance = (float) $l->variance;

            return [
                'product_id' => (string) $l->product_id,
                'product_name' => $nameFor($l->product_id, $l->sku_snapshot),
                'loaded' => round((float) $l->quantity_loaded, 4),
                'delivered' => round((float) $l->quantity_delivered, 4),
                'expected_return' => round($expected, 4),          // = loaded − delivered (canonical)
                'actual_good_return' => round($accepted, 4),        // accepted good stock (warehouse)
                'actual_return' => round((float) $l->quantity_returned_actual, 4),
                'damaged' => round($damaged, 4),
                'shortage' => round(max(0.0, $variance), 4),        // variance kept visible
                'variance' => round($variance, 4),
                'reconciliation_status' => $l->warehouse_receipt_at !== null ? 'received' : 'pending',
                'warehouse_received' => $l->warehouse_receipt_at !== null,
                'source' => 'reconciliation',
            ];
        });

        $custodyOnly = $custodyItems
            ->reject(fn (VehicleInventoryItem $i): bool => in_array($i->id, $reconciledItemIds, true))
            ->map(function (VehicleInventoryItem $i): array {
                $loaded = (float) $i->quantity_loaded;
                $delivered = (float) $i->quantity_delivered;

                return [
                    'product_id' => (string) $i->product_id,
                    'product_name' => (string) $i->name_snapshot,
                    'loaded' => round($loaded, 4),
                    'delivered' => round($delivered, 4),
                    'expected_return' => round(max(0.0, $loaded - $delivered), 4),
                    'actual_good_return' => null,     // not warehouse-counted yet
                    'actual_return' => null,
                    'damaged' => null,                // no reconciliation opened → unknown, not zero
                    'shortage' => null,
                    'variance' => null,
                    'remaining' => round((float) $i->quantity_on_hand, 4),
                    'reconciliation_status' => 'not_reconciled',
                    'warehouse_received' => false,
                    'source' => 'custody',
                ];
            });

        $allProducts = $products->merge($custodyOnly)->values()->all();

        $totalDamaged = round((float) $lines->sum(fn (VehicleShiftReconciliationLine $l): float => (float) $l->quantity_damaged), 4);
        $totalShortage = round((float) $lines->sum(
            fn (VehicleShiftReconciliationLine $l): float => max(0.0, (float) $l->variance),
        ), 4);
        $unresolvedVariance = $lines->contains(
            fn (VehicleShiftReconciliationLine $l): bool => abs((float) $l->variance) > self::EPSILON,
        );

        $status = $this->worstReconciliationStatus(
            $reconciliations->map(fn (VehicleShiftReconciliation $r): string => $r->status->value)->all(),
        );

        $linesReceived = $lines->filter(fn (VehicleShiftReconciliationLine $l): bool => $l->warehouse_receipt_at !== null)->count();

        $summary = [
            'reconciliation_available' => $hasReconciliation,
            'reconciliation_status' => $status,
            'total_loaded' => round((float) $custodyItems->sum(fn (VehicleInventoryItem $i): float => (float) $i->quantity_loaded), 4),
            'total_delivered' => round((float) $custodyItems->sum(fn (VehicleInventoryItem $i): float => (float) $i->quantity_delivered), 4),
            'expected_return' => round((float) $lines->sum(fn (VehicleShiftReconciliationLine $l): float => (float) $l->quantity_returned_expected), 4),
            'actual_return' => round((float) $lines->sum(fn (VehicleShiftReconciliationLine $l): float => (float) $l->quantity_returned_actual), 4),
            'accepted' => round((float) $lines->sum(fn (VehicleShiftReconciliationLine $l): float => (float) $l->quantity_accepted), 4),
            'damaged' => $totalDamaged,
            'shortage' => $totalShortage,
            'remaining_on_hand' => round((float) $custodyItems->sum(fn (VehicleInventoryItem $i): float => (float) $i->quantity_on_hand), 4),
            'lines_total' => $lines->count(),
            'lines_received' => $linesReceived,
        ];

        // Damage (§11) — kept separate from good stock. WasteInvestigation disposition is a
        // documented deferred gap (see the action docblock): damage is visible, but the waste
        // record is not yet raised.
        $damage = [
            'available' => $hasReconciliation,
            'gap' => 'waste_investigation_deferred',
            'items' => $lines
                ->filter(fn (VehicleShiftReconciliationLine $l): bool => (float) $l->quantity_damaged > self::EPSILON)
                ->map(fn (VehicleShiftReconciliationLine $l): array => [
                    'product_name' => $nameFor($l->product_id, $l->sku_snapshot),
                    'quantity' => round((float) $l->quantity_damaged, 4),
                    'reason' => $l->damage_reason,
                    'warehouse_receipt_at' => optional($l->warehouse_receipt_at)?->toIso8601String(),
                ])->values()->all(),
        ];

        // Shortage (§12) — the reconciliation variance. NOT auto-charged: WarehouseLiability
        // has no driver/vehicle attribution, a documented deferred gap.
        $shortage = [
            'available' => $hasReconciliation,
            'gap' => 'liability_attribution_deferred',
            'liability_confirmed' => false,
            'items' => $lines
                ->filter(fn (VehicleShiftReconciliationLine $l): bool => abs((float) $l->variance) > self::EPSILON)
                ->map(fn (VehicleShiftReconciliationLine $l): array => [
                    'product_name' => $nameFor($l->product_id, $l->sku_snapshot),
                    'variance' => round((float) $l->variance, 4),
                    'reconciliation_status' => $l->warehouse_receipt_at !== null ? 'received' : 'pending',
                    'resolution' => $l->variance_resolution,
                ])->values()->all(),
        ];

        return [
            'status' => $status,
            'has_custody' => $custodyItems->isNotEmpty(),
            'unresolved_variance' => $unresolvedVariance,
            'summary' => $summary,
            'products' => $allProducts,
            'damage' => $damage,
            'shortage' => $shortage,
            'reconciliations' => $reconciliations,
        ];
    }

    /**
     * Light per-trip reconciliation aggregates for the board rows (damaged / shortage /
     * status / on-hand), bulk-loaded to avoid an N+1 across the driver list.
     *
     * @param  list<int>  $tripIds
     * @return array<int, array{damaged: float, shortage: float, on_hand: float, status: ?string, has_custody: bool, unresolved_variance: bool}>
     */
    private function reconciliationAggregatesByTrip(string $companyId, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        // trip_id → [ops assignment ids]
        $assignments = VehicleAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('trip_id', $tripIds)
            ->get(['id', 'trip_id']);

        if ($assignments->isEmpty()) {
            return [];
        }

        $assignmentIds = $assignments->pluck('id')->all();

        $reconByAssignment = VehicleShiftReconciliation::query()
            ->where('company_id', $companyId)
            ->whereIn('vehicle_assignment_id', $assignmentIds)
            ->with('lines')
            ->get()
            ->keyBy('vehicle_assignment_id');

        $onHandByAssignment = VehicleInventoryItem::query()
            ->where('company_id', $companyId)
            ->whereIn('vehicle_assignment_id', $assignmentIds)
            ->get()
            ->groupBy('vehicle_assignment_id');

        $out = [];
        foreach ($assignments as $a) {
            $recon = $reconByAssignment->get($a->id);
            $items = $onHandByAssignment->get($a->id, collect());

            $lines = $recon?->lines ?? collect();
            $damaged = (float) $lines->sum(fn (VehicleShiftReconciliationLine $l): float => (float) $l->quantity_damaged);
            $shortage = (float) $lines->sum(fn (VehicleShiftReconciliationLine $l): float => max(0.0, (float) $l->variance));
            $unresolved = $lines->contains(fn (VehicleShiftReconciliationLine $l): bool => abs((float) $l->variance) > self::EPSILON);
            $onHand = (float) $items->sum(fn (VehicleInventoryItem $i): float => (float) $i->quantity_on_hand);

            $agg = [
                'damaged' => $damaged,
                'shortage' => $shortage,
                'on_hand' => $onHand,
                'status' => $recon?->status->value,
                'has_custody' => $items->isNotEmpty(),
                'unresolved_variance' => $unresolved,
            ];

            // Fold multiple assignments onto the same trip (rare) additively.
            $existing = $out[$a->trip_id] ?? null;
            $out[$a->trip_id] = $existing === null ? $agg : [
                'damaged' => $existing['damaged'] + $damaged,
                'shortage' => $existing['shortage'] + $shortage,
                'on_hand' => $existing['on_hand'] + $onHand,
                'status' => $this->worstReconciliationStatus(array_filter([$existing['status'], $agg['status']])),
                'has_custody' => $existing['has_custody'] || $agg['has_custody'],
                'unresolved_variance' => $existing['unresolved_variance'] || $unresolved,
            ];
        }

        return $out;
    }

    // ── Closing readiness (§14) + timeline (§16) ─────────────────────────────────

    /**
     * Canonical closing readiness. `ready` mirrors the engine's real gate (every trip
     * settlement Reconciled — {@see SettlementService::finalize()} is only reachable from
     * Reconciled); the blockers surface the operational reasons a close should wait, so the
     * UI never claims Ready while a canonical blocker remains (§14).
     *
     * §18: unresolved Pending driver movements are also a closing blocker — they can materially
     * change the driver's cash position, so the readiness rollup reports Needs Review rather than
     * letting the operator treat the position as settled. This is the read-side operational
     * readiness signal ONLY; the hard finalize guard (SettlementService::finalize, which requires
     * every trip settlement Reconciled) is the certified authority and is deliberately NOT changed.
     *
     * @param  array<int|string, array<string, mixed>>  $summaries
     * @param  array<string, mixed>  $recon
     * @return array{ready: bool, blockers: list<string>}
     */
    private function closingReadiness(array $summaries, int $stopsOutstanding, array $recon, ?float $difference, int $pendingMovements = 0): array
    {
        $statuses = array_map(static fn (array $s): ?string => $s['settlement_status'], $summaries);
        $allReconciled = $statuses !== [] && ! in_array(
            false,
            array_map(static fn (?string $s): bool => $s === SettlementStatus::Reconciled->value, $statuses),
            true,
        );
        $allFinalized = $statuses !== [] && ! in_array(
            false,
            array_map(static fn (?string $s): bool => $s === SettlementStatus::Finalized->value, $statuses),
            true,
        );

        $blockers = [];
        if ($stopsOutstanding > 0) {
            $blockers[] = 'stops_outstanding';
        }
        if ($recon['has_custody'] && ! ($recon['summary']['reconciliation_available'] ?? false)) {
            $blockers[] = 'reconciliation_not_opened';
        }
        if ($recon['unresolved_variance']) {
            $blockers[] = 'unresolved_variance';
        }
        if ($difference !== null && abs($difference) >= 0.01) {
            $blockers[] = 'cash_difference';
        }
        if (! $allReconciled && ! $allFinalized) {
            $blockers[] = 'settlement_not_reconciled';
        }
        if ($pendingMovements > 0) {
            $blockers[] = 'pending_movements';
        }

        return [
            'ready' => $allReconciled && ! $allFinalized && $pendingMovements === 0,
            'blockers' => $blockers,
        ];
    }

    /**
     * The driver-day timeline (§16) from canonical timestamps only — trip lifecycle,
     * reconciliation open/close, and settlement submit/reconcile/finalize. Null stamps
     * are dropped; the list is ordered.
     *
     * @param  Collection<int, Trip>  $trips
     * @param  Collection<int, VehicleShiftReconciliation>  $reconciliations
     * @return list<array{code: string, at: string}>
     */
    private function timeline(Collection $trips, Collection $reconciliations): array
    {
        $events = [];
        $push = static function (string $code, $at) use (&$events): void {
            if ($at !== null) {
                $events[] = ['code' => $code, 'at' => $at instanceof DateTimeInterface ? $at->format('c') : (string) $at];
            }
        };

        foreach ($trips as $t) {
            $push('dispatched', $t->dispatched_at);
            $push('trip_started', $t->trip_started_at);
            $push('trip_finished', $t->trip_finished_at);
            $push('cash_submitted', $t->settlement?->submitted_at);
            $push('reconciled', $t->settlement?->reconciled_at);
            $push('closed', $t->settlement?->finalized_at);
        }
        foreach ($reconciliations as $r) {
            $push('reconciliation_opened', $r->opened_at);
            $push('reconciliation_completed', $r->completed_at);
        }

        usort($events, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return $events;
    }

    // ── Status derivation ────────────────────────────────────────────────────────

    /**
     * Aggregate the per-trip settlement states into one driver-day money-settlement state.
     * (The canonical SettlementStatus rollup — unchanged from the original read model.)
     *
     * @param  list<string|null>  $statuses
     */
    private function aggregateSettlementStatus(array $statuses): string
    {
        $allFinalized = $statuses !== [] && ! in_array(
            false,
            array_map(static fn (?string $s): bool => $s === SettlementStatus::Finalized->value, $statuses),
            true,
        );
        if ($allFinalized) {
            return self::STATUS_SETTLED;
        }

        if (in_array(SettlementStatus::Disputed->value, $statuses, true)) {
            return self::STATUS_DISPUTED;
        }

        if (in_array(SettlementStatus::Submitted->value, $statuses, true)
            || in_array(SettlementStatus::Reconciled->value, $statuses, true)) {
            return self::STATUS_UNDER_REVIEW;
        }

        return self::STATUS_NEEDS_REVIEW;
    }

    /**
     * Map canonical facts to an operational closing stage (§13). A read-only rollup label;
     * never persisted, never a competing lifecycle.
     *
     * `ready_for_closing` requires EVERY trip settlement to be Reconciled — the same gate the
     * canonical {@see SettlementService::finalize()} enforces. A merely-Submitted settlement
     * (driver cash handed in, operator still reconciling) reads as warehouse-counting, not
     * ready, so the UI never claims Ready before the canonical gate is met.
     */
    private function deriveClosingStage(
        string $settlementStatus,
        ?string $reconStatus,
        int $stopsOutstanding,
        int $ordersTotal,
        int $deliveryOutcomes,
        bool $unresolvedVariance,
        bool $allReconciled,
        bool $anySubmitted,
    ): string {
        if ($settlementStatus === self::STATUS_SETTLED) {
            return self::STAGE_CLOSED;
        }
        if ($settlementStatus === self::STATUS_DISPUTED
            || $reconStatus === ReconciliationStatus::Disputed->value
            || $unresolvedVariance) {
            return self::STAGE_NEEDS_REVIEW;
        }
        if ($allReconciled) {
            return self::STAGE_READY_FOR_CLOSING;
        }
        if ($anySubmitted || $reconStatus === ReconciliationStatus::Open->value) {
            return self::STAGE_WAREHOUSE_COUNTING;
        }
        if ($ordersTotal > 0 && $stopsOutstanding === 0) {
            return self::STAGE_READY_FOR_RETURN;
        }
        if ($deliveryOutcomes > 0) {
            return self::STAGE_IN_OPERATION;
        }

        // Assigned/loaded but nothing delivered yet — the earliest operational stage.
        return self::STAGE_OPEN_CUSTODY;
    }

    /**
     * Reconciled/submitted flags for the closing-stage derivation, from the per-trip
     * settlement statuses.
     *
     * @param  list<string|null>  $statuses
     * @return array{all_reconciled: bool, any_submitted: bool}
     */
    private function settlementProgress(array $statuses): array
    {
        return [
            'all_reconciled' => $statuses !== [] && ! in_array(
                false,
                array_map(static fn (?string $s): bool => $s === SettlementStatus::Reconciled->value, $statuses),
                true,
            ),
            'any_submitted' => in_array(SettlementStatus::Submitted->value, $statuses, true),
        ];
    }

    /**
     * The most attention-worthy reconciliation status across a set: Disputed ▸ Open ▸
     * Completed ▸ Approved. Null when nothing was opened.
     *
     * @param  list<string>  $statuses
     */
    private function worstReconciliationStatus(array $statuses): ?string
    {
        $statuses = array_values(array_filter($statuses));
        if ($statuses === []) {
            return null;
        }
        foreach ([ReconciliationStatus::Disputed, ReconciliationStatus::Open, ReconciliationStatus::Completed, ReconciliationStatus::Approved] as $rank) {
            if (in_array($rank->value, $statuses, true)) {
                return $rank->value;
            }
        }

        return $statuses[0];
    }

    /**
     * The driver-day cash difference: the sum of the canonical per-trip discrepancies,
     * or null when no trip has had the driver's cash submitted yet.
     *
     * @param  array<int|string, array<string, mixed>>  $summaries
     */
    private function aggregateDifference(array $summaries): ?float
    {
        $discrepancies = array_values(array_filter(
            array_column($summaries, 'discrepancy'),
            static fn ($v): bool => $v !== null,
        ));

        if ($discrepancies === []) {
            return null;
        }

        return round((float) array_sum($discrepancies), 2);
    }

    // ── Filters / sort ────────────────────────────────────────────────────────────

    /**
     * List-only narrowing: driver/vehicle search, settlement/closing status, and the
     * needs-review / has-damage / has-shortage operational flags (§18). Applied after the
     * rows (and KPIs) are built.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyListFilters(Collection $rows, array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn (array $row): bool => str_contains(mb_strtolower((string) $row['driver_name']), $needle)
                || str_contains(mb_strtolower((string) $row['vehicle_plate']), $needle));
        }

        $status = $filters['status'] ?? null;
        if (in_array($status, [self::STATUS_NEEDS_REVIEW, self::STATUS_UNDER_REVIEW, self::STATUS_DISPUTED, self::STATUS_SETTLED], true)) {
            $rows = $rows->filter(fn (array $row): bool => $row['settlement_status'] === $status);
        }

        if (($filters['stage'] ?? null) !== null && $filters['stage'] !== '') {
            $rows = $rows->filter(fn (array $row): bool => $row['closing_stage'] === $filters['stage']);
        }

        if (! empty($filters['has_damage'])) {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['damaged_qty'] > self::EPSILON);
        }
        if (! empty($filters['has_shortage'])) {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['shortage_qty'] > self::EPSILON);
        }
        if (! empty($filters['needs_review'])) {
            $rows = $rows->filter(fn (array $row): bool => $row['closing_stage'] === self::STAGE_NEEDS_REVIEW);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, string $sort, string $dir): Collection
    {
        $desc = strtolower($dir) === 'desc';
        $sorted = match ($sort) {
            'date' => $rows->sortBy(fn (array $r): string => (string) ($r['operational_date'] ?? ''), SORT_REGULAR, $desc),
            'difference' => $rows->sortBy(fn (array $r): float => (float) ($r['difference'] ?? 0), SORT_REGULAR, $desc),
            'delivery_pct' => $rows->sortBy(fn (array $r): int => (int) ($r['delivery_pct'] ?? 0), SORT_REGULAR, $desc),
            default => $rows->sortBy(fn (array $r): string => mb_strtolower((string) ($r['driver_name'] ?? '')), SORT_REGULAR, $desc),
        };

        return $sorted->values();
    }

    // ── Trip / key helpers ────────────────────────────────────────────────────────

    private function tripDay(Trip $trip): string
    {
        $anchor = $trip->trip_started_at ?? $trip->dispatched_at ?? $trip->created_at;

        return $anchor !== null ? $anchor->format('Y-m-d') : '';
    }

    /**
     * The OPERATIONS vehicle-assignment ids for a set of trips (the custody grain).
     *
     * @param  list<int>  $tripIds
     * @return list<string>
     */
    private function opsAssignmentIds(string $companyId, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        return VehicleAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('trip_id', $tripIds)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<int>  $tripIds
     * @return array<int, array{delivered: int, partial: int, failed: int}>
     */
    private function stopBreakdownByTrip(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $rows = DeliveryStop::query()
            ->whereIn('trip_id', $tripIds)
            ->groupBy('trip_id', 'status')
            ->selectRaw('trip_id, status, COUNT(*) as aggregate')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->trip_id] ??= ['delivered' => 0, 'partial' => 0, 'failed' => 0];
            $status = $r->status instanceof DeliveryStopStatus ? $r->status : DeliveryStopStatus::tryFrom((string) $r->status);
            $count = (int) $r->aggregate;
            if ($status === DeliveryStopStatus::Delivered) {
                $out[$r->trip_id]['delivered'] += $count;
            } elseif ($status === DeliveryStopStatus::Partial) {
                $out[$r->trip_id]['partial'] += $count;
            } elseif ($status === DeliveryStopStatus::Failed) {
                $out[$r->trip_id]['failed'] += $count;
            }
        }

        return $out;
    }

    /**
     * Canonical order-value breakdown per trip: SUM(Order.total) grouped by delivery-stop outcome.
     * `orders_value` = all stops (total assigned); `delivered_value` = delivered stops (the actual
     * delivered/sold value used for Total Sales); `failed_value` = failed/exception stops.
     *
     * @param  list<int>  $tripIds
     * @return array<int, array{orders_value: float, delivered_value: float, failed_value: float}>
     */
    private function orderValueBreakdownByTrip(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $rows = DeliveryStop::query()
            ->whereIn('distribution_delivery_stops.trip_id', $tripIds)
            ->join('orders', 'orders.id', '=', 'distribution_delivery_stops.order_id')
            ->groupBy('distribution_delivery_stops.trip_id', 'distribution_delivery_stops.status')
            ->selectRaw('distribution_delivery_stops.trip_id as trip_id, distribution_delivery_stops.status as status, SUM(orders.total) as value')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->trip_id] ??= ['orders_value' => 0.0, 'delivered_value' => 0.0, 'failed_value' => 0.0];
            $status = $r->status instanceof DeliveryStopStatus ? $r->status : DeliveryStopStatus::tryFrom((string) $r->status);
            $value = (float) $r->value;
            $out[$r->trip_id]['orders_value'] += $value;
            if ($status === DeliveryStopStatus::Delivered) {
                $out[$r->trip_id]['delivered_value'] += $value;
            } elseif ($status === DeliveryStopStatus::Failed) {
                $out[$r->trip_id]['failed_value'] += $value;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $tripIds
     * @return Collection<int, int>
     */
    private function returnsCountByTrip(array $tripIds): Collection
    {
        if ($tripIds === []) {
            return collect();
        }

        return TripReturn::query()
            ->whereIn('trip_id', $tripIds)
            ->groupBy('trip_id')
            ->selectRaw('trip_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'trip_id')
            ->map(fn ($v): int => (int) $v);
    }

    /**
     * @param  list<string>  $orderIds
     * @return Collection<string, Order>
     */
    private function ordersById(string $companyId, array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return Order::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * The ACTIVE payment proof per order (superseded_at IS NULL), company-scoped.
     *
     * @param  list<string>  $orderIds
     * @return Collection<string, PaymentProof>
     */
    private function activeProofsByOrder(string $companyId, array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return PaymentProof::query()
            ->where('company_id', $companyId)
            ->whereIn('order_id', $orderIds)
            ->whereNull('superseded_at')
            ->get()
            ->keyBy('order_id');
    }

    private function deliveryPct(int $delivered, int $orders): int
    {
        return $orders > 0 ? (int) round($delivered / $orders * 100) : 0;
    }

    /**
     * Goods still on the vehicle, summed per product across the driver's trips.
     *
     * @param  list<int>  $tripIds
     * @return list<array{product_id: string, product_name: string, quantity_on_hand: float}>
     */
    private function goodsRemaining(string $companyId, array $tripIds): array
    {
        $assignmentIds = $this->opsAssignmentIds($companyId, $tripIds);

        if ($assignmentIds === []) {
            return [];
        }

        return VehicleInventoryItem::query()
            ->where('company_id', $companyId)
            ->whereIn('vehicle_assignment_id', $assignmentIds)
            ->get()
            ->groupBy('product_id')
            ->map(fn (Collection $group): array => [
                'product_id' => (string) $group->first()->product_id,
                'product_name' => (string) $group->first()->name_snapshot,
                'quantity_on_hand' => round((float) $group->sum(fn (VehicleInventoryItem $i): float => (float) $i->quantity_on_hand), 4),
            ])
            ->values()
            ->all();
    }

    // ── Driver trip movements (operational cash) — TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 ──

    /**
     * Approved operational cash sums per trip, for the board rows/KPIs. ONLY Approved (and its
     * terminal Settled) movements count (§5/§41); Pending/Rejected never touch the totals. An
     * advance is cash IN and is NEVER folded into Expenses (§4/§12/§13).
     *
     * @param  list<int>  $tripIds
     * @return array<int, array{expenses: float, cash_in: float, pending: int}>
     */
    private function movementSumsByTrip(string $companyId, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $out = [];
        $movements = DriverTripMovement::query()
            ->where('company_id', $companyId)
            ->whereIn('trip_id', $tripIds)
            ->get(['trip_id', 'direction', 'amount', 'status']);

        foreach ($movements as $m) {
            $tid = (int) $m->trip_id;
            $out[$tid] ??= ['expenses' => 0.0, 'cash_in' => 0.0, 'pending' => 0];

            $status = $m->status instanceof DriverTripMovementStatus ? $m->status : DriverTripMovementStatus::from((string) $m->status);
            if ($status === DriverTripMovementStatus::Pending) {
                $out[$tid]['pending']++;
            }
            if (! $status->countsTowardTotals()) {
                continue;
            }

            $direction = $m->direction instanceof DriverTripMovementDirection ? $m->direction : DriverTripMovementDirection::from((string) $m->direction);
            if ($direction === DriverTripMovementDirection::CashOut) {
                $out[$tid]['expenses'] += (float) $m->amount;
            } else {
                $out[$tid]['cash_in'] += (float) $m->amount;
            }
        }

        return $out;
    }

    /**
     * The full driver-movement block for the drill-down (§7/§12/§13): the reviewable movement list
     * (Operations sees Pending here) plus the approved-only Expense / Cash-In totals and the
     * approved cash-out breakdown by category. Physical cash-collected + net cash are folded in by
     * the caller from the canonical settlement summary.
     *
     * @param  list<int>  $tripIds
     * @return array<string, mixed>
     */
    private function driverMovements(string $companyId, array $tripIds): array
    {
        $movements = $tripIds === [] ? collect() : DriverTripMovement::query()
            ->where('company_id', $companyId)
            ->whereIn('trip_id', $tripIds)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->get();

        $expenses = 0.0;
        $cashIn = 0.0;
        $pending = 0;
        $byCategory = [];
        $items = [];

        foreach ($movements as $m) {
            $category = $m->category instanceof DriverTripMovementCategory ? $m->category : DriverTripMovementCategory::from((string) $m->category);
            $direction = $m->direction instanceof DriverTripMovementDirection ? $m->direction : DriverTripMovementDirection::from((string) $m->direction);
            $status = $m->status instanceof DriverTripMovementStatus ? $m->status : DriverTripMovementStatus::from((string) $m->status);

            if ($status === DriverTripMovementStatus::Pending) {
                $pending++;
            }
            if ($status->countsTowardTotals()) {
                if ($direction === DriverTripMovementDirection::CashOut) {
                    $expenses += (float) $m->amount;
                    $byCategory[$category->value] = round(($byCategory[$category->value] ?? 0.0) + (float) $m->amount, 2);
                } else {
                    $cashIn += (float) $m->amount;
                }
            }

            $items[] = [
                'id' => $m->id,
                'category' => $category->value,
                'direction' => $direction->value,
                'is_expense' => $category->isExpense(),
                'amount' => (float) $m->amount,
                'note' => $m->note,
                'status' => $status->value,
                'occurred_at' => optional($m->occurred_at)->toIso8601String(),
                'has_receipt' => $m->hasReceipt(),
                'reviewed_by' => $m->reviewed_by,
                'reviewed_at' => optional($m->reviewed_at)->toIso8601String(),
            ];
        }

        return [
            'available' => true, // the canonical movement authority now exists
            'items' => $items,
            'pending_count' => $pending,
            'approved_expenses' => round($expenses, 2),
            'approved_cash_in' => round($cashIn, 2),
            'expenses_by_category' => $byCategory,
        ];
    }
}
