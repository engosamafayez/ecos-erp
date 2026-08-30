<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\PaymentType;
use Modules\Logistics\Distribution\Domain\Enums\SettlementStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Models\DeliveryAction;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\PaymentCollection;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliationLine;
use Throwable;

/**
 * READ-ONLY driver-scoped operational reporting — TASK-DRIVER-APP-PHASE-6-WALLET-REPORTS-CLOSURE-001.
 *
 * The Driver App's permanent Wallet + Reports read model. It owns NO money logic and NO write:
 * every money figure is DERIVED, per trip, from the canonical {@see SettlementService::financialSummary()}
 * and the {@see PaymentCollection} ledger (the cash SSOT), then SUMMED across the AUTHENTICATED
 * DRIVER'S OWN trips over a server-resolved date range. This mirrors the operator
 * {@see DriverDaySettlementReadService} but (a) self-scopes to one driver instead of trusting an
 * assignment id, (b) works over a DATE RANGE rather than a single day, and (c) adds the full
 * stop-status histogram and the reconciliation-sourced goods movement.
 *
 * CROSS-TRIP VALUES ARE DERIVED, NOT STORED. No canonical driver wallet/ledger exists (money is
 * strictly per-trip). Wallet totals are computed-on-read here — never a new ledger, never
 * aggregated in React (§1/§20). NO Finance/GL entry is created anywhere (§16).
 *
 * WHAT HAS NO CANONICAL AUTHORITY (documented, never invented — §5/§8): driver operational
 * ADVANCES and driver-operational EXPENSES have no driver-attributed backend authority; a
 * shortage's monetary VALUE and a confirmed-liability/settled money ladder do not exist. Those
 * are surfaced as explicit `available: false` sections, not fabricated.
 *
 * TENANCY — every query is fail-closed to the driver's own company id; the Loading tables carry
 * no global tenant scope, so the explicit company filter is load-bearing.
 */
class DriverReportsReadService
{
    /** @var list<string> */
    private const PERIODS = ['today', 'this_week', 'this_month', 'previous_month', 'this_year', 'ytd', 'previous_year', 'custom'];

    public function __construct(
        private readonly SettlementService $settlements,
    ) {}

    // ── Period resolution (server-side — §4) ────────────────────────────────────

    /**
     * Resolve a preset (or a custom from/to) into an inclusive [from, to] date window, server-side.
     * A single definition of "this week" etc. lives here, not in React.
     *
     * @return array{from: string, to: string, period: string}
     */
    public function resolvePeriod(?string $period, ?string $from, ?string $to): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'this_month';
        $today = CarbonImmutable::now()->startOfDay();

