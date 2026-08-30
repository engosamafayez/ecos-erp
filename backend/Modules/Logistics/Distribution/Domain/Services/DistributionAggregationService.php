<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\PaymentState;
use Modules\Logistics\Distribution\Domain\Enums\DistributionAssignmentSource;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveManager;

/**
 * The Distribution Workspace read model.
 *
 * Every figure here is computed from current rows on every call. Nothing is
 * cached, snapshotted or incrementally maintained, because the contract requires
 * the aggregation to be LIVE: an Order that becomes eligible at 11:00 must show
 * up in its Zone — and in that Zone's Slot — without anyone pressing "refresh".
 * A denormalised counter would be a second source of truth and would drift.
 *
 * This class only reads. It never assigns, never mutates inventory, and never
 * touches the Order lifecycle.
 */
final class DistributionAggregationService
{
    public function __construct(
        private readonly PreparationEligibilityReader $preparation,
        private readonly WaveManager $waves,
    ) {}

    /**
     * Whitelist of sortable fields for the Orders read model.
     *
     * Public name → qualified column. The client sends only the key, so no raw
     * column name, expression or SQL fragment can reach orderBy(). Every entry is
     * a real column already selected by the query, so a sort can never reference
     * something the payload does not expose.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'order_number' => 'o.order_number',
        'received_at' => 'o.created_at',
        'total' => 'o.total',
        'order_status' => 'o.status',
        'payment_method' => 'o.payment_method',
        'distribution_status' => 'dwo.assignment_source',
        'assigned_at' => 'dwo.assigned_at',
        'governorate' => 'lg.name_en',
        'warehouse' => 'w.name',
        'zone' => 'dz.name_en',
    ];

    /**
     * Per-Zone rollup for a Window — exactly ONE row per Zone.
     *
     * Grouped by Zone alone, deliberately NOT by (Zone, Slot). An individual
     * Order may be moved to a different Slot while staying in its Zone, so a
     * Zone can legitimately span several Slots; grouping by both would emit that
     * Zone twice with its Order count split between the rows, and the Workspace
     * would report a Zone of 3 Orders as two Zones of 2 and 1.
     *
     * `virtual_slot_id` therefore reports the Zone's PLANNED Slot — the Zone→Slot
     * mapping — not whatever Slot its Orders happen to sit in. `spans_slots`
     * flags the case where individual reassignment has moved Orders away from
     * that plan, which is a real operational state and not an error.
     *
     * Zones with no Orders are absent rather than reported as empty rows: the
     * Workspace shows work, and an empty Zone is not work.
     *
     * DELIBERATELY STAYS ON `constrainToEligible` (LP-1.0). This is the PLANNING
     * board — its consumer is the zone-selection list for building a Group
     * (`selectable` in distribution-groups-panel.tsx), which offers Zones that are
     * not yet grouped. That question is "what can still enter planning?", the
     * narrower predicate's question. The Group card and Loading Preparation ask
     * "what is in today's departure?" and use `constrainToLoadingEligible`.
     *
     * The visible consequence is real and accepted: once a Zone's Orders are all
     * `ready_for_dispatch`, the Zone leaves this board while its Group keeps
     * reporting them. That is the two surfaces answering their own questions, not
     * a disagreement about one.
     *
     * @return list<array<string, mixed>>
     */
    public function zoneSummaries(string $windowId, ?string $warehouseId = null): array
    {
        $rows = $this->preparation->constrainToEligible(
            $this->scopeWarehouse(
                DB::table('distribution_window_orders as dwo')
                    ->leftJoin('distribution_zones as dz', 'dz.id', '=', 'dwo.distribution_zone_id')
                    ->leftJoin('orders as o', 'o.id', '=', 'dwo.order_id')
                    ->where('dwo.distribution_window_id', $windowId),
                $warehouseId,
            ),
            'o',
        )
            ->groupBy('dwo.distribution_zone_id', 'dz.code', 'dz.name_en', 'dz.name_ar')
            ->select([
                'dwo.distribution_zone_id',
                'dz.code as zone_code',
                'dz.name_en as zone_name_en',
                'dz.name_ar as zone_name_ar',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('COALESCE(SUM(o.total), 0) as total_value'),
                DB::raw('COUNT(DISTINCT dwo.virtual_slot_id) as distinct_slots'),
                // Products per Zone: a correlated subquery per Order, summed. A
                // join to `order_lines` would multiply the Order rows and inflate
                // both order_count and total_value.
                DB::raw('COALESCE(SUM((SELECT COUNT(DISTINCT ol.product_id) FROM order_lines ol WHERE ol.order_id = dwo.order_id)), 0) as products_count'),
                // Paid vs unpaid, using the APPROVED PaymentState rule
                // (deposit_amount >= total => paid). Expressed in SQL so the split
                // is one aggregate pass rather than a per-row round trip; the
                // epsilon matches PaymentState::fromAmounts exactly.
                DB::raw('SUM(CASE WHEN COALESCE(o.deposit_amount, 0) > 0 AND (o.total <= 0 OR COALESCE(o.deposit_amount, 0) + 0.001 >= o.total) THEN 1 ELSE 0 END) as paid_orders'),
            ])
            ->get();

        $plannedSlot = $this->plannedSlotByZone($windowId);

        return $rows->map(static function (object $r) use ($plannedSlot): array {
            $zoneId = $r->distribution_zone_id === null ? null : (int) $r->distribution_zone_id;

            $orderCount = (int) $r->order_count;
            $paidOrders = (int) $r->paid_orders;

            return [
                'zone_id' => $zoneId,
                'zone_code' => $r->zone_code,
                'zone_name' => $r->zone_name_en ?? $r->zone_name_ar,
                'virtual_slot_id' => $zoneId === null ? null : ($plannedSlot[$zoneId] ?? null),
                'order_count' => $orderCount,
                'total_value' => (float) $r->total_value,
                'spans_slots' => (int) $r->distinct_slots > 1,
                'products_count' => (int) $r->products_count,
                'paid_orders' => $paidOrders,
                // Derived, not separately aggregated: "not fully paid" is the
                // complement by definition, so it cannot drift from paid_orders.
                'unpaid_orders' => $orderCount - $paidOrders,
            ];
        })->all();
    }

