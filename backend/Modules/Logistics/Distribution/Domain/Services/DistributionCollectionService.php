<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\DistributionAssignmentSource;
use Modules\Logistics\Distribution\Domain\Events\OrderAddedToDistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveManager;

/**
 * Automatic collection of eligible Orders into their Distribution Window.
 *
 * Idempotent by construction rather than by checking: `order_id` is globally
 * unique on `distribution_window_orders`, so a second pass over the same Order
 * cannot produce a second assignment even if two collectors run concurrently.
 * The pre-filter below is an optimisation, not the safety mechanism — the
 * database constraint is.
 *
 * Eligibility is the CLOSED list in config('distribution.eligible_order_statuses').
 * Reservation state is deliberately not consulted: an Order may be fully
 * reserved and still ineligible, and vice versa. The two contracts are separate.
 *
 * This class never touches `orders`. Distribution assignment and Order lifecycle
 * are independent.
 */
final class DistributionCollectionService
{
    public function __construct(
        private readonly DistributionWindowService $windows,
        private readonly OrderZoneResolver $zones,
        private readonly PreparationEligibilityReader $preparation,
        private readonly WaveManager $waves,
    ) {}

    /**
     * Collect every currently-eligible, unassigned Order for one company.
     *
     * ┌─ THE TARGET WINDOW IS THE CYCLE'S, NOT THE CALENDAR'S ───────────────────┐
     * │ Owner decision: "The Distribution ingestion/collection path must resolve  │
     * │ the same active Preparation Wave planning window used by the Distribution │
     * │ workspace." So READ and WRITE now share one anchor:                       │
     * │                                                                          │
     * │   Preparation Wave → planning window → workspace reads it                 │
     * │                                    └─→ collection writes into it          │
     * │                                                                          │
     * │ Before this, `current()` anchored on the wave while collection anchored on │
     * │ the calendar day, so a newly eligible Order landed in a window the         │
     * │ workspace was not reading and — because `order_id` is globally unique —    │
     * │ could never be moved there by a later run.                                │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * PER WAREHOUSE, NOT PER COMPANY. Each warehouse runs its OWN Preparation
     * Wave, so "the planning window" only has an answer once the Order's warehouse
     * is known. The target is therefore resolved per Order (memoised per
     * warehouse), which is also why the slot map below is keyed by window AND
     * warehouse rather than warehouse alone.
     *
     * CUTOFF SEMANTICS ARE PRESERVED EXACTLY — see targetWindowFor().
     *
     * @return list<DistributionWindowOrder> the assignments created by THIS run
     */
    public function collectForCompany(string $companyId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $candidates = $this->eligibleUnassignedOrders($companyId);

        if ($candidates === []) {
            return [];
        }

        $zoneByCity = $this->zones->resolveMany(
            array_values(array_filter(array_map(
                static fn (object $o): ?int => $o->logistics_city_id === null ? null : (int) $o->logistics_city_id,
                $candidates,
            ))),
        );

        // Target window per warehouse. Memoised so a batch costs one wave lookup
        // per warehouse, not one per Order.
        /** @var array<string, DistributionWindow> $windowByWarehouse */
        $windowByWarehouse = [];

        $windowFor = function (?string $warehouseId) use ($companyId, $now, &$windowByWarehouse): DistributionWindow {
            // The empty-string key stands for "no warehouse"; a null array key
            // would silently collapse into it and is not usable here.
            $key = $warehouseId ?? '';

            if (! array_key_exists($key, $windowByWarehouse)) {
                $windowByWarehouse[$key] = $this->targetWindowFor($companyId, $warehouseId, $now);
            }

            return $windowByWarehouse[$key];
        };

        // One slot map per (WINDOW, WAREHOUSE). A Zone can be planned by two
        // warehouses in two different Groups, and now two warehouses can also be
        // collecting into two different windows — so the window is part of the key
        // or one warehouse would inherit the other's Group.
        /** @var array<string, array<int, string>> $slotByZone */
        $slotByZone = [];

        $slotFor = function (string $windowId, ?string $warehouseId, ?int $zoneId) use (&$slotByZone): ?string {
            if ($zoneId === null || $warehouseId === null) {
                return null;
            }

            $key = $windowId.'|'.$warehouseId;

            if (! array_key_exists($key, $slotByZone)) {
                $slotByZone[$key] = $this->slotMapForWindow($windowId, $warehouseId);
            }

            return $slotByZone[$key][$zoneId] ?? null;
        };

        $created = [];

        foreach ($candidates as $order) {
            $zoneId = $order->logistics_city_id === null
                ? null
                : ($zoneByCity[(int) $order->logistics_city_id] ?? null);

            $warehouseId = $order->assigned_warehouse_id === null
                ? null
                : (string) $order->assigned_warehouse_id;

            $window = $windowFor($warehouseId);

            $assignment = $this->attach(
                companyId: $companyId,
                windowId: $window->id,
                orderId: (string) $order->id,
                zoneId: $zoneId,
                slotId: $slotFor($window->id, $warehouseId, $zoneId),
                source: DistributionAssignmentSource::Automatic,
                actorId: null,
                now: $now,
            );

            if ($assignment !== null) {
                $created[] = $assignment;
            }
        }

        return $created;
    }