        [$start, $end] = match ($period) {
            'today' => [$today, $today],
            'this_week' => [$today->startOfWeek(), $today->endOfWeek()],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'previous_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()],
            'ytd' => [$today->startOfYear(), $today],
            'previous_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            'custom' => [
                $this->parseDate($from) ?? $today->startOfMonth(),
                $this->parseDate($to) ?? $today->endOfMonth(),
            ],
        };

        // Guard an inverted custom range.
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'period' => $period];
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    // ── Wallet (§2) + Closing status (§13) ──────────────────────────────────────

    /**
     * The driver's operational wallet for the window — collections by source, expected cash,
     * cash submitted, difference, and the aggregated settlement/closing state. Kept separate from
     * any accounting ledger authority (§2/§16).
     *
     * @return array<string, mixed>
     */
    public function wallet(Driver $driver, string $companyId, string $from, string $to): array
    {
        $trips = $this->driverTrips($driver, $companyId, $from, $to);
        $tripIds = $trips->pluck('id')->all();

        // Money SSOT — canonical per-trip engine, summed. Never re-derived in React.
        $summaries = $trips->map(fn (Trip $t): array => $this->settlements->financialSummary($t));

        // Collection breakdown straight from the payment-collection ledger (non-rejected).
        $collections = $this->collectionsByType($tripIds);

        $cashExpected = round((float) $summaries->sum(fn (array $s): float => (float) $s['cash_expected']), 2);
        $cashSubmitted = $this->cashSubmitted($trips);
        $difference = $this->aggregateDifference($summaries->all());
        $totalCollected = round((float) $summaries->sum(fn (array $s): float => (float) $s['total_collected']), 2);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'trips' => $trips->count(),
            'collections' => [
                'total' => $totalCollected,
                'cash' => $collections['cash'],
                'transfer' => $collections['bank_transfer'],
                'card' => $collections['card'],
                'already_paid' => $collections['already_paid'],
            ],
            'cash' => [
                'expected' => $cashExpected,
                'submitted' => $cashSubmitted,
                'difference' => $difference,
                'is_balanced' => $difference === null ? null : abs($difference) < 0.01,
            ],
            'settlement_status' => $this->aggregateSettlementStatus(
                $summaries->map(fn (array $s): ?string => $s['settlement_status'])->all(),
            ),
            // §5/§8 — no canonical driver-attributed authority; surfaced, not invented.
            'advances' => ['available' => false, 'reason' => 'no_canonical_authority', 'items' => []],
            'expenses' => ['available' => false, 'reason' => 'no_canonical_authority', 'items' => []],
            'liability' => ['available' => false, 'reason' => 'no_monetary_liability_authority'],
            'closing' => $this->closingIndicators($trips, $summaries->all(), $tripIds, $companyId),
        ];
    }

    /**
     * Driver-facing closing indicators (§13) — read-only booleans/counters the driver can
     * understand. The driver never approves any of these; they reflect canonical state only.
     *
     * @param  Collection<int, Trip>  $trips
     * @param  array<int, array<string, mixed>>  $summaries
     * @param  list<int>  $tripIds
     * @return array<string, mixed>
     */
    private function closingIndicators(Collection $trips, array $summaries, array $tripIds, string $companyId): array
    {
        $settlementStatus = $this->aggregateSettlementStatus(
            array_map(static fn (array $s): ?string => $s['settlement_status'], $summaries),
        );
        $custodyRemaining = $this->custodyRemainingQty($companyId, $tripIds);
        $outstandingStops = (int) array_sum(array_map(static fn (array $s): int => (int) $s['stops_outstanding'], $summaries));

        return [
            'all_trips_closed' => $trips->isNotEmpty() && $trips->every(
                fn (Trip $t): bool => in_array($t->status, [TripStatus::Closed, TripStatus::Cancelled], true),
            ),
            'deliveries_outstanding' => $outstandingStops,
            'custody_remaining' => round($custodyRemaining, 4),
            'custody_reconciled' => $custodyRemaining < 0.0001,
            'settlement_status' => $settlementStatus,
            'settlement_complete' => $settlementStatus === DriverDaySettlementReadService::STATUS_SETTLED,
        ];
    }

    // ── Orders performance (§7) ─────────────────────────────────────────────────

    /**
     * Full stop-status histogram over the window + a paginated order-row drill-down (§7/§8).
     * "Deferred" is derived from `delay` delivery-actions (not a stop status); "cancelled" has no
     * stop-level concept and is reported as 0 with a documented note.
     *
     * @return array<string, mixed>
     */
    public function ordersPerformance(Driver $driver, string $companyId, string $from, string $to, int $page, int $perPage): array
    {
        $tripIds = $this->driverTrips($driver, $companyId, $from, $to)->pluck('id')->all();

        $histogram = $this->stopStatusHistogram($tripIds);
        $received = (int) array_sum($histogram);
        $delivered = $histogram[DeliveryStopStatus::Delivered->value] ?? 0;
        $deferred = $this->deferredCount($tripIds);

        $rows = $this->orderRows($companyId, $tripIds, $page, $perPage);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'received' => $received,
                'delivered' => $delivered,
                'partial' => $histogram[DeliveryStopStatus::Partial->value] ?? 0,
                'failed' => $histogram[DeliveryStopStatus::Failed->value] ?? 0,
                'returned' => $histogram[DeliveryStopStatus::Returned->value] ?? 0,
                'skipped' => $histogram[DeliveryStopStatus::Skipped->value] ?? 0,
                'pending' => ($histogram[DeliveryStopStatus::Pending->value] ?? 0) + ($histogram[DeliveryStopStatus::InProgress->value] ?? 0),
                'deferred' => $deferred,
                'delivery_rate' => $received > 0 ? (int) round($delivered / $received * 100) : 0,
            ],
            'items' => $rows->items(),
            'meta' => $this->meta($rows),
        ];
    }

    // ── Goods movement (§9/§10/§11) ─────────────────────────────────────────────

    /**
     * Per-product goods movement for the window. The custody `quantity_returned` leg is inert in
     * practice, so Returned / Damaged / Confirmed-Shortage come from the reconciliation lines
     * (`quantity_returned_actual` / `quantity_damaged` / `variance`); Received/Delivered/Remaining
     * come from custody. Approved arithmetic: Remaining = max(0, Received − Delivered − Returned).
     *
     * @return array<string, mixed>
     */
    public function goodsMovement(Driver $driver, string $companyId, string $from, string $to): array
    {
        $tripIds = $this->driverTrips($driver, $companyId, $from, $to)->pluck('id')->all();
        $assignmentIds = $this->assignmentIds($companyId, $tripIds);

        $items = $assignmentIds === [] ? collect() : VehicleInventoryItem::query()
            ->where('company_id', $companyId)
            ->whereIn('vehicle_assignment_id', $assignmentIds)
            ->get();

        // Warehouse-received return classification (accepted/damaged/shortage) lives on the
        // reconciliation lines, keyed to the same custody items.
        $itemIds = $items->pluck('id')->all();
        $reconByItem = $itemIds === [] ? collect() : VehicleShiftReconciliationLine::query()
            ->where('company_id', $companyId)
            ->whereIn('vehicle_inventory_item_id', $itemIds)
            ->get()
            ->groupBy('product_id');

        $byProduct = $items->groupBy('product_id')->map(function (Collection $group, $productId) use ($reconByItem): array {
            $recon = $reconByItem->get((string) $productId) ?? collect();

            $received = round((float) $group->sum(fn (VehicleInventoryItem $i): float => (float) $i->quantity_loaded), 4);
            $delivered = round((float) $group->sum(fn (VehicleInventoryItem $i): float => (float) $i->quantity_delivered), 4);
            $returned = round((float) $recon->sum(fn (VehicleShiftReconciliationLine $l): float => (float) $l->quantity_returned_actual), 4);
            $damaged = round((float) $recon->sum(fn (VehicleShiftReconciliationLine $l): float => (float) $l->quantity_damaged), 4);
            $shortage = round((float) $recon->sum(fn (VehicleShiftReconciliationLine $l): float => (float) $l->variance), 4);
            $remaining = round((float) $group->sum(fn (VehicleInventoryItem $i): float => (float) $i->quantity_on_hand), 4);

            return [
                'product_id' => (string) $productId,
                'product_name' => (string) $group->first()->name_snapshot,
                'sku' => (string) $group->first()->sku_snapshot,
                'received' => $received,
                'delivered' => $delivered,
                'returned' => $returned,
                'damaged' => $damaged,
                'shortage' => max(0.0, $shortage),
                'remaining_custody' => $remaining,
            ];
        })->values()->all();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'arithmetic' => 'remaining_custody = max(0, received - delivered - returned); returned/damaged/shortage sourced from warehouse reconciliation lines',
            'products' => $byProduct,
        ];
    }

    // ── Shortage / liability (§6/§8) ────────────────────────────────────────────

    /**
     * Driver shortages from the reconciliation variance — the one source with driver+vehicle+date
     * attribution. Real: qty/variance/damage/reason/status (Disputed = under-investigation). Not
     * available (documented, not invented): monetary value, confirmed-liability/settled ladder.
     *
     * @return array<string, mixed>
     */
    public function shortages(Driver $driver, string $companyId, string $from, string $to): array
    {
        $tripIds = $this->driverTrips($driver, $companyId, $from, $to)->pluck('id')->all();
        $assignmentIds = $this->assignmentIds($companyId, $tripIds);
        $itemIds = $assignmentIds === [] ? [] : VehicleInventoryItem::query()
            ->where('company_id', $companyId)
            ->whereIn('vehicle_assignment_id', $assignmentIds)
            ->pluck('id')->all();

        $lines = $itemIds === [] ? collect() : VehicleShiftReconciliationLine::query()
            ->where('company_id', $companyId)
            ->whereIn('vehicle_inventory_item_id', $itemIds)
            ->where('variance', '>', 0.0001)
            ->with('reconciliation')
            ->get();

        $items = $lines->map(fn (VehicleShiftReconciliationLine $l): array => [
            'date' => $l->reconciliation?->operational_date,
            'product_id' => (string) $l->product_id,
            'sku' => (string) $l->sku_snapshot,
            'expected_return' => round((float) $l->quantity_returned_expected, 4),
            'actual_return' => round((float) $l->quantity_returned_actual, 4),
            'damaged' => round((float) $l->quantity_damaged, 4),
            'shortage_qty' => round((float) $l->variance, 4),
            'damage_reason' => $l->damage_reason,
            // Disputed shift = under investigation; resolved (variance_resolution set) = reviewed.
            'investigation_status' => $l->variance_resolution !== null ? 'reviewed' : 'under_investigation',
            // §8 — a shortage is NOT auto-debt; value/confirmed-liability/settled have no authority.
            'value' => null,
            'liability_status' => null,
        ])->values()->all();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'items' => $items,
            'value_available' => false,
            'liability_ladder_available' => false,
            'note' => 'Shortage stays a visible variance and is never auto-charged; monetary value and confirmed-liability/settled states have no canonical driver authority.',
        ];
    }

    // ── Monthly statement (§12) ─────────────────────────────────────────────────

    /**
     * A permanent monthly statement composed from the same canonical reads — a reporting read
     * model only, NO Finance journal (§12/§16).
     *
     * @return array<string, mixed>
     */
    public function monthlyStatement(Driver $driver, string $companyId, string $month): array
    {
        $anchor = $this->parseMonth($month);
        $from = $anchor->startOfMonth()->toDateString();
        $to = $anchor->endOfMonth()->toDateString();

        $wallet = $this->wallet($driver, $companyId, $from, $to);
        $orders = $this->ordersPerformance($driver, $companyId, $from, $to, 1, 1);
        $shortages = $this->shortages($driver, $companyId, $from, $to);

        return [
            'month' => $anchor->format('Y-m'),
            'period' => ['from' => $from, 'to' => $to],
            'orders' => $orders['summary'],
            'collections' => $wallet['collections'],
            'cash' => $wallet['cash'],
            'settlement_status' => $wallet['settlement_status'],
            'shortages_count' => count($shortages['items']),
            'advances' => $wallet['advances'],
            'expenses' => $wallet['expenses'],
        ];
    }

    private function parseMonth(string $month): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m', trim($month))->startOfMonth();
        } catch (Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    /**
     * The authenticated driver's OWN trips in the window, scoped fail-closed to the driver id and
     * company. Same idiom as DriverRuntimeController::vehicleInventory. Anchored on the operational
     * date COALESCE(trip_started_at, dispatched_at, created_at).
     *
     * @return Collection<int, Trip>
     */
    private function driverTrips(Driver $driver, string $companyId, string $from, string $to): Collection
    {
        return Trip::query()
            ->where('company_id', $companyId)
            ->whereHas('driverVehicleAssignment', fn ($q) => $q->where('driver_id', $driver->id))
            ->whereRaw('DATE(COALESCE(trip_started_at, dispatched_at, created_at)) BETWEEN ? AND ?', [$from, $to])
            ->with('settlement')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  list<int>  $tripIds
     * @return array<string, int> status value → count, for every DeliveryStopStatus
     */
    private function stopStatusHistogram(array $tripIds): array
    {
        $base = array_fill_keys(DeliveryStopStatus::values(), 0);
        if ($tripIds === []) {
            return $base;
        }

        $counts = DeliveryStop::query()
            ->whereIn('trip_id', $tripIds)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(fn ($v): int => (int) $v)
            ->all();

        return array_merge($base, $counts);
    }

    /** @param  list<int>  $tripIds */
    private function deferredCount(array $tripIds): int
    {
        if ($tripIds === []) {
            return 0;
        }

        // "Deferred/postponed" = a `delay` action (DeliveryAction is keyed by stop_id, not
        // trip_id), counted by distinct stop — it is not a stop status.
        $stopIds = DeliveryStop::query()->whereIn('trip_id', $tripIds)->pluck('id')->all();
        if ($stopIds === []) {
            return 0;
        }

        return (int) DeliveryAction::query()
            ->whereIn('stop_id', $stopIds)
            ->where('action_type', 'delay')
            ->distinct('stop_id')
            ->count('stop_id');
    }

    /**
     * @param  list<int>  $tripIds
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function orderRows(string $companyId, array $tripIds, int $page, int $perPage): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        /** @var LengthAwarePaginator<int, DeliveryStop> $paginator */
        $paginator = DeliveryStop::query()
            ->when($tripIds === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->when($tripIds !== [], fn ($q) => $q->whereIn('trip_id', $tripIds))
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $orderIds = collect($paginator->items())->pluck('order_id')->filter()->unique()->values()->all();
        $orders = $this->ordersById($companyId, $orderIds);

        $paginator->getCollection()->transform(function (DeliveryStop $stop) use ($orders): array {
            $order = $orders->get($stop->order_id);

            return [
                'order_id' => $stop->order_id,
                'order_number' => $order?->order_number,
                'customer_name' => $order?->customer_name,
                'area' => $order?->city,
                'governorate' => $order?->governorate,
                'outcome' => $stop->status instanceof BackedEnum ? $stop->status->value : (string) $stop->status,
                'order_value' => $order !== null ? round((float) $order->total, 2) : null,
            ];
        });

        return $paginator;
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
     * @param  list<int>  $tripIds
     * @return array{cash: float, bank_transfer: float, card: float, already_paid: float}
     */
    private function collectionsByType(array $tripIds): array
    {
        $out = ['cash' => 0.0, 'bank_transfer' => 0.0, 'card' => 0.0, 'already_paid' => 0.0];
        if ($tripIds === []) {
            return $out;
        }

        $sums = PaymentCollection::query()
            ->whereIn('trip_id', $tripIds)
            ->where('status', '!=', PaymentCollection::STATUS_REJECTED)
            ->groupBy('payment_type')
            ->selectRaw('payment_type, SUM(amount) as aggregate')
            ->pluck('aggregate', 'payment_type');

        foreach (PaymentType::cases() as $type) {
            $out[$type->value] = round((float) ($sums[$type->value] ?? 0), 2);
        }

        return $out;
    }

    /** @param  Collection<int, Trip>  $trips */
    private function cashSubmitted(Collection $trips): ?float
    {
        $submitted = $trips
            ->map(fn (Trip $t): ?float => $t->settlement?->driver_cash_submitted !== null
                ? (float) $t->settlement->driver_cash_submitted
                : null)
            ->filter(fn (?float $v): bool => $v !== null);

        return $submitted->isEmpty() ? null : round((float) $submitted->sum(), 2);
    }

    /** @param  array<int|string, array<string, mixed>>  $summaries */
    private function aggregateDifference(array $summaries): ?float
    {
        $discrepancies = array_values(array_filter(
            array_column($summaries, 'discrepancy'),
            static fn ($v): bool => $v !== null,
        ));

        return $discrepancies === [] ? null : round((float) array_sum($discrepancies), 2);
    }

    /**
     * Reuse the operator rollup's exact state mapping so the driver's view cannot disagree with
     * Operations: settled → all finalized; disputed → any disputed; under_review → any
     * submitted/reconciled; else needs_review.
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
            return DriverDaySettlementReadService::STATUS_SETTLED;
        }
        if (in_array(SettlementStatus::Disputed->value, $statuses, true)) {
            return DriverDaySettlementReadService::STATUS_DISPUTED;
        }
        if (in_array(SettlementStatus::Submitted->value, $statuses, true)
            || in_array(SettlementStatus::Reconciled->value, $statuses, true)) {
            return DriverDaySettlementReadService::STATUS_UNDER_REVIEW;
        }

        return DriverDaySettlementReadService::STATUS_NEEDS_REVIEW;
    }

    /**
     * @param  list<int>  $tripIds
     * @return list<int|string>
     */
    private function assignmentIds(string $companyId, array $tripIds): array
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

    /** @param  list<int>  $tripIds */
    private function custodyRemainingQty(string $companyId, array $tripIds): float
    {
        $assignmentIds = $this->assignmentIds($companyId, $tripIds);
        if ($assignmentIds === []) {
            return 0.0;
        }

        return (float) VehicleInventoryItem::query()
            ->where('company_id', $companyId)
            ->whereIn('vehicle_assignment_id', $assignmentIds)
            ->sum('quantity_on_hand');
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