    /**
     * The Slot each Zone is PLANNED into, from the Zone→Slot mapping.
     *
     * @return array<int, string>
     */
    private function plannedSlotByZone(string $windowId): array
    {
        /** @var array<int, string> $out */
        $out = [];

        DB::table('distribution_slot_zones')
            ->where('distribution_window_id', $windowId)
            ->select('distribution_zone_id', 'virtual_slot_id')
            ->get()
            ->each(function (object $r) use (&$out): void {
                $out[(int) $r->distribution_zone_id] = (string) $r->virtual_slot_id;
            });

        return $out;
    }

    /**
     * Per-Slot rollup, including capacity, utilisation and overflow.
     *
     * Utilisation is null on an unconstrained dimension. A Slot with no order
     * limit cannot overflow on orders, and reporting 0% would imply otherwise.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @param  string|null  $waveId  TASK-003 PART 1/7/8 — narrow to ONE operational Wave.
     *
     * Optional, and passed only by the two ACTIVE BOARD reads. Several Waves can share a
     * calendar day for the same company and warehouse, so the day alone cannot separate
     * them; filtering on the Wave is what stops Wave 1's Groups appearing on Wave 2's
     * board.
     *
     * The other callers deliberately pass nothing: they read a Group they already hold —
     * for vehicle assignment, redistribution or a group detail panel — and must keep
     * working on a Group whose Wave has since closed. Making the parameter required would
     * have turned those reads into historical blind spots.
     *
     * Groups with NO Wave are still shown. A null `preparation_wave_id` means the Group
     * was never wave-scoped (an operator created it, or no Wave was open), so it cannot
     * belong to a *different* Wave and hiding it would make operator work vanish. The
     * window filter still confines it to the right day.
     */
    public function slotSummaries(
        string $windowId,
        ?string $warehouseId = null,
        ?string $waveId = null,
    ): array {
        // The GROUP LIST is filtered by OWNERSHIP, not merely by the orders it
        // reports. Part 5A scoped the totals, which hid the symptom; a group owned
        // by another warehouse must not appear at all.
        $slots = VirtualCapacitySlot::query()
            ->where('distribution_window_id', $windowId)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            // WAVE ISOLATION. Groups of this Wave, plus Groups belonging to no Wave at
            // all — never another Wave's.
            ->when($waveId !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('preparation_wave_id', $waveId)
                    ->orWhereNull('preparation_wave_id'),
            ))
            // TASK-002 PART 15 — the ACTIVE board shows operational Groups only.
            //
            // A Group closed with its Preparation Wave is historical: it keeps every row
            // it had and stays queryable, but it must never reappear as something an
            // operator can plan into. Filtering here rather than at each caller means
            // the board, the KPIs and the capacity totals all agree about what is live.
            ->whereNull('closed_at')
            ->orderBy('code')
            ->get();

        $demand = $this->slotOrderCounts($windowId, $warehouseId);
        $zonesBySlot = $this->zonesBySlot($windowId);
        $zoneNames = $this->zoneNames();
        $rollup = $this->slotRollup($windowId, $warehouseId);
        $warn = (float) config('distribution.slot.warn_threshold', 0.85);