    /**
     * The window a newly collected Order for this warehouse must join.
     *
     * ┌─ WHY CUTOFF IS STILL CUTOFF ─────────────────────────────────────────────┐
     * │ Preparation's own contract, quoted from WaveMembershipService:            │
     * │                                                                          │
     * │   `intake_closes_at`  -> Collecting becomes Preparing.                    │
     * │                          STOPS NEW ADMISSIONS ONLY.                       │
     * │   closeWave()         -> terminal status + released_at. ENDS THE WAVE.    │
     * │                                                                          │
     * │ Anchoring the write path on the wave must NOT become a back door that     │
     * │ pours new work into a cycle whose intake has closed. So the wave's own    │
     * │ predicate decides, and it is CALLED rather than restated:                 │
     * │ `PreparationWave::hasReachedIntakeCutoff()` — the same method the wave    │
     * │ scheduler uses to flip Collecting -> Preparing.                          │
     * │                                                                          │
     * │   intake OPEN   -> the planning window. Read and write agree.             │
     * │   intake CLOSED -> resolveIngestionWindow(), i.e. the pre-existing §16     │
     * │                    behaviour. The Order is queued for a later window and  │
     * │                    reaches this cycle only by the approved manual         │
     * │                    late-order path, which is deliberately still permitted │
     * │                    after cutoff because CUTOFF != CLOSE.                  │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * NOTHING ABOUT PREPARATION IS WRITTEN OR WIDENED. This method reads a wave
     * and calls one of its predicates. No `preparation_wave_orders` row is created
     * — Distribution has never admitted an Order to a wave and still does not —
     * no eligibility list is widened, and no status is introduced or changed.
     *
     * FALLS BACK TO TODAY, NEVER TO NOTHING. An Order with no warehouse cannot be
     * attributed to a wave; a warehouse with no active engine wave has no cycle to
     * join. Both keep the behaviour they have today rather than being dropped.
     */
    private function targetWindowFor(
        string $companyId,
        ?string $warehouseId,
        CarbonImmutable $now,
    ): DistributionWindow {
        if ($warehouseId === null) {
            return $this->windows->resolveIngestionWindow($companyId, $now);
        }

        $wave = $this->waves->getActiveWave($companyId, $warehouseId);

        // Only an engine wave describes an operational cycle — the same test
        // DistributionAggregationService::governingPreparationWave() applies, so the
        // read and write paths agree on what counts as "the governing wave".
        if ($wave === null || $wave->wave_type !== 'engine') {
            return $this->windows->resolveIngestionWindow($companyId, $now);
        }

        // CUTOFF PRESERVED. Preparation's predicate, not a copy of it.
        if ($wave->hasReachedIntakeCutoff($now)) {
            return $this->windows->resolveIngestionWindow($companyId, $now);
        }

        // COLLECTION MAY CREATE. On the first sweep of a new cycle no order carries an
        // assignment yet, so there is no anchor to resolve and the sweep still needs a
        // destination. The reader deliberately cannot do this (TASK-1-A §1), so the
        // write path names the create intent itself.
        return $this->windows->resolveOrCreatePlanningWindow($companyId, $wave->id, $warehouseId, $now);
    }

