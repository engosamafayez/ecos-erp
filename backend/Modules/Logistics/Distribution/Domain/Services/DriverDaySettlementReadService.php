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

    /**
     * The canonical delivery-stop outcomes this board counts, and the row key each one lands in.
     * `Failed`, `Returned` and `Skipped` are SEPARATE canonical statuses (DeliveryStopStatus) and
     * keep separate buckets — the board's third outcome is their disjoint union, computed from
     * these, never a rename of one to another. Pending / InProgress are outstanding, not outcomes.
     */
    private const COUNT_BUCKET = [
        'delivered' => 'delivered',
        'partial' => 'partial',
        'failed' => 'failed',
        'returned' => 'returned',
        'skipped' => 'skipped',
    ];

    /** The money counterpart of {@see self::COUNT_BUCKET}. */
    private const VALUE_BUCKET = [
        'delivered' => 'delivered_value',
        'partial' => 'partial_value',
        'failed' => 'failed_value',
        'returned' => 'returned_value',
        'skipped' => 'skipped_value',
    ];

    private const EMPTY_OUTCOME_COUNTS = [
        'delivered' => 0,
        'partial' => 0,
        'failed' => 0,
        'returned' => 0,
        'skipped' => 0,
    ];

    private const EMPTY_OUTCOME_VALUES = [
        'orders_value' => 0.0,
        'delivered_value' => 0.0,
        'partial_value' => 0.0,
        'failed_value' => 0.0,
        'returned_value' => 0.0,
        'skipped_value' => 0.0,
    ];

    /** `value_basis` on the board response — tells the UI which money semantics it is showing. */
    public const VALUE_BASIS_ORDER_TOTAL = 'order_total';

    public const VALUE_BASIS_BRAND_LINE_TOTAL = 'brand_line_total';

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

        $brandId = $this->brandFilter($filters);
        $rows = $this->buildRows($companyId, $trips, $brandId);
        $filtered = $this->applyListFilters($rows, $filters)->values();

        return [
            'scope' => 'day',
            'date' => $date,
            'brand_id' => $brandId,
            'value_basis' => $this->valueBasis($brandId),
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
        $brandId = $this->brandFilter($filters);
        $rows = $this->flagDuplicateOpenCustody($this->buildRows($companyId, $trips, $brandId));

        $filtered = $this->applyListFilters($rows, $filters)->values();

        return [
            'scope' => 'active',
            'brand_id' => $brandId,
            'value_basis' => $this->valueBasis($brandId),
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

        $brandId = $this->brandFilter($filters);
        $rows = $this->sortRows($this->buildRows($companyId, $trips, $brandId), $sort, $dir);

        $total = $rows->count();
        $paged = $rows->forPage($page, $perPage)->values();

        return [
            'scope' => 'history',
            'range' => ['from' => $from, 'to' => $to],
            'brand_id' => $brandId,
            'value_basis' => $this->valueBasis($brandId),
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
            // Every driver-collected ELECTRONIC channel, from the canonical enum: bank transfer,
            // card, InstaPay, wallet. `already_paid` is structurally excluded, so a pre-delivery
            // payment can never surface here as a driver collection (§14).
            ->whereIn('payment_type', PaymentType::electronicValues())
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

        // Per-order money split, folded out of the collections already loaded above (no new query).
        $byOrder = $this->collectionsByOrder($allNonRejected);

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
            'orders' => $stops->map(function (DeliveryStop $stop) use ($ordersById, $byOrder): array {
                $order = $ordersById->get($stop->order_id);
                $orderValue = $order !== null ? round((float) $order->total, 2) : null;
                $split = $byOrder[$stop->order_id] ?? ['cash' => 0.0, 'electronic' => 0.0, 'already_paid' => 0.0];

                // Expected at handoff — the immutable per-stop snapshot. Null for stops handed off
                // before it was tracked; never backfilled from current order state.
                $expected = $stop->expected_collection_at_handoff !== null
                    ? round((float) $stop->expected_collection_at_handoff, 2)
                    : null;
                $collected = round($split['cash'] + $split['electronic'], 2);

                return [
                    'order_id' => $stop->order_id,
                    'order_number' => $order?->order_number,
                    'customer_name' => $order?->customer_name,
                    'order_value' => $orderValue,
                    // Commercial value that actually reached the customer. Only a Delivered stop has
                    // delivered value; Partial is NOT treated as fully delivered, and reporting a
                    // partial line's delivered value would need a per-line delivered authority this
                    // read model does not own — so it stays null rather than guessing.
                    'delivered_value' => $stop->status === DeliveryStopStatus::Delivered ? $orderValue : null,
                    'payment_method' => $order?->payment_method,
                    // Canonical, mutually exclusive buckets (see collectionsByOrder).
                    'cash_collected' => round($split['cash'], 2),
                    'electronic_collected' => round($split['electronic'], 2),
                    'already_paid' => round($split['already_paid'], 2),
                    'expected_collection' => $expected,
                    'collected_from_customer' => $collected,
                    'outstanding' => $expected !== null ? round($expected - $collected, 2) : null,
                    'status' => $stop->status->value,
                ];
            })->values()->all(),
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
                    'reference_number' => $c->reference_number,
                    // Timing/origin, canonical rather than inferred: this row is a collection the
                    // DRIVER recorded against a stop during custody (`already_paid` collections are
                    // excluded from this list by the query above), so it is never confused with a
                    // pre-dispatch payment.
                    'is_driver_collected' => true,
                    'collected_at' => optional($c->created_at)?->toIso8601String(),
                    'verified_at' => optional($c->verified_at)?->toIso8601String(),
                    'proof' => $proof !== null ? ['id' => $proof->id, 'state' => $proof->state->value] : null,
                ];
            })->values()->all(),
            'returns' => $returns->map(fn (TripReturn $r): array => [
                'order_id' => $r->order_id,
                'product_id' => $r->product_id,
                'product_name' => $r->product_name,
                'kind' => $r->kind->value,
                'dispatched_qty' => $r->dispatched_qty !== null ? (float) $r->dispatched_qty : null,
                'returned_qty' => (float) $r->returned_qty,
                'warehouse_confirmed_qty' => $r->warehouse_confirmed_qty !== null ? (float) $r->warehouse_confirmed_qty : null,
                'discrepancy_qty' => $r->discrepancy_qty !== null ? (float) $r->discrepancy_qty : null,
                // Canonical reverse-custody detail, surfaced rather than re-derived. `driver_liable`
                // is reported exactly as the canonical record states it — this read model never
                // infers or creates liability.
                'reason' => $r->reason,
                'disposition' => $r->disposition,
                'custody_type' => $r->custody_type,
                'warehouse_confirmed_at' => optional($r->warehouse_confirmed_at)?->toIso8601String(),
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
    private function buildRows(string $companyId, Collection $trips, ?string $brandId = null): Collection
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
        // ONE extra grouped query for the whole board when a single Brand is selected — never one
        // per driver, order or brand (§18). Null means All Brands: the canonical whole-order path.
        $brandByTrip = $brandId !== null ? $this->brandOutcomeByTrip($companyId, $tripIds, $brandId) : null;
        // Brand-scoped DRIVER WAREHOUSE stock — additive context only. It never replaces the
        // overall custody figure and never feeds the closing stage (see brandGoodsOnHandByTrip).
        $brandGoods = $brandId !== null ? $this->brandGoodsOnHandByTrip($companyId, $tripIds, $brandId) : null;

        $rows = $trips
            // ONE row per open Trip/Custody — the canonical operational identity (§7). NOT grouped
            // by calendar day, so a custody spanning midnight stays the SAME single row (§8), and
            // two genuine open custodies never collapse into one (§13).
            ->groupBy(fn (Trip $t): string => (string) $t->id)
            ->map(function (Collection $group) use ($stopBreakdown, $returnsByTrip, $reconByTrip, $valueByTrip, $brandByTrip, $brandGoods, $movementSums): array {
                $trip = $group->first();
                $assignment = $trip->driverVehicleAssignment;
                $driver = $assignment?->driver;
                $vehicle = $assignment?->vehicle;
                $assignmentId = (int) $trip->driver_vehicle_assignment_id;
                $opDay = $this->tripDay($trip);

                $summaries = $group->map(fn (Trip $t): array => $this->settlements->financialSummary($t));

                // The order population and its commercial value — whole-order canonical for All
                // Brands, narrowed to one Brand's attributable LINE value when a Brand is selected.
                $o = $this->outcomeForRow($group, $summaries, $stopBreakdown, $valueByTrip, $brandByTrip);
                $orders = (int) $o['orders'];
                $delivered = (int) $o['delivered'];
                $partial = (int) $o['partial'];
                $failed = (int) $o['failed'];
                $returnedOrders = (int) $o['returned'];
                $skipped = (int) $o['skipped'];
                // Goods-return records (canonical TripReturn) — a DIFFERENT authority from the
                // `returned` delivery-stop outcome above; the two are never conflated.
                $returns = (int) $group->sum(fn (Trip $t): int => (int) $returnsByTrip->get($t->id, 0));

                $ordersValue = round((float) $o['orders_value'], 2);
                $deliveredValue = round((float) $o['delivered_value'], 2);
                $partialValue = round((float) $o['partial_value'], 2);
                $failedValue = round((float) $o['failed_value'], 2);
                $returnedValue = round((float) $o['returned_value'], 2);
                $skippedValue = round((float) $o['skipped_value'], 2);

                // The board's THIRD outcome: the disjoint union of the canonical undelivered
                // outcomes. A stop carries exactly one status, so Failed / Returned / Skipped never
                // overlap and the sum double-counts nothing. The components stay on the row so the
                // canonical distinction is preserved and presentable — nothing is renamed or merged
                // away (§5).
                $undelivered = $failed + $returnedOrders + $skipped;
                $undeliveredValue = round($failedValue + $returnedValue + $skippedValue, 2);

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
                    // Canonical Returned / Skipped delivery-stop outcomes, previously surfaced
                    // nowhere. `returned_orders` is the stop OUTCOME; `returns` below is the
                    // TripReturn goods authority. Distinct facts, distinct fields.
                    'returned_orders' => $returnedOrders,
                    'skipped' => $skipped,
                    'undelivered' => $undelivered,
                    'delivery_pct' => $this->deliveryPct($delivered, $orders),
                    'returns' => $returns,
                    'cash_expected' => round((float) $summaries->sum(fn (array $s): float => (float) $s['cash_expected']), 2),
                    'transfers' => round((float) $summaries->sum(fn (array $s): float => (float) $s['bank_transfers_pending']), 2),
                    'difference' => $this->aggregateDifference($summaries->all()),
                    // Canonical operational value columns (final workspace table — CTO UX correction).
                    'orders_value' => $ordersValue,
                    'delivered_value' => $deliveredValue,
                    'partial_value' => $partialValue,
                    'failed_value' => $failedValue,
                    'returned_orders_value' => $returnedValue,
                    'skipped_value' => $skippedValue,
                    'undelivered_value' => $undeliveredValue,
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
                    // ALWAYS the driver's CURRENT physical on-hand stock in the canonical Driver /
                    // Vehicle Warehouse custody (SUM of VehicleInventoryItem.quantity_on_hand across
                    // the custody's vehicle assignments) — never order/undelivered/planned quantity
                    // and never settlement arithmetic. A Brand selection does NOT alter it.
                    'goods_on_hand' => round($onHand, 4),
                    // Brand-scoped slice of that SAME canonical custody stock, present only while a
                    // Brand is selected. Additive context, explicitly labelled by the UI — the
                    // overall figure above stays the primary Goods Remaining value.
                    'brand_goods_on_hand' => $brandGoods === null
                        ? null
                        : round((float) $group->sum(fn (Trip $t): float => (float) ($brandGoods[$t->id] ?? 0.0)), 4),
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

        // A Brand drill-down answers "for this Driver/Trip, what happened for Brand X?" — a custody
        // the brand never participated in has no answer to give, so it drops out of both the rows
        // and the KPIs that aggregate them (§17). All Brands keeps every row.
        return $brandByTrip === null
            ? $rows
            : $rows->filter(fn (array $r): bool => (int) $r['orders'] > 0)->values();
    }

    /**
     * The order population and commercial value for ONE board row.
     *
     * All Brands (`$brandByTrip === null`): the canonical whole-order figures — the count from the
     * per-trip settlement engine (`stops_total`), the outcome counts from the delivery-stop
     * authority and the money from `Order.total` by outcome.
     *
     * One Brand: the count is COUNT(DISTINCT orders) the brand participated in and the money is
     * that brand's attributable `order_lines.line_total` — both already aggregated by the single
     * grouped {@see self::brandOutcomeByTrip} query. Order-level shipping / discount / tax are not
     * attributed to any brand (§14), so a brand's value is strictly below the whole-order total.
     *
     * @param  Collection<int, Trip>  $group
     * @param  Collection<int, array<string, mixed>>  $summaries
     * @param  array<int, array<string, int>>  $stopBreakdown
     * @param  array<int, array<string, float>>  $valueByTrip
     * @param  array<int, array<string, float|int>>|null  $brandByTrip
     * @return array<string, float|int>
     */
    private function outcomeForRow(
        Collection $group,
        Collection $summaries,
        array $stopBreakdown,
        array $valueByTrip,
        ?array $brandByTrip,
    ): array {
        if ($brandByTrip !== null) {
            $out = self::EMPTY_OUTCOME_COUNTS + self::EMPTY_OUTCOME_VALUES + ['orders' => 0];
            foreach ($group as $t) {
                $b = $brandByTrip[$t->id] ?? null;
                if ($b === null) {
                    continue;
                }
                foreach (array_keys($out) as $k) {
                    $out[$k] += $b[$k] ?? 0;
                }
            }

            return $out;
        }

        $out = ['orders' => (int) $summaries->sum(fn (array $s): int => (int) $s['stops_total'])];
        foreach (self::COUNT_BUCKET as $bucket) {
            $out[$bucket] = (int) $group->sum(fn (Trip $t): int => (int) ($stopBreakdown[$t->id][$bucket] ?? 0));
        }
        foreach (array_keys(self::EMPTY_OUTCOME_VALUES) as $bucket) {
            $out[$bucket] = (float) $group->sum(fn (Trip $t): float => (float) ($valueByTrip[$t->id][$bucket] ?? 0.0));
        }

        return $out;
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
        $count = fn (string $key): int => (int) $rows->sum(fn (array $r): int => (int) ($r[$key] ?? 0));
        $value = fn (string $key): float => round((float) $rows->sum(fn (array $r): float => (float) ($r[$key] ?? 0.0)), 2);

        return [
            'total_orders' => $totalOrders,
            'total_delivered' => $totalDelivered,
            'total_failed' => $count('failed'),
            // The canonical undelivered outcomes. `total_undelivered` is the disjoint union that the
            // third KPI card leads with; the three components are reported alongside it so the
            // canonical Failed / Returned / Skipped distinction stays visible and is never renamed
            // into one another (§5/§9).
            'total_returned' => $count('returned_orders'),
            'total_skipped' => $count('skipped'),
            'total_undelivered' => $count('undelivered'),
            'delivery_rate' => $totalOrders > 0 ? (int) round($totalDelivered / $totalOrders * 100) : 0,
            // Commercial ORDER VALUE for exactly the populations counted above (§4/§6). Commercial
            // value is NOT collected cash — the cash figures below stay separate and unnetted.
            'total_orders_value' => $value('orders_value'),
            'total_delivered_value' => $value('delivered_value'),
            'total_failed_value' => $value('failed_value'),
            'total_returned_value' => $value('returned_orders_value'),
            'total_skipped_value' => $value('skipped_value'),
            'total_undelivered_value' => $value('undelivered_value'),
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
            // Operational trip facts for the Driver/Trip context panel — read straight off the
            // already-loaded Trip; no extra query, no new stored field.
            'trip_status' => $trip->status->value,
            'operational_date' => $this->tripDay($trip),
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
        $instapay = $sumType(PaymentType::InstaPay);
        $wallet = $sumType(PaymentType::Wallet);
        $alreadyPaid = $sumType(PaymentType::AlreadyPaid);
        $totalCollected = round($cash + $bank + $card + $instapay + $wallet + $alreadyPaid, 2);

        // Expected Collection = Σ of the per-stop handoff snapshots. Available ONLY when every stop
        // carries one; a null (pre-snapshot / historical) stop makes the whole figure unavailable
        // rather than a misleading partial sum — never backfilled from current order state.
        $snapshots = $stops->map(fn (DeliveryStop $s) => $s->expected_collection_at_handoff);
        $expectedAvailable = $stops->isNotEmpty() && $snapshots->every(fn ($v): bool => $v !== null);
        $expectedCollection = $expectedAvailable
            ? round((float) $snapshots->sum(fn ($v): float => (float) $v), 2)
            : null;

        // What customers actually paid DURING the trip (physical cash + driver-collected
        // electronic), excluding the prepaid `already_paid` portion that was never collectible at
        // handoff, measured against the handoff expectation. This is the canonical Collection
        // Difference and is deliberately NOT "expected cash − physical cash": every driver-collected
        // channel counts toward it, so a customer paying electronically at the door does not read as
        // a driver shortage.
        // All electronic channels, derived from the enum so a new channel is counted automatically.
        $driverElectronic = round((float) array_sum(array_map($sumType, PaymentType::electronicCases())), 2);
        $collectedFromCustomers = round($cash + $driverElectronic, 2);
        $collectionDifference = $expectedCollection !== null
            ? round($collectedFromCustomers - $expectedCollection, 2)
            : null;

        return [
            'cash' => $cash,
            'bank_transfer' => $bank,
            'card' => $card,
            // Canonical driver-collected InstaPay / Wallet — the ACTUAL collection channel, never
            // derived from an order's declared payment method (§11/§12 of CHANNELS-003).
            'instapay' => $instapay,
            'wallet' => $wallet,
            'already_paid' => $alreadyPaid,
            'total_collected' => $totalCollected,
            'delivered_sales' => $deliveredSales,
            'actual_collected' => $totalCollected,
            'cash_expected' => round((float) array_sum(array_column($summaries, 'cash_expected')), 2),
            'actual_cash' => $actualCash,
            'expected_collection' => $expectedCollection,
            'expected_collection_available' => $expectedAvailable,
            'collection_difference' => $collectionDifference,
            // ── Driver-collected vs prepaid, stated rather than left to the client ──────────
            // A PaymentCollection row IS a driver collection: it is recorded against a stop by the
            // driver, with `collected_by` stamped. `payment_type = already_paid` is the canonical
            // marker for value that was settled BEFORE custody. So the timing split below is
            // canonical, never inferred from an order's declared payment method.
            'driver_collected_electronic' => $driverElectronic,
            'driver_collected_total' => $collectedFromCustomers,
            'prepaid_before_delivery' => $alreadyPaid,
            // The canonical collection authority now carries InstaPay and Wallet as first-class
            // driver-collected channels (TASK-...-DRIVER-COLLECTION-CHANNELS-003), so the split
            // below is the real one and the flags report availability truthfully. Historical rows
            // recorded before the extension remain `bank_transfer` / `card` and are NOT
            // reinterpreted — those totals stay visible in Transfers and Reconciliation.
            'channel_granularity' => 'payment_type',
            'instapay_available' => true,
            'wallet_available' => true,
        ];
    }

    /**
     * Per-order collection split, folded out of the collections ALREADY loaded for this driver-day.
     *
     * Costs NO additional query: `$collections` is the one bounded, already-fetched set of
     * non-rejected PaymentCollection rows for these trips (with `stop` eager-loaded), so this is
     * server-side aggregation over loaded data — not a per-order query and not browser-side work.
     *
     * Each amount is attributed by canonical `payment_type`, and every payment lands in exactly ONE
     * bucket: physical cash, driver-collected electronic, or prepaid-before-delivery. Nothing is
     * counted twice.
     *
     * @param  Collection<int, PaymentCollection>  $collections
     * @return array<string, array{cash: float, electronic: float, already_paid: float}>
     */
    private function collectionsByOrder(Collection $collections): array
    {
        $out = [];
        foreach ($collections as $c) {
            $orderId = $c->stop?->order_id;
            if ($orderId === null) {
                continue;
            }

            $out[$orderId] ??= ['cash' => 0.0, 'electronic' => 0.0, 'already_paid' => 0.0];
            $amount = (float) $c->amount;

            // Classified from the canonical enum, not from a case list: physical cash, any
            // driver-collected electronic channel, or pre-delivery value. The three are mutually
            // exclusive by construction, so a payment can never land in two buckets, and a new
            // channel is classified correctly the moment it is added to PaymentType.
            $type = $c->payment_type;
            $bucket = $type->isPhysicalCash() ? 'cash' : ($type->isDriverCollected() ? 'electronic' : 'already_paid');
            $out[$orderId][$bucket] += $amount;
        }

        return $out;
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

    // ── Brand narrowing (§10-§17) ─────────────────────────────────────────────────

    /**
     * The selected canonical Brand id, or null for All Brands.
     *
     * The Brand identity is the canonical {@see \Modules\Organization\Brands\Domain\Models\Brand}
     * (uuid), reached through the canonical products.brand_id ownership edge. No Distribution-local
     * brand table, no DriverBrand / SettlementBrand, and no free-text matching: an unknown id simply
     * attributes nothing and the board comes back empty rather than guessing.
     *
     * @param  array<string, mixed>  $filters
     */
    private function brandFilter(array $filters): ?string
    {
        $brandId = $filters['brand_id'] ?? null;

        return is_string($brandId) && trim($brandId) !== '' ? trim($brandId) : null;
    }

    /**
     * Which money semantics the board's order-value figures carry, declared to the client so the UI
     * never has to guess: whole-order `Order.total` for All Brands, or brand-attributable
     * `order_lines.line_total` when one Brand is selected (§13/§14).
     */
    private function valueBasis(?string $brandId): string
    {
        return $brandId !== null ? self::VALUE_BASIS_BRAND_LINE_TOTAL : self::VALUE_BASIS_ORDER_TOTAL;
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
     * Delivery-stop outcome counts per trip, one grouped query.
     *
     * `Failed`, `Returned` and `Skipped` are DISTINCT canonical DeliveryStopStatus cases and are
     * counted separately here — never collapsed into one another, never renamed. Returned and
     * Skipped were previously counted NOWHERE on this board; that read gap is closed. The board's
     * third outcome presents their disjoint union (a stop carries exactly one status) while keeping
     * each component individually addressable, so no canonical meaning is lost.
     *
     * @param  list<int>  $tripIds
     * @return array<int, array{delivered: int, partial: int, failed: int, returned: int, skipped: int}>
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
            $out[$r->trip_id] ??= self::EMPTY_OUTCOME_COUNTS;
            $status = $r->status instanceof DeliveryStopStatus ? $r->status : DeliveryStopStatus::tryFrom((string) $r->status);
            $bucket = self::COUNT_BUCKET[$status?->value ?? ''] ?? null;
            if ($bucket !== null) {
                $out[$r->trip_id][$bucket] += (int) $r->aggregate;
            }
        }

        return $out;
    }

    /**
     * Canonical WHOLE-ORDER commercial value breakdown per trip: SUM(Order.total) grouped by
     * delivery-stop outcome, one grouped query.
     *
     * `Order.total` is the canonical commercial FINAL total of the order — it already carries
     * shipping_total / discount_total / tax_total. It is the COMMERCIAL value of the order, NOT
     * cash collected; cash stays in the payment/settlement figures (cash_collected, transfers_paid,
     * net_cash), which this never touches. `orders_value` = every stop (total assigned);
     * `delivered_value` = delivered stops (the actual delivered/sold value used for Total Sales);
     * `failed_value` / `returned_value` / `skipped_value` are the three DISTINCT canonical
     * undelivered outcomes, kept separate.
     *
     * @param  list<int>  $tripIds
     * @return array<int, array<string, float>>
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
            $out[$r->trip_id] ??= self::EMPTY_OUTCOME_VALUES;
            $status = $r->status instanceof DeliveryStopStatus ? $r->status : DeliveryStopStatus::tryFrom((string) $r->status);
            $value = (float) $r->value;
            $out[$r->trip_id]['orders_value'] += $value;
            $bucket = self::VALUE_BUCKET[$status?->value ?? ''] ?? null;
            if ($bucket !== null) {
                $out[$r->trip_id][$bucket] += $value;
            }
        }

        return $out;
    }

    /**
     * BRAND-ATTRIBUTABLE outcome breakdown per trip, restricted to ONE canonical Brand
     * (TASK-ECOS-DISTRIBUTION-DRIVER-DAY-SETTLEMENT-PAGE-001 §12/§13/§14).
     *
     * ONE grouped query for the WHOLE board — no per-driver, per-order or per-brand query, and no
     * browser-side aggregation.
     *
     * Counts use `COUNT(DISTINCT distribution_delivery_stops.id)`. A stop references exactly one
     * order, so this is COUNT(DISTINCT orders): an order carrying several lines of the SAME brand
     * counts ONCE (§12). A multi-brand order legitimately appears under each participating brand,
     * which is the expected behaviour for a brand drill-down.
     *
     * Value uses `SUM(order_lines.line_total)` — the canonical LINE-LEVEL attributable commercial
     * value, reached over order_lines → products → products.brand_id (the canonical Brand ownership
     * edge; Brand is never re-modelled here). The full order total is deliberately NOT assigned to
     * every participating brand, which would duplicate revenue (§13). Order-level components of
     * `Order.total` that belong to no line — shipping_total, discount_total, tax_total, fees — are
     * NOT distributed across brands: ECOS carries no canonical brand-attribution policy for them,
     * and a proportional allocation would be an invented formula. Brand value is therefore
     * attributable line value only, and is strictly less than the whole-order final total (§14).
     *
     * @param  list<int>  $tripIds
     * @return array<int, array<string, float|int>>
     */
    private function brandOutcomeByTrip(string $companyId, array $tripIds, string $brandId): array
    {
        if ($tripIds === []) {
            return [];
        }

        $rows = DeliveryStop::query()
            ->join('orders', 'orders.id', '=', 'distribution_delivery_stops.order_id')
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_lines.product_id')
            ->whereIn('distribution_delivery_stops.trip_id', $tripIds)
            ->where('orders.company_id', $companyId)
            ->where('products.brand_id', $brandId)
            ->groupBy('distribution_delivery_stops.trip_id', 'distribution_delivery_stops.status')
            ->selectRaw(
                'distribution_delivery_stops.trip_id as trip_id,'
                .' distribution_delivery_stops.status as status,'
                .' COUNT(DISTINCT distribution_delivery_stops.id) as stops,'
                .' SUM(order_lines.line_total) as value'
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->trip_id] ??= self::EMPTY_OUTCOME_COUNTS + self::EMPTY_OUTCOME_VALUES + ['orders' => 0];
            $status = $r->status instanceof DeliveryStopStatus ? $r->status : DeliveryStopStatus::tryFrom((string) $r->status);
            $stops = (int) $r->stops;
            $value = (float) $r->value;

            $out[$r->trip_id]['orders'] += $stops;
            $out[$r->trip_id]['orders_value'] += $value;

            $countBucket = self::COUNT_BUCKET[$status?->value ?? ''] ?? null;
            if ($countBucket !== null) {
                $out[$r->trip_id][$countBucket] += $stops;
            }
            $valueBucket = self::VALUE_BUCKET[$status?->value ?? ''] ?? null;
            if ($valueBucket !== null) {
                $out[$r->trip_id][$valueBucket] += $value;
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

    /**
     * Brand-scoped slice of the canonical DRIVER / VEHICLE WAREHOUSE stock, per trip.
     *
     * The authority is the SAME canonical custody engine the overall figure uses —
     * {@see VehicleInventoryItem}.quantity_on_hand keyed by vehicle_assignment_id. This is a FILTER
     * over that authority along the canonical products.brand_id ownership edge, not a second driver
     * stock calculation: no quantity is re-derived, apportioned or invented. Custody stock is held
     * per product, and a product has exactly one canonical Brand owner, so the slice is fully
     * attributable with no shared residue to distribute.
     *
     * Deliberately SEPARATE from {@see self::reconciliationAggregatesByTrip}: that aggregate feeds
     * `has_custody` and therefore the derived closing stage, and narrowing it by Brand would change
     * a custody-lifecycle signal. Trip/custody lifecycle semantics are untouched here.
     *
     * Two grouped queries for the whole board — no per-driver, per-product or per-brand query.
     *
     * @param  list<int>  $tripIds
     * @return array<int, float>  trip_id → brand-attributable quantity on hand
     */
    private function brandGoodsOnHandByTrip(string $companyId, array $tripIds, string $brandId): array
    {
        if ($tripIds === []) {
            return [];
        }

        $assignments = VehicleAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('trip_id', $tripIds)
            ->get(['id', 'trip_id']);

        if ($assignments->isEmpty()) {
            return [];
        }

        $qtyByAssignment = VehicleInventoryItem::query()
            ->join('products', 'products.id', '=', 'vehicle_inventory_items.product_id')
            ->where('vehicle_inventory_items.company_id', $companyId)
            ->whereIn('vehicle_inventory_items.vehicle_assignment_id', $assignments->pluck('id')->all())
            ->where('products.brand_id', $brandId)
            ->groupBy('vehicle_inventory_items.vehicle_assignment_id')
            ->selectRaw('vehicle_inventory_items.vehicle_assignment_id as assignment_id, SUM(vehicle_inventory_items.quantity_on_hand) as qty')
            ->pluck('qty', 'assignment_id');

        $out = [];
        foreach ($assignments as $a) {
            $qty = (float) ($qtyByAssignment[$a->id] ?? 0.0);
            $out[$a->trip_id] = ($out[$a->trip_id] ?? 0.0) + $qty;
        }

        return $out;
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