        return $slots->map(static function (VirtualCapacitySlot $slot) use ($demand, $zonesBySlot, $zoneNames, $rollup, $warn): array {
            $count = $demand[$slot->id] ?? 0;
            $capacity = $slot->capacity_orders;

            $utilisation = ($capacity !== null && $capacity > 0)
                ? round($count / $capacity, 4)
                : null;

            $overflow = ($capacity !== null && $count > $capacity)
                ? $count - $capacity
                : 0;

            $zoneIds = $zonesBySlot[$slot->id] ?? [];
            $totals = $rollup[$slot->id] ?? ['orders' => 0, 'products' => 0, 'value' => 0.0, 'paid' => 0];

            return [
                'slot_id' => $slot->id,
                'code' => $slot->code,
                'name' => $slot->name,
                'warehouse_id' => $slot->warehouse_id,
                'zone_ids' => $zoneIds,
                // Names travel with the ids so a group can be described without a
                // second round trip and without the client joining anything.
                'zone_names' => array_values(array_map(
                    static fn (int $id): string => $zoneNames[$id] ?? ('Zone '.$id),
                    $zoneIds,
                )),
                'zones_count' => count($zoneIds),
                // -- Group rollup ------------------------------------------------
                // orders_count comes from the SAME per-slot aggregate as the value
                // and the payment split, so a group's headline numbers can never
                // disagree with each other. demand_orders below keeps its original
                // meaning and its original source, for the capacity maths.
                'orders_count' => (int) $totals['orders'],
                'products_count' => (int) $totals['products'],
                'total_value' => (float) $totals['value'],
                'paid_orders' => (int) $totals['paid'],
                'unpaid_orders' => (int) $totals['orders'] - (int) $totals['paid'],
                // A Virtual Capacity Slot has exactly one state today: it exists and
                // is being planned. Reporting that as a literal keeps the UI honest
                // without inventing a status column or a second state machine.
                'status' => 'draft',
                'capacity_orders' => $capacity,
                'capacity_stops' => $slot->capacity_stops,
                'capacity_weight_kg' => $slot->capacity_weight_kg,
                'capacity_volume_m3' => $slot->capacity_volume_m3,
                'demand_orders' => $count,
                // DERIVED, NEVER STORED — `capacity_orders - demand_orders`, from
                // the two figures already in this payload, so the screen cannot
                // disagree with the guard that enforces it. Null means the Group has
                // no maximum (unconstrained), which is not the same as zero room.
                // Floored at zero: a Group that ingestion pushed past its limit has
                // no remaining capacity, and a negative would read as owing orders.
                'remaining_orders' => $capacity === null ? null : max(0, $capacity - $count),
                'utilisation' => $utilisation,
                'overflow_orders' => $overflow,
                'is_over_capacity' => $overflow > 0,
                'is_warning' => $utilisation !== null && $overflow === 0 && $utilisation >= $warn,
            ];
        })->all();
    }

    /**
     * Zone id => display name, for describing a group without a second query.
     *
     * @return array<int, string>
     */
    private function zoneNames(): array
    {
        /** @var array<int, string> $names */
        $names = [];

        DB::table('distribution_zones')
            ->select(['id', 'code', 'name_en', 'name_ar'])
            ->get()
            ->each(function (object $z) use (&$names): void {
                $names[(int) $z->id] = (string) ($z->name_en ?? $z->name_ar ?? $z->code);
            });

        return $names;
    }

    /**
     * Per-slot rollup: orders, distinct products, value and the paid count.
     *
     * One grouped pass over the window's assignments. Products use a correlated
     * subquery per Order rather than a join to `order_lines`: a join would emit one
     * row per line and inflate the order count AND the value of every group that
     * holds a multi-line Order.
     *
     * LOADING-ELIGIBLE (LP-1.0): a Group's headline figures must keep counting an
     * Order once Preparation has moved it to `ready_for_dispatch`. The Order has not
     * left the Group — it is the Group's work, now ready to load — so a card reading
     * "0 orders" above a populated Loading Preparation list would be the same read
     * model contradicting itself.
     *
     * @return array<string, array{orders:int, products:int, value:float, paid:int}>
     */
    private function slotRollup(string $windowId, ?string $warehouseId = null): array
    {
        /** @var array<string, array{orders:int, products:int, value:float, paid:int}> $out */
        $out = [];

        $this->preparation->constrainToLoadingEligible(
            $this->scopeWarehouse(
                DB::table('distribution_window_orders as dwo')
                    ->leftJoin('orders as o', 'o.id', '=', 'dwo.order_id')
                    ->where('dwo.distribution_window_id', $windowId)
                    ->whereNotNull('dwo.virtual_slot_id'),
                $warehouseId,
            ),
            'o',
        )
            ->groupBy('dwo.virtual_slot_id')
            ->select([
                'dwo.virtual_slot_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('COALESCE(SUM(o.total), 0) as total_value'),
                DB::raw('COALESCE(SUM((SELECT COUNT(DISTINCT ol.product_id) FROM order_lines ol WHERE ol.order_id = dwo.order_id)), 0) as products_count'),
                // Same rule and the same epsilon as PaymentState::fromAmounts.
                DB::raw('SUM(CASE WHEN COALESCE(o.deposit_amount, 0) > 0 AND (o.total <= 0 OR COALESCE(o.deposit_amount, 0) + 0.001 >= o.total) THEN 1 ELSE 0 END) as paid_orders'),
            ])
            ->get()
            ->each(function (object $r) use (&$out): void {
                $out[(string) $r->virtual_slot_id] = [
                    'orders' => (int) $r->orders_count,
                    'products' => (int) $r->products_count,
                    'value' => (float) $r->total_value,
                    'paid' => (int) $r->paid_orders,
                ];
            });

        return $out;
    }

    /**
     * The Preparation Wave whose operational cycle governs ONE WAREHOUSE right now.
     *
     * ┌─ THE RESOLVER IS PREPARATION'S, NOT OURS ────────────────────────────┐
     * │ This used to run its own query: company-wide, `activeValues()` (five  │
     * │ statuses, including Draft), ordered by `starts_at`. Three ways wrong. │
     * │ It now delegates to `WaveManager::getActiveWave`, which is the        │
     * │ scheduler's own authority — so Distribution cannot drift from         │
     * │ Preparation's idea of "the current wave", because it no longer has    │
     * │ one of its own.                                                       │
     * └───────────────────────────────────────────────────────────────────────┘
     *
     * D-P5-1: the canonical selection contract is
     *   company_id + warehouse_id + planning_date + wave_type=engine + ACTIVE_STATUSES.
     *
     * `WaveManager` supplies the first three and the last. `wave_type` is applied
     * here for a reason of its own: a MANUAL wave "has no resolved boundaries",
     * so it has no start, cutoff or end to display. Reporting one as the cycle
     * would print an empty clock.
     *
     * NO WAREHOUSE, NO CYCLE. When no warehouse is in scope this returns null
     * rather than picking one. Falling back to another warehouse's wave is the
     * exact failure D-P5-1 exists to end, and a company-wide "latest" is a guess
     * dressed as an answer.
     *
     * @return array<string, mixed>|null
     */
    public function governingPreparationWave(
        string $companyId,
        ?string $warehouseId,
        ?string $operationalDate = null,
    ): ?array {
        if ($warehouseId === null) {
            return null;
        }

        $wave = $this->waves->getActiveWave($companyId, $warehouseId, $operationalDate);

        // A manual wave carries no resolved boundaries; only an engine wave can
        // describe an operational cycle.
        if ($wave === null || $wave->wave_type !== 'engine') {
            return null;
        }

        return [
            'wave_id' => $wave->id,
            'wave_number' => $wave->wave_number,
            'planning_date' => $wave->planning_date instanceof DateTimeInterface
                ? $wave->planning_date->format('Y-m-d')
                : $wave->planning_date,
            'starts_at' => (string) $wave->starts_at,
            'cutoff_at' => (string) $wave->intake_closes_at,
            'ends_at' => (string) $wave->ends_at,
            'status' => $wave->status instanceof BackedEnum ? $wave->status->value : $wave->status,
            'warehouse_id' => $wave->warehouse_id,
            // The operational timezone is the COMPANY's - the certified authority.
            'timezone' => DB::table('companies')->where('id', $companyId)->value('timezone'),
        ];
    }

    /**
     * Constrain a query to one warehouse, using the ORDER's own warehouse.
     *
     * Never inferred from the Zone: a Zone is geography and two warehouses can
     * legitimately deliver into the same one. `assigned_warehouse_id` is the same
     * column Preparation's collector uses, so the two modules agree by
     * construction rather than by coincidence.
     *
     * A null warehouse leaves the query untouched — company-wide, which is the
     * certified behaviour of every one of these endpoints today.
     */
    private function scopeWarehouse(mixed $query, ?string $warehouseId, string $alias = 'o'): mixed
    {
        if ($warehouseId === null) {
            return $query;
        }

        return $query->where($alias.'.assigned_warehouse_id', $warehouseId);
    }

    /**
     * Aggregate product demand — the figure Warehouse Loading will later consume.
     *
     * Quantities only. No inventory is read, reserved, moved or consumed here.
     *
     * LOADING-ELIGIBLE (LP-1.0): this is Loading Preparation's Required projection.
     * It must include `ready_for_dispatch` — the status that MEANS "prepared, waiting
     * to be loaded" — or the screen blanks itself at exactly the moment the warehouse
     * needs it. `constrainToLoadingEligible` still excludes postponed and cancelled
     * work through the identical `excludePostponed()`.
     *
     * @return list<array<string, mixed>>
     */
    public function productAggregation(string $windowId, ?int $zoneId = null, ?string $slotId = null, ?string $warehouseId = null): array
    {
        $q = $this->preparation->constrainToLoadingEligible(
            $this->scopeWarehouse(
                DB::table('distribution_window_orders as dwo')
                    ->join('order_lines as ol', 'ol.order_id', '=', 'dwo.order_id')
                    ->join('orders as o', 'o.id', '=', 'dwo.order_id')
                    ->leftJoin('products as p', 'p.id', '=', 'ol.product_id')
                    ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
                    ->where('dwo.distribution_window_id', $windowId),
                $warehouseId,
            ),
            'o',
        );

        if ($zoneId !== null) {
            $q->where('dwo.distribution_zone_id', $zoneId);
        }

        if ($slotId !== null) {
            $q->where('dwo.virtual_slot_id', $slotId);
        }

        return $q->groupBy('ol.product_id', 'p.name', 'p.sku', 'u.code', 'u.symbol')
            ->select([
                'ol.product_id',
                'p.name as product_name',
                'p.sku as product_sku',
                'u.code as unit_code',
                'u.symbol as unit_symbol',
                DB::raw('SUM(ol.quantity) as total_quantity'),
            ])
            ->orderBy('p.name')
            ->get()
            ->map(static fn (object $r): array => [
                'product_id' => $r->product_id,
                'product_name' => $r->product_name,
                'product_sku' => $r->product_sku,
                // Nullable by design: a Product with no unit shows a quantity with
                // no unit rather than a guessed one.
                'unit_code' => $r->unit_code,
                'unit_symbol' => $r->unit_symbol,
                'total_quantity' => (float) $r->total_quantity,
            ])
            ->all();
    }

    /**
     * Full Order list for a Zone or Slot, with the operational detail the
     * Workspace needs to act on a row.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Orders assigned to a Window, optionally narrowed by any combination of
     * filters. Every filter composes server-side in ONE query — the API contract
     * stays authoritative and no caller has to re-filter what it received.
     *
     * WAREHOUSE BOUNDARY: `assigned_warehouse_id` is READ from the Order and never
     * written or chosen here. Distribution owns no warehouse column and performs no
     * warehouse selection — that was decided upstream by Governorate + Zone + Brand
     * Coverage. Filtering by warehouse is a join, not an assignment.
     *
     * @param  array{
     *     zone_id?: int|null, slot_id?: string|null, governorate_id?: int|null,
     *     warehouse_id?: string|null, order_status?: string|null,
     *     payment_status?: string|null, distribution_status?: string|null,
     *     late?: bool|null, payment_method?: string|null,
     *     start_date?: string|null, end_date?: string|null,
     *     sort_by?: string|null, sort_dir?: string|null,
     * }  $filters
     * @return list<array<string, mixed>>
     */
    public function orders(
        string $windowId,
        ?int $zoneId = null,
        ?string $slotId = null,
        array $filters = [],
    ): array {
        // Positional zone/slot remain supported for existing callers; the array
        // form wins when both are supplied.
        $zoneId = $filters['zone_id'] ?? $zoneId;
        $slotId = $filters['slot_id'] ?? $slotId;

        // Sorting follows the platform convention (sort_by / sort_dir, 77/68 uses
        // across Modules) including its safety pattern: an unrecognised sort_by
        // falls back to the default rather than erroring, and the client can never
        // reach a raw column — SORTABLE maps a public name to a qualified column.
        $sortBy = (string) ($filters['sort_by'] ?? 'order_number');
        if (! array_key_exists($sortBy, self::SORTABLE)) {
            $sortBy = 'order_number';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        // A postponed Order leaves ACTIVE distribution but keeps its assignment row:
        // hiding it is reversible, deleting it would force a re-collection when
        // Preparation resumes the Order.
        //
        // LOADING-ELIGIBLE (LP-1.0): this list is rendered INSIDE the Group card,
        // filtered to the Group and headed by that Group's own `orders_count`
        // (distribution-groups-panel.tsx). Leaving it on the narrower predicate while
        // the count widened would print "3 orders" above an empty table.
        $q = $this->preparation->constrainToLoadingEligible(
            DB::table('distribution_window_orders as dwo')
                ->join('orders as o', 'o.id', '=', 'dwo.order_id'),
            'o',
        )
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            // Single joins rather than per-row lookups — no N+1.
            ->leftJoin('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->leftJoin('logistics_governorates as lg', 'lg.id', '=', 'lc.governorate_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'o.assigned_warehouse_id')
            // Zone name resolves from the ASSIGNMENT's zone, not the city's.
            //
            // `zone_id` in this payload is already dwo.distribution_zone_id, and an
            // operator may move an Order to a different Zone via changeOrderZone().
            // Naming it from logistics_cities.distribution_zone_id would then emit a
            // row whose zone_name contradicts its own zone_id. lateOrders() uses the
            // city path correctly, because a late Order has no assignment yet and the
            // city is its only Zone source; here the assignment is authoritative.
            ->leftJoin('distribution_zones as dz', 'dz.id', '=', 'dwo.distribution_zone_id')
            ->where('dwo.distribution_window_id', $windowId);

        if ($zoneId !== null) {
            $q->where('dwo.distribution_zone_id', $zoneId);
        }

        if ($slotId !== null) {
            $q->where('dwo.virtual_slot_id', $slotId);
        }

        // Governorate is reached through the canonical city FK, not the Order's
        // free-text `governorate` column — the text is a display value, the id is identity.
        if (($filters['governorate_id'] ?? null) !== null) {
            $q->where('lc.governorate_id', $filters['governorate_id']);
        }

        if (($filters['warehouse_id'] ?? null) !== null) {
            $q->where('o.assigned_warehouse_id', $filters['warehouse_id']);
        }

        if (($filters['order_status'] ?? null) !== null) {
            $q->where('o.status', $filters['order_status']);
        }

        if (($filters['payment_status'] ?? null) !== null) {
            $q->where('o.payment_status', $filters['payment_status']);
        }

        // Matched against the stored value as-is. `orders.payment_method` has no
        // enum in the Orders domain, so no whitelist is imposed here — inventing
        // one would reject values the column legitimately holds.
        if (($filters['payment_method'] ?? null) !== null) {
            $q->where('o.payment_method', $filters['payment_method']);
        }

        // Received-at range. `received_at` in this payload IS `orders.created_at`
        // (selected below as order_created_at), so the filter applies to that same
        // column — filtering and display can never disagree.
        //
        // Inclusive at both ends: whereDate compares the calendar date, so
        // start_date alone means "from the start of that day" and end_date alone
        // means "up to the end of that day".
        if (($filters['start_date'] ?? null) !== null) {
            $q->whereDate('o.created_at', '>=', $filters['start_date']);
        }

        if (($filters['end_date'] ?? null) !== null) {
            $q->whereDate('o.created_at', '<=', $filters['end_date']);
        }

        // `distribution_status` IS the assignment source — auto / manual_late /
        // manual_move. No separate status column exists and none was invented.
        if (($filters['distribution_status'] ?? null) !== null) {
            $q->where('dwo.assignment_source', $filters['distribution_status']);
        }

        // Late, for an ALREADY-ASSIGNED order, means it entered this Window by
        // manual late assignment rather than automatic collection. The window's
        // cutoff decided that at assignment time; this reads the recorded outcome
        // instead of recomputing the rule.
        if (($filters['late'] ?? null) !== null) {
            $late = DistributionAssignmentSource::ManualLate->value;
            $filters['late']
                ? $q->where('dwo.assignment_source', $late)
                : $q->where('dwo.assignment_source', '!=', $late);
        }

        return $q->select([
            'dwo.id as assignment_id',
            'dwo.order_id',
            'dwo.distribution_zone_id',
            'dwo.virtual_slot_id',
            'dwo.assignment_source',
            'dwo.assigned_at',
            'o.order_number',
            'o.status as order_status',
            'o.payment_status',
            'o.total',
            'o.payment_method',
            'o.billing_phone',
            'o.billing_address_1',
            'o.created_at as order_created_at',
            'o.updated_at as order_updated_at',
            'o.assigned_warehouse_id',
            'w.name as warehouse_name',
            // City is what Zone resolution actually runs on, so the Workspace has
            // to show it: "unzoned" is unactionable until the operator can see
            // whether the City is missing, unmatched, or matched-but-unconfigured.
            'o.city as order_city_text',
            'o.governorate as order_governorate_text',
            'o.logistics_city_id',
            'lc.name_en as city_name',
            'lc.distribution_zone_id as city_zone_id',
            // Payment: `payment_method` is null on manually-entered Orders, which
            // record the method in `payment_method_manual` instead. Both are read
            // so the column is not blank for the Orders operators create by hand.
            'o.payment_method_manual',
            // FULL shipping address, from the Order's OWN fields. The workspace
            // previously showed `billing_address_1`, which is NULL on every
            // manually-entered order — the operator saw a blank where the street
            // actually is. Nothing here is reconstructed from Zone or City data.
            'o.customer_name as order_customer_name',
            'o.customer_secondary_phone',
            'o.shipping_address',
            'o.building',
            'o.floor',
            'o.apartment',
            'o.landmark',
            'o.address_notes',
            'o.area',
            'o.shipping_postcode',
            'o.billing_postcode',
            // PaymentState (approved rule) is DERIVED from amount received vs
            // total. Both inputs are carried so the derivation stays in one place.
            'o.deposit_amount',
            'lc.governorate_id',
            'lg.name_en as governorate_name',
            'dz.name_en as zone_name',
            // Captured delivery coordinates — the SAME columns the Map read model
            // uses (`orders.google_maps_lat/lng/url`). Carried so the Zones table can
            // link to the real pin; a row with no coordinate stays honestly blank.
            'o.google_maps_lat',
            'o.google_maps_lng',
            'o.google_maps_url',
            'c.name as customer_name',
            'c.phone as customer_phone',
            // Product counts come from a correlated subquery, never a join to
            // `order_lines`: joining would emit one row per line and a two-product
            // Order would appear twice in the pool.
            DB::raw('(SELECT COUNT(DISTINCT ol.product_id) FROM order_lines ol WHERE ol.order_id = o.id) as products_count'),
            DB::raw('(SELECT COALESCE(SUM(ol.quantity), 0) FROM order_lines ol WHERE ol.order_id = o.id) as total_quantity'),
        ])
            ->orderBy(self::SORTABLE[$sortBy], $sortDir)
            // Stable secondary ordering: equal sort values must still come back in a
            // deterministic order, or the same query can return rows in a different
            // sequence on each call.
            ->orderBy('o.order_number')
            ->get()
            ->map(static fn (object $r): array => [
                'assignment_id' => $r->assignment_id,
                'order_id' => $r->order_id,
                'order_number' => $r->order_number,
                'order_status' => $r->order_status,
                // NEW — the Workspace needs these; payment_method is retained
                // alongside rather than replaced (no breaking change).
                'payment_status' => $r->payment_status,
                'warehouse_id' => $r->assigned_warehouse_id,
                'warehouse_name' => $r->warehouse_name,
                'governorate_id' => $r->governorate_id === null ? null : (int) $r->governorate_id,
                'governorate_name' => $r->governorate_name,
                'zone_name' => $r->zone_name,
                'received_at' => $r->order_created_at,
                'distribution_status' => $r->assignment_source,
                'is_late' => $r->assignment_source === DistributionAssignmentSource::ManualLate->value,
                'customer_name' => $r->customer_name,
                'phone' => $r->customer_phone ?? $r->billing_phone,
                'address' => $r->billing_address_1,
                'total' => (float) $r->total,
                'payment_method' => $r->payment_method,
                'zone_id' => $r->distribution_zone_id === null ? null : (int) $r->distribution_zone_id,
                'virtual_slot_id' => $r->virtual_slot_id,
                'assignment_source' => $r->assignment_source,
                'assigned_at' => $r->assigned_at,
                // ── Address binding + zone diagnostics ───────────────────────
                'city_id' => $r->logistics_city_id === null ? null : (int) $r->logistics_city_id,
                'city_name' => $r->city_name,
                'city_text' => $r->order_city_text,
                'unassigned_reason' => self::unassignedReason($r),
                // ── Captured location (DECIMAL(10,7) comes back as string via PDO) ──
                'latitude' => $r->google_maps_lat === null ? null : (float) $r->google_maps_lat,
                'longitude' => $r->google_maps_lng === null ? null : (float) $r->google_maps_lng,
                'google_maps_url' => $r->google_maps_url,
                // ── Order content ───────────────────────────────────────────
                'products_count' => (int) $r->products_count,
                'total_quantity' => (float) $r->total_quantity,
                'last_updated_at' => $r->order_updated_at,
                // ── Payment ─────────────────────────────────────────────────
                'payment_method_effective' => $r->payment_method ?? $r->payment_method_manual,
                // ── Full shipping address ───────────────────────────────────
                // Every field is emitted as it is stored, including nulls: the UI
                // marks what is missing rather than hiding the gap, and a missing
                // field is never filled in from somewhere else.
                'shipping_address' => [
                    // The ORDER's own recipient and phone win over the customer
                    // master record. A delivery goes where THIS order says, which
                    // is not always where the customer profile says - a gift, a
                    // workplace, a second address. Preferring the profile here
                    // would send the driver to the wrong door.
                    'recipient' => $r->order_customer_name ?? $r->customer_name,
                    'phone' => $r->billing_phone ?? $r->customer_phone,
                    'secondary_phone' => $r->customer_secondary_phone,
                    'street' => $r->shipping_address,
                    'building' => $r->building,
                    'floor' => $r->floor,
                    'apartment' => $r->apartment,
                    'landmark' => $r->landmark,
                    'area' => $r->area,
                    'city' => $r->city_name ?? $r->order_city_text,
                    'governorate' => $r->governorate_name ?? $r->order_governorate_text,
                    'postcode' => $r->shipping_postcode ?? $r->billing_postcode,
                    'notes' => $r->address_notes,
                ],
                'payment_state' => PaymentState::fromAmounts(
                    (float) ($r->deposit_amount ?? 0),
                    (float) $r->total,
                )->value,
            ])
            ->all();
    }

    /**
     * Why this Order has no Zone — or null when it has one.
     *
     * Every branch reads state that already exists; nothing is inferred and no
     * new status is introduced. The four answers are exhaustive over the real
     * failure modes, and each one tells the operator what to fix:
     *
     *   address_incomplete  — the Order carries no city text at all
     *   city_not_resolved   — city text exists but matches no canonical City
     *   zone_not_configured — City is known, but no Zone is mapped to it
     *   unresolved          — City and Zone both exist; the assignment predates
     *                         binding and has not been reconciled yet
     */
    private static function unassignedReason(object $row): ?string
    {
        if ($row->distribution_zone_id !== null) {
            return null;
        }

        if ($row->logistics_city_id === null) {
            return trim((string) ($row->order_city_text ?? '')) === ''
                ? 'address_incomplete'
                : 'city_not_resolved';
        }

        return $row->city_zone_id === null ? 'zone_not_configured' : 'unresolved';
    }

    /**
     * Orders that arrived AFTER this Window's cutoff and were therefore never
     * collected into it automatically — the Manager's triage list.
     *
     * Two conditions define the set, and both are read from existing domain state
     * rather than recomputed:
     *   1. the Order carries NO Distribution assignment at all, and
     *   2. it was created at or after the Window's cutoff moment.
     *
     * Eligibility for manual assignment is the Window's own rule
     * (`status->acceptsManualAssignment()`), so a UI never has to decide it.
     *
     * @return list<array<string, mixed>>
     */
    public function lateOrders(DistributionWindow $window): array
    {
        /** @var list<string> $eligibleStatuses */
        $eligibleStatuses = (array) config('distribution.eligible_order_statuses', []);

        if ($eligibleStatuses === []) {
            return [];
        }

        // The cutoff instant: the moment it was actually reached when recorded,
        // otherwise the Window's scheduled close.
        $cutoff = $window->cutoff_reached_at ?? $window->closes_at;
        $windowAcceptsManual = $window->status->acceptsManualAssignment();

        return DB::table('orders as o')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->leftJoin('logistics_governorates as lg', 'lg.id', '=', 'lc.governorate_id')
            ->leftJoin('distribution_zones as dz', 'dz.id', '=', 'lc.distribution_zone_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'o.assigned_warehouse_id')
            // Tenant boundary: the Window's company, never a request parameter.
            ->where('o.company_id', $window->company_id)
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', $eligibleStatuses)
            ->where('o.created_at', '>=', $cutoff)
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('distribution_window_orders as dwo')
                    ->whereColumn('dwo.order_id', 'o.id');
            })
            ->select([
                'o.id as order_id',
                'o.order_number',
                'o.status as order_status',
                'o.payment_status',
                'o.total',
                'o.created_at as received_at',
                'o.billing_phone',
                'o.assigned_warehouse_id',
                'w.name as warehouse_name',
                'lc.governorate_id',
                'lg.name_en as governorate_name',
                'lc.distribution_zone_id',
                'dz.name_en as zone_name',
                'c.name as customer_name',
                'c.phone as customer_phone',
            ])
            ->orderByDesc('o.created_at')
            ->get()
            ->map(static fn (object $r): array => [
                'order_id' => $r->order_id,
                'order_number' => $r->order_number,
                'customer_name' => $r->customer_name,
                'phone' => $r->customer_phone ?? $r->billing_phone,
                'warehouse_id' => $r->assigned_warehouse_id,
                'warehouse_name' => $r->warehouse_name,
                'governorate_id' => $r->governorate_id === null ? null : (int) $r->governorate_id,
                'governorate_name' => $r->governorate_name,
                'zone_id' => $r->distribution_zone_id === null ? null : (int) $r->distribution_zone_id,
                'zone_name' => $r->zone_name,
                'order_status' => $r->order_status,
                'payment_status' => $r->payment_status,
                'total' => (float) $r->total,
                'received_at' => $r->received_at,
                'cutoff_at' => $cutoff instanceof DateTimeInterface
                    ? $cutoff->format(DateTimeInterface::ATOM)
                    : (string) $cutoff,
                // Why it is late, and whether the Manager may still pull it in.
                'late_reason' => 'received_after_cutoff',
                'assignment_state' => 'unassigned',
                'current_window_eligible' => $windowAcceptsManual,
            ])
            ->all();
    }

    /**
     * @return array<string, int> slot id => order count
     *
     * LOADING-ELIGIBLE (LP-1.0): this is the OTHER half of `slotSummaries()` — it
     * produces `demand_orders`, which drives utilisation and overflow on the same
     * payload `slotRollup()` fills. The two must move together or one API response
     * would report a Group's size twice, differently.
     */
    public function slotOrderCounts(string $windowId, ?string $warehouseId = null): array
    {
        /** @var array<string, int> $out */
        $out = [];

        $this->preparation->constrainToLoadingEligible(
            $this->scopeWarehouse(
                DB::table('distribution_window_orders as dwo')
                    ->join('orders as o', 'o.id', '=', 'dwo.order_id')
                    ->where('dwo.distribution_window_id', $windowId)
                    ->whereNotNull('dwo.virtual_slot_id'),
                $warehouseId,
            ),
            'o',
        )
            ->groupBy('dwo.virtual_slot_id')
            ->select('dwo.virtual_slot_id', DB::raw('COUNT(*) as c'))
            ->get()
            ->each(function (object $r) use (&$out): void {
                $out[(string) $r->virtual_slot_id] = (int) $r->c;
            });

        return $out;
    }

    /**
     * The Map read model — Zones, Groups and plotted Orders for one Window.
     *
     * ┌─ WHERE THE GEOGRAPHY COMES FROM ─────────────────────────────────────────┐
     * │ `orders.google_maps_lat` / `google_maps_lng` — a REAL, already-populated  │
     * │ Orders contract, captured from the Google Maps link on the order address. │
     * │ Nothing is invented, defaulted or centred on a city guess.                │
     * │                                                                          │
     * │ A Zone has NO geometry of its own and none is added: `distribution_zones` │
     * │ carries only code / name / colour / is_active, and adding a polygon or a  │
     * │ centroid column would change a certified contract. A Zone is therefore    │
     * │ DERIVED here — the mean of its own plotted Orders, falling back to the    │
     * │ mean of its Cities (`logistics_cities.latitude/longitude`, the existing   │
     * │ Network contract) when no Order in it has been plotted. Nothing is stored.│
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * MISSING COORDINATES STAY MISSING. An Order with no lat/lng is returned with
     * nulls and `has_location: false`, so the surface can say "location not
     * registered" — which is real operational information the operator can act on.
     * Substituting a city centre, a warehouse position or a country default would
     * make an unplottable Order look plotted and would send a driver to a number
     * nobody entered.
     *
     * LOADING-ELIGIBLE, matching the Group card. The Map shows Groups, so it must
     * count the same population the Group's own totals count — otherwise a Group
     * would report 5 orders on its card and 3 on the map. That is the same reason
     * `slotOrderCounts()` uses this predicate.
     *
     * READ ONLY. No coordinate is written, no zone geometry is persisted and no
     * Order is modified.
     *
     * @return array{zones: list<array<string, mixed>>, groups: list<array<string, mixed>>, orders: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function mapData(string $windowId, ?string $warehouseId = null, ?string $waveId = null): array
    {
        $rows = $this->preparation->constrainToLoadingEligible(
            $this->scopeWarehouse(
                DB::table('distribution_window_orders as dwo')
                    ->join('orders as o', 'o.id', '=', 'dwo.order_id')
                    ->leftJoin('distribution_zones as dz', 'dz.id', '=', 'dwo.distribution_zone_id')
                    ->where('dwo.distribution_window_id', $windowId),
                $warehouseId,
            ),
            'o',
        )
            ->select([
                'o.id as order_id',
                'o.order_number',
                'o.customer_name',
                'o.total',
                'o.city',
                'o.google_maps_lat',
                'o.google_maps_lng',
                'dwo.distribution_zone_id',
                'dwo.virtual_slot_id',
                'dz.code as zone_code',
                'dz.name_en as zone_name_en',
                'dz.name_ar as zone_name_ar',
                'dz.color as zone_color',
            ])
            ->orderBy('o.order_number')
            ->get();

        /** @var array<int, array<string, mixed>> $zoneAcc */
        $zoneAcc = [];
        $orders = [];
        $plotted = 0;

        foreach ($rows as $r) {
            // Cast at the edge: the columns are DECIMAL(10,7), which PDO hands back
            // as strings. A string would sort and average as text downstream.
            $lat = $r->google_maps_lat === null ? null : (float) $r->google_maps_lat;
            $lng = $r->google_maps_lng === null ? null : (float) $r->google_maps_lng;
            $hasLocation = $lat !== null && $lng !== null;

            if ($hasLocation) {
                $plotted++;
            }

            $zoneId = $r->distribution_zone_id === null ? null : (int) $r->distribution_zone_id;

            $orders[] = [
                'order_id' => (string) $r->order_id,
                'order_number' => $r->order_number,
                'customer_name' => $r->customer_name,
                'total' => (float) $r->total,
                'city' => $r->city,
                'zone_id' => $zoneId,
                'slot_id' => $r->virtual_slot_id === null ? null : (string) $r->virtual_slot_id,
                'latitude' => $lat,
                'longitude' => $lng,
                // The single flag the UI branches on. Named for what it is, so no
                // caller has to decide what a null coordinate means.
                'has_location' => $hasLocation,
            ];

            if ($zoneId === null) {
                continue;
            }

            if (! isset($zoneAcc[$zoneId])) {
                $zoneAcc[$zoneId] = [
                    'zone_id' => $zoneId,
                    'zone_code' => $r->zone_code,
                    'zone_name' => $r->zone_name_en ?? $r->zone_name_ar,
                    'color' => $r->zone_color,
                    'order_count' => 0,
                    'plotted_count' => 0,
                    'slot_ids' => [],
                    'lat_sum' => 0.0,
                    'lng_sum' => 0.0,
                ];
            }

            $zoneAcc[$zoneId]['order_count']++;

            if ($r->virtual_slot_id !== null) {
                $zoneAcc[$zoneId]['slot_ids'][(string) $r->virtual_slot_id] = true;
            }

            if ($hasLocation) {
                $zoneAcc[$zoneId]['plotted_count']++;
                $zoneAcc[$zoneId]['lat_sum'] += $lat;
                $zoneAcc[$zoneId]['lng_sum'] += $lng;
            }
        }

        $cityCentroids = $this->zoneCityCentroids(array_keys($zoneAcc));

        $zones = [];

        foreach ($zoneAcc as $zoneId => $z) {
            $plottedCount = (int) $z['plotted_count'];

            if ($plottedCount > 0) {
                $lat = $z['lat_sum'] / $plottedCount;
                $lng = $z['lng_sum'] / $plottedCount;
                $source = 'orders';
            } else {
                // No Order in this Zone has been plotted. Fall back to the Zone's
                // own Cities — the existing Network coordinate contract — so the
                // Zone can still be placed once that data is captured. Null when
                // neither source has anything: an honestly unplottable Zone.
                $fallback = $cityCentroids[$zoneId] ?? null;
                $lat = $fallback === null ? null : $fallback['latitude'];
                $lng = $fallback === null ? null : $fallback['longitude'];
                $source = $fallback === null ? null : 'cities';
            }

            unset($z['lat_sum'], $z['lng_sum']);

            $zones[] = [
                ...$z,
                'slot_ids' => array_keys($z['slot_ids']),
                'latitude' => $lat === null ? null : round((float) $lat, 7),
                'longitude' => $lng === null ? null : round((float) $lng, 7),
                // Which contract placed this Zone, so the surface can be explicit
                // rather than presenting a city average as an order position.
                'centroid_source' => $source,
                'has_location' => $lat !== null && $lng !== null,
            ];
        }

        usort($zones, static fn (array $a, array $b): int => $b['order_count'] <=> $a['order_count']);

        // Groups come from the SAME ownership-filtered list the Groups tab uses, so
        // a Group the warehouse does not own cannot appear on its map. The governing
        // Wave is threaded through for the SAME reason the Groups tab passes it
        // (TASK-FINAL-SYNC §GAP-4): the map's Group overlay must show THIS Wave's
        // Groups (plus un-waved ones), never another Wave's — and slotSummaries()
        // treats a null waveId exactly as before, so un-waved tenants are unaffected.
        $groups = array_map(
            static fn (array $s): array => [
                'slot_id' => $s['slot_id'],
                'code' => $s['code'],
                'name' => $s['name'],
                'zone_ids' => $s['zone_ids'],
                'orders_count' => $s['orders_count'],
                'capacity_orders' => $s['capacity_orders'],
                'remaining_orders' => $s['remaining_orders'],
            ],
            $this->slotSummaries($windowId, $warehouseId, $waveId),
        );

        return [
            'zones' => $zones,
            'groups' => $groups,
            'orders' => $orders,
            'summary' => [
                'orders_total' => count($orders),
                'orders_plotted' => $plotted,
                // Stated, not hidden: the operator needs to know how much of the
                // picture is missing before trusting what the map shows.
                'orders_without_location' => count($orders) - $plotted,
                'zones_total' => count($zones),
                'zones_plotted' => count(array_filter(
                    $zones,
                    static fn (array $z): bool => (bool) $z['has_location'],
                )),
            ],
        ];
    }

    /**
     * Mean City coordinate per Zone, from `logistics_cities`.
     *
     * The FALLBACK anchor only — used when no Order in a Zone carries coordinates.
     * Every one of these columns is currently NULL in this deployment, so the
     * fallback yields nothing today; it is wired because the contract exists and the
     * alternative would be inventing a position.
     *
     * @param  list<int>  $zoneIds
     * @return array<int, array{latitude: float, longitude: float}>
     */
    private function zoneCityCentroids(array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }

        /** @var array<int, array{latitude: float, longitude: float}> $out */
        $out = [];

        DB::table('logistics_cities')
            ->whereIn('distribution_zone_id', $zoneIds)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->groupBy('distribution_zone_id')
            ->select([
                'distribution_zone_id',
                DB::raw('AVG(latitude) as lat'),
                DB::raw('AVG(longitude) as lng'),
            ])
            ->get()
            ->each(function (object $r) use (&$out): void {
                $out[(int) $r->distribution_zone_id] = [
                    'latitude' => (float) $r->lat,
                    'longitude' => (float) $r->lng,
                ];
            });

        return $out;
    }

    /** @return array<string, list<int>> slot id => zone ids */
    private function zonesBySlot(string $windowId): array
    {
        /** @var array<string, list<int>> $out */
        $out = [];

        DB::table('distribution_slot_zones')
            ->where('distribution_window_id', $windowId)
            ->select('virtual_slot_id', 'distribution_zone_id')
            ->get()
            ->each(function (object $r) use (&$out): void {
                $out[(string) $r->virtual_slot_id][] = (int) $r->distribution_zone_id;
            });

        return $out;
    }
}