    /**
     * Create one assignment, or return null when the Order already has one.
     *
     * The unique index does the deciding. A duplicate is a normal outcome of a
     * repeated run, not an error, so it is swallowed rather than raised.
     */
    public function attach(
        string $companyId,
        string $windowId,
        string $orderId,
        ?int $zoneId,
        ?string $slotId,
        DistributionAssignmentSource $source,
        ?int $actorId,
        CarbonImmutable $now,
        ?string $previousWindowId = null,
        ?string $reason = null,
    ): ?DistributionWindowOrder {
        $existing = DistributionWindowOrder::query()->where('order_id', $orderId)->first();

        if ($existing !== null) {
            return null;
        }

        try {
            $assignment = DistributionWindowOrder::query()->create([
                'company_id' => $companyId,
                'distribution_window_id' => $windowId,
                'order_id' => $orderId,
                'distribution_zone_id' => $zoneId,
                'virtual_slot_id' => $slotId,
                'assignment_source' => $source->value,
                'assigned_by' => $actorId,
                'assigned_at' => $now,
                'previous_window_id' => $previousWindowId,
                'assignment_reason' => $reason,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Another worker won the race. One effective assignment exists, which
            // is exactly the required outcome — nothing to repair.
            return null;
        }

        OrderAddedToDistributionWindow::dispatch($assignment);

        return $assignment;
    }

    /**
     * Re-resolve the Zone of assignments that were attached while their Order had
     * no City.
     *
     * ┌─ WHY THIS IS NOT "RE-COLLECTION" ────────────────────────────────────┐
     * │ `attach()` stamps the Zone once, from whatever the resolver could say  │
     * │ at that instant. An Order collected before its City was bound is       │
     * │ therefore pinned to zone NULL forever, even after binding teaches the  │
     * │ system exactly which Zone it belongs to — it can never be re-collected, │
     * │ because it already HAS an assignment.                                  │
     * │                                                                        │
     * │ This method repairs precisely that case and nothing else.              │
     * └────────────────────────────────────────────────────────────────────────┘
     *
     * Strictly bounded, so it can never become a second planning engine:
     *   • only rows whose `distribution_zone_id IS NULL` are considered — an
     *     Order that already has a Zone is never moved, including one a manager
     *     moved by hand;
     *   • `assignment_source` is left untouched. The Order still arrived by the
     *     route it arrived by; learning its Zone later does not make it a manual
     *     move, and overwriting the source would destroy the audit answer to
     *     "why is this Order here?";
     *   • the Zone comes from the SAME OrderZoneResolver used at collection, and
     *     the Slot from the SAME zone->slot map. No second rule exists.
     *
     * Idempotent: a second run finds nothing left with a NULL Zone that resolves.
     *
     * @return int assignments that gained a Zone on this run
     */
    public function reconcileUnzoned(string $companyId, string $windowId): int
    {
        $pending = DB::table('distribution_window_orders as dwo')
            ->join('orders as o', 'o.id', '=', 'dwo.order_id')
            ->where('dwo.company_id', $companyId)
            ->where('dwo.distribution_window_id', $windowId)
            ->whereNull('dwo.distribution_zone_id')
            ->whereNotNull('o.logistics_city_id')
            ->select(['dwo.id', 'o.logistics_city_id', 'o.assigned_warehouse_id'])
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $zoneByCity = $this->zones->resolveMany(
            $pending->map(static fn (object $r): int => (int) $r->logistics_city_id)->all(),
        );

        /** @var array<string, array<int, string>> $slotByZoneForWarehouse */
        $slotByZoneForWarehouse = [];

        $repaired = 0;

        foreach ($pending as $row) {
            $zoneId = $zoneByCity[(int) $row->logistics_city_id] ?? null;

            // The Group a re-zoned Order joins is its OWN warehouse's Group.
            $warehouseId = $row->assigned_warehouse_id;
            if ($warehouseId !== null && ! array_key_exists($warehouseId, $slotByZoneForWarehouse)) {
                $slotByZoneForWarehouse[$warehouseId] = $this->slotMapForWindow($windowId, $warehouseId);
            }
            $slotByZone = $warehouseId === null ? [] : $slotByZoneForWarehouse[$warehouseId];

            // The City is known but its Zone is not configured. That is a real
            // operational state, not a failure: the Order stays unzoned and the
            // Workspace reports the reason.
            if ($zoneId === null) {
                continue;
            }

            $updated = DB::table('distribution_window_orders')
                ->where('id', $row->id)
                ->whereNull('distribution_zone_id')
                ->update([
                    'distribution_zone_id' => $zoneId,
                    'virtual_slot_id' => $slotByZone[$zoneId] ?? null,
                    'updated_at' => now(),
                ]);

            $repaired += $updated;
        }

        return $repaired;
    }

    /**
     * Eligible Orders for this company that carry no Distribution assignment yet.
     *
     * @return list<object>
     */
    /**
     * PUBLIC as of TASK-003: the wave-start sweep asks this same question — "what work is
     * eligible and not yet in a window?" — and PART 3 names this method as the canonical
     * source. Exposing it is what keeps the sweep from growing a second, drifting
     * definition of eligibility. Behaviour is unchanged.
     */
    public function eligibleUnassignedOrders(string $companyId): array
    {
        /** @var list<string> $statuses */
        $statuses = (array) config('distribution.eligible_order_statuses', []);

        if ($statuses === []) {
            return [];
        }

        $query = DB::table('orders')
            ->where('orders.company_id', $companyId)
            ->whereIn('orders.status', $statuses)
            ->whereNull('orders.deleted_at')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('distribution_window_orders as dwo')
                    ->whereColumn('dwo.order_id', 'orders.id');
            });

        // Status is only HALF of eligibility. An Order postponed out of the current
        // preparation cycle still holds an eligible status, and collecting it would
        // put work in front of a warehouse that has already deferred it.
        return $this->preparation->excludePostponed($query)
            ->select('orders.id', 'orders.logistics_city_id', 'orders.assigned_warehouse_id')
            ->get()
            ->all();
    }

    /**
     * Zone → Slot map for one Window.
     *
     * Orders inherit their Slot from their Zone, so this is read once per run
     * rather than per Order.
     *
     * @return array<int, string>
     */
    public function slotMapForWindow(string $windowId, ?string $warehouseId = null): array
    {
        /** @var array<int, string> $map */
        $map = [];

        // Warehouse-aware: the same Zone can now be planned by two warehouses, each
        // in its own Group. Without the filter an Order would inherit whichever of
        // the two rows came back last — a cross-warehouse Group membership arriving
        // by the back door.
        DB::table('distribution_slot_zones')
            ->where('distribution_window_id', $windowId)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->select('distribution_zone_id', 'virtual_slot_id')
            ->get()
            ->each(function (object $row) use (&$map): void {
                $map[(int) $row->distribution_zone_id] = (string) $row->virtual_slot_id;
            });

        return $map;
    }
}
