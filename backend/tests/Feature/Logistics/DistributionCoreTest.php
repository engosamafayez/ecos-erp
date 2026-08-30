<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Distribution\Domain\Enums\DistributionAssignmentSource;
use Modules\Logistics\Distribution\Domain\Enums\DistributionWindowStatus;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DistributionAggregationService;
use Modules\Logistics\Distribution\Domain\Services\DistributionCollectionService;
use Modules\Logistics\Distribution\Domain\Services\DistributionWindowService;
use Modules\Logistics\Distribution\Domain\Services\ManualAssignmentService;
use Modules\Logistics\Distribution\Domain\Services\RedistributionSuggestionService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-SHIPPING-DISTRIBUTION-CORE-001 — runtime certification.
 *
 * Database-backed throughout. Nothing is mocked: the real services run against
 * real rows, and every assertion reads persisted state.
 *
 * The Window is pinned to 08:00–14:00 in setUp so that "before cutoff" and
 * "after cutoff" are exact instants rather than whatever time the suite happens
 * to run at.
 */
final class DistributionCoreTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private Customer $customer;

    private int $zoneA;

    private int $zoneB;

    private int $cityA;

    private int $cityB;

    /** 10:00 — inside the Window. */
    private CarbonImmutable $beforeCutoff;

    /** 15:00 — past the 14:00 cutoff. */
    private CarbonImmutable $afterCutoff;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '08:00');
        config()->set('distribution.window.closes_at', '14:00');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->customer = Customer::factory()->create();

        $governorate = DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'محافظة',
            'name_en' => 'Governorate',
            'default_shipping_price' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->zoneA = $this->makeZone('ZA', 'Zone A');
        $this->zoneB = $this->makeZone('ZB', 'Zone B');

        $this->cityA = $this->makeCity($governorate, 'City A', $this->zoneA);
        $this->cityB = $this->makeCity($governorate, 'City B', $this->zoneB);

        $today = CarbonImmutable::now()->toDateString();
        $this->beforeCutoff = CarbonImmutable::parse($today.' 10:00:00');
        $this->afterCutoff = CarbonImmutable::parse($today.' 15:00:00');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeZone(string $code, string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => $code.'-'.substr(uniqid(), -5),
            'name_ar' => $name.' '.substr(uniqid(), -5),
            'name_en' => $name.' '.substr(uniqid(), -5),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeCity(int $governorateId, string $name, int $zoneId): int
    {
        $id = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorateId,
            'name_ar' => $name.' '.substr(uniqid(), -5),
            'name_en' => $name.' '.substr(uniqid(), -5),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('logistics_cities')->where('id', $id)->update(['distribution_zone_id' => $zoneId]);

        return $id;
    }

    private function order(
        string $status = 'in_progress',
        ?Company $company = null,
        ?int $cityId = null,
    ): Order {
        return Order::query()->create([
            'company_id' => ($company ?? $this->companyA)->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            // The SAME memoised warehouse the fixture's Groups are owned by. A Group
            // only absorbs its own warehouse's orders (Part 5B), so an order with no
            // warehouse — or a different one — could never join a slot, which is not
            // what these tests are about.
            'assigned_warehouse_id' => $this->slotWarehouseId(($company ?? $this->companyA)->id),
            'logistics_city_id' => $cityId ?? $this->cityA,
            'status' => $status,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);
    }

    private function collect(?CarbonImmutable $at = null, ?Company $company = null): array
    {
        return app(DistributionCollectionService::class)
            ->collectForCompany(($company ?? $this->companyA)->id, $at ?? $this->beforeCutoff);
    }

    private function currentWindowId(?Company $company = null, ?CarbonImmutable $at = null): string
    {
        return app(DistributionWindowService::class)
            ->windowFor(($company ?? $this->companyA)->id, ($at ?? $this->beforeCutoff)->toDateString(), $at ?? $this->beforeCutoff)
            ->id;
    }

    private function makeSlot(string $windowId, string $code, ?int $capacity, ?Company $company = null): VirtualCapacitySlot
    {
        return VirtualCapacitySlot::query()->create([
            'company_id' => ($company ?? $this->companyA)->id,
            'distribution_window_id' => $windowId,
            // A Distribution Group is owned by exactly one warehouse (Part 5B);
            // the column is NOT NULL, so a fixture must name the owner.
            'warehouse_id' => $this->slotWarehouseId(($company ?? $this->companyA)->id),
            'code' => $code,
            'capacity_orders' => $capacity,
        ]);
    }

    private function assignment(Order $order): DistributionWindowOrder
    {
        return DistributionWindowOrder::query()->where('order_id', $order->id)->firstOrFail();
    }

    private function manager(): User
    {
        return User::factory()->create(['company_id' => $this->companyA->id]);
    }

    // ── TEST 1–3 — eligible statuses before cutoff ───────────────────────────

    public function test_1_new_order_before_cutoff_enters_current_window_zone_and_slot(): void
    {
        $windowId = $this->currentWindowId();
        $slot = $this->makeSlot($windowId, 'S1', 100);
        app(ManualAssignmentService::class)->assignZoneToSlot(
            app(DistributionWindowService::class)->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff),
            $this->zoneA,
            $slot,
        );

        $order = $this->order(OrderStatus::InProgress->value);
        $this->collect();

        $a = $this->assignment($order);
        self::assertSame($windowId, $a->distribution_window_id);
        self::assertSame($this->zoneA, $a->distribution_zone_id);
        self::assertSame($slot->id, $a->virtual_slot_id);
        self::assertSame(DistributionAssignmentSource::Automatic, $a->assignment_source);
    }

    public function test_2_in_progress_order_before_cutoff_enters_current_window(): void
    {
        $order = $this->order(OrderStatus::InProgress->value);
        $this->collect();

        self::assertSame($this->currentWindowId(), $this->assignment($order)->distribution_window_id);
    }

    public function test_3_confirmed_order_before_cutoff_enters_current_window(): void
    {
        // ADR-042 §7: `confirmed` is a first-class fulfilment-eligible STATUS (not an
        // in_progress order carrying a confirmed_at timestamp). Distribution admits it via the
        // same closed [in_progress, confirmed] list.
        $order = $this->order(OrderStatus::Confirmed->value);

        $this->collect();

        self::assertSame($this->currentWindowId(), $this->assignment($order)->distribution_window_id);
    }

    // ── TEST 4 — ineligible statuses ─────────────────────────────────────────

    public function test_4_ineligible_statuses_are_never_collected_automatically(): void
    {
        $ineligible = [
            OrderStatus::ReadyForDispatch, OrderStatus::OutForDelivery, OrderStatus::Delivered,
            OrderStatus::AwaitingStock, OrderStatus::AwaitingPayment, OrderStatus::OnHold,
            OrderStatus::Scheduled, OrderStatus::Cancelled, OrderStatus::Returned,
        ];

        $orders = [];

        foreach ($ineligible as $status) {
            $orders[$status->value] = $this->order($status->value);
        }

        $this->collect();

        foreach ($orders as $status => $order) {
            self::assertDatabaseMissing('distribution_window_orders', ['order_id' => $order->id]);
        }
    }

    // ── TEST 5 — late order goes to the NEXT window ──────────────────────────

    public function test_5_order_after_cutoff_goes_to_the_next_window(): void
    {
        $currentId = $this->currentWindowId();

        $order = $this->order();
        $this->collect($this->afterCutoff);

        $a = $this->assignment($order);
        self::assertNotSame($currentId, $a->distribution_window_id, 'A late Order must not enter the closed Window.');

        $next = DB::table('distribution_windows')->where('id', $a->distribution_window_id)->first();
        self::assertSame(
            CarbonImmutable::parse($this->beforeCutoff->toDateString())->addDay()->toDateString(),
            CarbonImmutable::parse($next->window_date)->toDateString(),
        );
    }

    // ── TEST 6 + 19 — Manual Late-Order Assignment ───────────────────────────

    public function test_6_manager_manually_adds_late_order_to_current_window(): void
    {
        $windows = app(DistributionWindowService::class);
        $window = $windows->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->afterCutoff);

        $slot = $this->makeSlot($window->id, 'S1', 100);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $slot);

        // Arrives late — automatic collection puts it in tomorrow.
        $order = $this->order();
        $this->collect($this->afterCutoff);
        self::assertNotSame($window->id, $this->assignment($order)->distribution_window_id);

        $manager = $this->manager();
        $moved = app(ManualAssignmentService::class)->assignLateOrder(
            $window, $order->id, (int) $manager->id, 'customer called', $this->afterCutoff,
        );

        self::assertSame($window->id, $moved->distribution_window_id);
        self::assertSame($this->zoneA, $moved->distribution_zone_id);
        self::assertSame($slot->id, $moved->virtual_slot_id);
        self::assertSame(DistributionAssignmentSource::ManualLate, $moved->assignment_source);
        self::assertNotNull($moved->previous_window_id, 'The prior Window must be retained for audit.');
    }

    public function test_19_late_manual_assignment_updates_aggregation_immediately(): void
    {
        $windows = app(DistributionWindowService::class);
        $window = $windows->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->afterCutoff);
        $slot = $this->makeSlot($window->id, 'S1', 100);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $slot);

        $agg = app(DistributionAggregationService::class);
        self::assertSame(0, $agg->slotOrderCounts($window->id)[$slot->id] ?? 0);

        $order = $this->order();
        $this->collect($this->afterCutoff);
        app(ManualAssignmentService::class)->assignLateOrder(
            $window, $order->id, null, null, $this->afterCutoff,
        );

        self::assertSame(1, $agg->slotOrderCounts($window->id)[$slot->id] ?? 0);
    }

    // ── TEST 7 + 8 + 10 — live aggregation ───────────────────────────────────

    public function test_7_new_order_entering_existing_zone_updates_zone_count(): void
    {
        $this->order();
        $this->collect();

        $windowId = $this->currentWindowId();
        $agg = app(DistributionAggregationService::class);
        self::assertSame(1, $this->zoneCount($agg->zoneSummaries($windowId), $this->zoneA));

        $this->order();
        $this->collect();

        self::assertSame(2, $this->zoneCount($agg->zoneSummaries($windowId), $this->zoneA));
    }

    public function test_8_new_order_in_slotted_zone_updates_slot_aggregation(): void
    {
        $windows = app(DistributionWindowService::class);
        $window = $windows->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);
        $slot = $this->makeSlot($window->id, 'S1', 100);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $slot);

        $this->order();
        $this->collect();

        $agg = app(DistributionAggregationService::class);
        self::assertSame(1, $agg->slotOrderCounts($window->id)[$slot->id] ?? 0);

        $this->order();
        $this->collect();

        self::assertSame(2, $agg->slotOrderCounts($window->id)[$slot->id] ?? 0);
    }

    public function test_10_zone_attached_to_slot_after_collection_pulls_existing_orders_in(): void
    {
        $windows = app(DistributionWindowService::class);
        $window = $windows->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);

        $this->order();
        $this->order();
        $this->collect();

        $slot = $this->makeSlot($window->id, 'S1', 100);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $slot);

        self::assertSame(2, app(DistributionAggregationService::class)->slotOrderCounts($window->id)[$slot->id] ?? 0);
    }

    // ── TEST 9 + 11 + 12 — overflow, suggestions, approval ───────────────────

    public function test_9_zone_exceeding_slot_capacity_is_detected_as_overflow(): void
    {
        $window = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);

        $slot = $this->makeSlot($window->id, 'S1', 2);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $slot);

        for ($i = 0; $i < 5; $i++) {
            $this->order();
        }
        $this->collect();

        $summary = collect(app(DistributionAggregationService::class)->slotSummaries($window->id))
            ->firstWhere('slot_id', $slot->id);

        self::assertTrue($summary['is_over_capacity']);
        self::assertSame(3, $summary['overflow_orders']);
        self::assertSame(5, $summary['demand_orders']);
    }

    public function test_11_overflow_produces_suggestions_that_do_not_mutate_anything(): void
    {
        $window = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);

        $full = $this->makeSlot($window->id, 'S1', 2);
        $spare = $this->makeSlot($window->id, 'S2', 50);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $full);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneB, $spare);

        for ($i = 0; $i < 5; $i++) {
            $this->order();
        }
        $this->collect();

        $before = DB::table('distribution_window_orders')
            ->where('distribution_window_id', $window->id)->orderBy('id')->pluck('virtual_slot_id')->all();

        $overflows = app(RedistributionSuggestionService::class)->overflows($window->id);

        self::assertCount(1, $overflows);
        self::assertSame(3, $overflows[0]['excess_orders']);
        self::assertNotEmpty($overflows[0]['suggestions'], 'An overflow with a spare Slot must produce candidates.');
        self::assertSame(
            $spare->id,
            $overflows[0]['suggestions'][0]['candidate_slots'][0]['slot_id'],
            'The Slot with capacity must be the top candidate.',
        );

        $after = DB::table('distribution_window_orders')
            ->where('distribution_window_id', $window->id)->orderBy('id')->pluck('virtual_slot_id')->all();

        self::assertSame($before, $after, 'Generating suggestions must not move a single Order.');
    }

    public function test_12_manager_approving_a_suggestion_changes_the_assignment(): void
    {
        $window = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);

        $full = $this->makeSlot($window->id, 'S1', 1);
        $spare = $this->makeSlot($window->id, 'S2', 50);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $full);

        $order = $this->order();
        $this->order();
        $this->collect();

        $suggestion = app(RedistributionSuggestionService::class)->overflows($window->id)[0]['suggestions'][0];

        $assignment = DistributionWindowOrder::query()->findOrFail($suggestion['assignment_id']);
        app(ManualAssignmentService::class)->changeOrderSlot($assignment, $spare, null, 'approved');

        self::assertSame($spare->id, $assignment->fresh()->virtual_slot_id);
        self::assertSame(1, app(DistributionAggregationService::class)->slotOrderCounts($window->id)[$full->id] ?? 0);
    }

    // ── TEST 13 + 14 — manual overrides AFTER cutoff ─────────────────────────

    public function test_13_manager_changes_zone_after_cutoff(): void
    {
        $order = $this->order();
        $this->collect();

        $window = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->afterCutoff);

        self::assertSame(DistributionWindowStatus::CutoffReached, $window->status);

        $updated = app(ManualAssignmentService::class)
            ->changeOrderZone($this->assignment($order), $this->zoneB, null, 'manager move');

        self::assertSame($this->zoneB, $updated->fresh()->distribution_zone_id);
    }

    public function test_14_manager_changes_slot_after_cutoff(): void
    {
        $window = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);
        $slot = $this->makeSlot($window->id, 'S9', 10);

        $order = $this->order();
        $this->collect();

        // Advance past cutoff.
        app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->afterCutoff);

        $updated = app(ManualAssignmentService::class)
            ->changeOrderSlot($this->assignment($order), $slot, null, 'manager move');

        self::assertSame($slot->id, $updated->fresh()->virtual_slot_id);
    }

    // ── TEST 15 — idempotency ────────────────────────────────────────────────

    public function test_15_repeated_collection_creates_no_duplicates(): void
    {
        $order = $this->order();

        $first = $this->collect();
        $second = $this->collect();
        $third = $this->collect();

        self::assertCount(1, $first);
        self::assertCount(0, $second, 'A second pass must create nothing.');
        self::assertCount(0, $third);

        self::assertSame(
            1,
            DB::table('distribution_window_orders')->where('order_id', $order->id)->count(),
        );
    }

    // ── TEST 16 — tenant isolation ───────────────────────────────────────────

    public function test_16_two_companies_are_completely_isolated(): void
    {
        $orderA = $this->order(company: $this->companyA);
        $orderB = $this->order(company: $this->companyB);

        $this->collect(company: $this->companyA);
        $this->collect(company: $this->companyB);

        $windowA = $this->currentWindowId($this->companyA);
        $windowB = $this->currentWindowId($this->companyB);

        self::assertNotSame($windowA, $windowB, 'Each company gets its own Window.');
        self::assertSame($windowA, $this->assignment($orderA)->distribution_window_id);
        self::assertSame($windowB, $this->assignment($orderB)->distribution_window_id);

        $ordersInA = app(DistributionAggregationService::class)->orders($windowA);
        self::assertCount(1, $ordersInA);
        self::assertSame($orderA->id, $ordersInA[0]['order_id']);

        // Company A's Window must never expose Company B's Order.
        self::assertNotContains(
            $orderB->id,
            array_column(app(DistributionAggregationService::class)->orders($windowA), 'order_id'),
        );
    }

    public function test_16b_late_assignment_cannot_cross_company_boundary(): void
    {
        $windowA = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->afterCutoff);

        $orderB = $this->order(company: $this->companyB);

        $this->expectExceptionMessage('Order not found.');

        app(ManualAssignmentService::class)
            ->assignLateOrder($windowA, $orderB->id, null, null, $this->afterCutoff);
    }

    // ── TEST 17 — the Vehicle boundary ───────────────────────────────────────

    public function test_17_virtual_slot_creates_no_real_vehicle_assignment(): void
    {
        $window = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);

        $slot = $this->makeSlot($window->id, 'S1', 10);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $slot);

        $this->order();
        $this->collect();

        // The Slot table has no vehicle/driver column at all — the boundary is
        // structural, not a nulled-out field.
        $columns = array_map(
            static fn ($c) => is_object($c) ? (string) $c->Field : (string) $c,
            DB::select('SHOW COLUMNS FROM distribution_virtual_slots'),
        );

        foreach ($columns as $column) {
            self::assertStringNotContainsString('vehicle', strtolower($column));
            self::assertStringNotContainsString('driver', strtolower($column));
        }

        self::assertSame(0, DB::table('distribution_trips')->count(), 'Planning must create no Trip.');
    }

    // ── TEST 18 — product aggregation ────────────────────────────────────────

    public function test_18_product_aggregation_reports_exact_quantities(): void
    {
        $honey = Product::factory()->create();
        $coffee = Product::factory()->create();

        $o1 = $this->order();
        $o2 = $this->order();

        $this->line($o1, $honey->id, 10);
        $this->line($o1, $coffee->id, 5);
        $this->line($o2, $honey->id, 30);

        $this->collect();

        $rows = app(DistributionAggregationService::class)->productAggregation($this->currentWindowId());
        $byProduct = [];

        foreach ($rows as $r) {
            $byProduct[(string) $r['product_id']] = $r['total_quantity'];
        }

        self::assertSame(40.0, $byProduct[$honey->id]);
        self::assertSame(5.0, $byProduct[$coffee->id]);
    }

    // ── TEST 20 — lifecycle independence ─────────────────────────────────────

    public function test_20_order_lifecycle_status_is_untouched_by_distribution(): void
    {
        $order = $this->order(OrderStatus::InProgress->value);
        $this->collect();

        $window = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);
        $slot = $this->makeSlot($window->id, 'S1', 10);

        app(ManualAssignmentService::class)->changeOrderZone($this->assignment($order), $this->zoneB, null);
        app(ManualAssignmentService::class)->changeOrderSlot($this->assignment($order), $slot, null);

        $order->refresh();

        self::assertSame(OrderStatus::InProgress, $order->status, 'Distribution must never move the Order lifecycle.');
        self::assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::InProgress->value]);
    }

    // ── TEST 21 + 22 — individual Order reassignment (contract clarification) ─

    /**
     * Moving ONE Order between Slots must move exactly that Order.
     *
     * The Zone is deliberately untouched: the Order has not physically moved, so
     * `changeOrderSlot` overrides Slot membership only. The Zone→Slot mapping
     * also stays put — otherwise re-pointing one Order would silently drag every
     * other Order in the Zone with it, which is the failure this test exists to
     * catch.
     */
    public function test_21_individual_order_moves_between_slots_without_disturbing_its_zone_or_peers(): void
    {
        $window = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);

        $slot1 = $this->makeSlot($window->id, 'S1', 10);
        $slot2 = $this->makeSlot($window->id, 'S2', 10);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $slot1);

        $o1 = $this->order();
        $o2 = $this->order();
        $o3 = $this->order();
        $this->collect();

        foreach ([$o1, $o2, $o3] as $o) {
            self::assertSame($slot1->id, $this->assignment($o)->virtual_slot_id);
        }

        // Move ONLY order 2.
        app(ManualAssignmentService::class)
            ->changeOrderSlot($this->assignment($o2), $slot2, null, 'operator rebalance');

        self::assertSame($slot1->id, $this->assignment($o1)->fresh()->virtual_slot_id, 'Order 1 must not move.');
        self::assertSame($slot2->id, $this->assignment($o2)->fresh()->virtual_slot_id, 'Order 2 must move.');
        self::assertSame($slot1->id, $this->assignment($o3)->fresh()->virtual_slot_id, 'Order 3 must not move.');

        // Zone is unchanged for all three — the Order did not physically relocate.
        foreach ([$o1, $o2, $o3] as $o) {
            self::assertSame($this->zoneA, $this->assignment($o)->fresh()->distribution_zone_id);
        }

        // The Zone→Slot mapping itself is untouched.
        self::assertDatabaseHas('distribution_slot_zones', [
            'distribution_window_id' => $window->id,
            'distribution_zone_id' => $this->zoneA,
            'virtual_slot_id' => $slot1->id,
        ]);

        // The Zone is reported ONCE, with its full count, and flagged as spanning
        // Slots — not split into two rows of 2 and 1.
        $zones = app(DistributionAggregationService::class)->zoneSummaries($window->id);
        $zoneA = collect($zones)->firstWhere('zone_id', $this->zoneA);

        self::assertCount(1, collect($zones)->where('zone_id', $this->zoneA));
        self::assertSame(3, $zoneA['order_count']);
        self::assertSame($slot1->id, $zoneA['virtual_slot_id'], 'Reports the PLANNED Slot.');
        self::assertTrue($zoneA['spans_slots']);
    }

    /**
     * After an individual move, every derived figure must follow immediately:
     * counts, per-Slot product totals, utilisation and overflow.
     */
    public function test_22_live_aggregation_updates_on_both_slots_after_reassignment(): void
    {
        $window = app(DistributionWindowService::class)
            ->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->beforeCutoff);

        // Capacity 2 with 3 Orders makes the source Slot genuinely over capacity,
        // so the move must also clear the overflow.
        $slot1 = $this->makeSlot($window->id, 'S1', 2);
        $slot2 = $this->makeSlot($window->id, 'S2', 10);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $slot1);

        $honey = Product::factory()->create();

        $o1 = $this->order();
        $o2 = $this->order();
        $o3 = $this->order();
        $this->line($o1, $honey->id, 10);
        $this->line($o2, $honey->id, 20);
        $this->line($o3, $honey->id, 30);
        $this->collect();

        $agg = app(DistributionAggregationService::class);

        // Before: everything on Slot 1, which is over its capacity of 2.
        self::assertSame(3, $agg->slotOrderCounts($window->id)[$slot1->id] ?? 0);
        self::assertSame(0, $agg->slotOrderCounts($window->id)[$slot2->id] ?? 0);
        self::assertSame(60.0, $this->slotProductTotal($agg, $window->id, $slot1->id, $honey->id));

        $before = collect($agg->slotSummaries($window->id))->keyBy('slot_id');
        self::assertTrue($before[$slot1->id]['is_over_capacity']);
        self::assertSame(1, $before[$slot1->id]['overflow_orders']);
        self::assertSame(1.5, $before[$slot1->id]['utilisation']);

        app(ManualAssignmentService::class)
            ->changeOrderSlot($this->assignment($o2), $slot2, null, 'rebalance');

        // Counts: source decreases, destination increases.
        self::assertSame(2, $agg->slotOrderCounts($window->id)[$slot1->id] ?? 0);
        self::assertSame(1, $agg->slotOrderCounts($window->id)[$slot2->id] ?? 0);

        // Product totals follow the Order, not the Zone.
        self::assertSame(40.0, $this->slotProductTotal($agg, $window->id, $slot1->id, $honey->id));
        self::assertSame(20.0, $this->slotProductTotal($agg, $window->id, $slot2->id, $honey->id));

        // Utilisation recomputed, and the overflow is resolved.
        $after = collect($agg->slotSummaries($window->id))->keyBy('slot_id');
        self::assertSame(1.0, $after[$slot1->id]['utilisation']);
        self::assertSame(0, $after[$slot1->id]['overflow_orders']);
        self::assertFalse($after[$slot1->id]['is_over_capacity'], 'Moving one Order must clear the overflow.');
        self::assertSame(0.1, $after[$slot2->id]['utilisation']);

        // Zone totals are unaffected — no Order left Zone A.
        self::assertSame(3, $this->zoneCount($agg->zoneSummaries($window->id), $this->zoneA));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function slotProductTotal(
        DistributionAggregationService $agg,
        string $windowId,
        string $slotId,
        string $productId,
    ): float {
        foreach ($agg->productAggregation($windowId, null, $slotId) as $row) {
            if ((string) $row['product_id'] === $productId) {
                return (float) $row['total_quantity'];
            }
        }

        return 0.0;
    }

    private function line(Order $order, string $productId, float $qty): void
    {
        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => 10,
            'line_total' => $qty * 10,
        ]);
    }

    /** @param  list<array<string, mixed>>  $summaries */
    private function zoneCount(array $summaries, int $zoneId): int
    {
        foreach ($summaries as $s) {
            if ($s['zone_id'] === $zoneId) {
                return (int) $s['order_count'];
            }
        }

        return 0;
    }

    /**
     * A warehouse to own a fixture Group.
     *
     * Part 5B: `distribution_virtual_slots.warehouse_id` is NOT NULL, because a
     * Distribution Group is the planning container for exactly ONE warehouse.
     * Memoised per company so repeated fixtures reuse the same warehouse.
     *
     * @var array<string, string>
     */
    private array $slotWarehouses = [];

    private function slotWarehouseId(string $companyId): string
    {
        return $this->slotWarehouses[$companyId] ??= Warehouse::factory()
            ->create(['company_id' => $companyId])->id;
    }

}
