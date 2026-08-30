<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-GROUP-TRIP-VEHICLE-DRIVER-LOADING-DISPATCH-IMPLEMENTATION-001.
 *
 * ┌─ THE APPROVED RELATION ──────────────────────────────────────────────────┐
 * │ Group = planning unit      Trip = transport execution unit                │
 * │ 1 Group → 1..N Trips (N only when Trip.capacity forces it)                │
 * │ 1 Trip  → exactly 1 Group, structurally                                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Finalize is the handover. It writes NO order status and NO inventory — those
 * belong to Dispatch, which is a later, separate stage and is not exercised here.
 */
class DistributionGroupTripTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private int $zoneMaadi;

    private Product $honey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouseA = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->warehouseB = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi');
        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneMaadi);

        $this->honey = Product::factory()->create();
    }

    // ── Finalize → Trip ───────────────────────────────────────────────────────

    public function test_finalize_creates_the_canonical_trip_and_is_idempotent(): void
    {
        $group = $this->groupWithOrders('DG-T1', 3);

        $first = $this->finalize($group)->assertOk()->json('data');

        // ONE Trip for a Group well inside capacity — the normal case.
        self::assertCount(1, $first);
        self::assertSame(3, (int) $first[0]['orders_count']);
        self::assertNotNull($first[0]['finalized_at'], 'Finalize must stamp finalized_at');

        // It is the CANONICAL Trip — a real distribution_trips row owned by this Group.
        self::assertDatabaseHas('distribution_trips', [
            'trip_number' => $first[0]['trip_number'],
            'virtual_slot_id' => $group['id'],
            'status' => TripStatus::Loading->value,
        ]);

        // IDEMPOTENT. A retry returns the same Trip and creates no second one.
        $second = $this->finalize($group)->assertOk()->json('data');

        self::assertCount(1, $second);
        self::assertSame($first[0]['trip_number'], $second[0]['trip_number']);
        self::assertSame(1, $this->tripCount($group));
        self::assertSame(3, (int) DB::table('distribution_trip_orders')->count());
    }

    public function test_a_trip_belongs_to_exactly_one_group(): void
    {
        $a = $this->groupWithOrders('DG-T2A', 2);
        $this->finalize($a)->assertOk();

        // The relation is a single-valued column on the Trip, so "two Groups" is not
        // an expressible state — this asserts the shape rather than a rule.
        $trip = DB::table('distribution_trips')->where('virtual_slot_id', $a['id'])->first();
        self::assertNotNull($trip);
        self::assertSame($a['id'], $trip->virtual_slot_id);

        self::assertTrue(
            DB::table('distribution_trips')->where('id', $trip->id)->count() === 1,
            'one row, one group reference',
        );
    }

    public function test_a_group_trip_refuses_an_order_from_another_group(): void
    {
        $a = $this->groupWithOrders('DG-T3A', 2);
        $this->finalize($a)->assertOk();

        // A second Group in a DIFFERENT zone, so its orders are genuinely elsewhere.
        $zoneNasr = $this->zone('Nasr City');
        $this->city(
            (int) DB::table('logistics_governorates')->value('id'),
            'Nasr City',
            'مدينة نصر',
            $zoneNasr,
        );
        $foreign = $this->order($this->warehouseA, 'Nasr City');
        $this->line($foreign, $this->honey->id, 1);
        $this->collect();
        $b = $this->group($this->warehouseA, 'DG-T3B');
        $this->addZone($b['id'], $zoneNasr);

        $tripUuid = DB::table('distribution_trips')->where('virtual_slot_id', $a['id'])->value('uuid');

        // Group A's Trip must refuse Group B's order.
        $this->actingAs($this->userFor())
            ->postJson("{$this->tripBase()}/trips/{$tripUuid}/orders", ['order_id' => $foreign->id])
            ->assertStatus(422);

        self::assertDatabaseMissing('distribution_trip_orders', ['order_id' => $foreign->id]);
    }

    // ── Capacity ──────────────────────────────────────────────────────────────

    public function test_trip_capacity_forces_a_split_and_never_duplicates_an_order(): void
    {
        // 61 orders against a default trip capacity of 60 — the smallest input that
        // proves the split is real rather than configured.
        $group = $this->groupWithOrders('DG-T4', 61);

        $trips = $this->finalize($group)->assertOk()->json('data');

        self::assertCount(2, $trips, 'a Group over one Trip capacity must produce two Trips');
        self::assertSame(60, (int) $trips[0]['orders_count'], 'the first Trip fills to capacity');
        self::assertSame(1, (int) $trips[1]['orders_count'], 'the remainder overflows into the next');

        // Every order placed exactly once across the whole Group.
        self::assertSame(61, (int) DB::table('distribution_trip_orders')->count());
        self::assertSame(
            61,
            (int) DB::table('distribution_trip_orders')->distinct()->count('order_id'),
            'no order may appear on two Trips',
        );

        // Both Trips belong to the SAME Group — a split never leaks work elsewhere.
        self::assertSame(2, $this->tripCount($group));
    }

    public function test_group_capacity_is_enforced_at_finalize_and_null_stays_unconstrained(): void
    {
        $group = $this->groupWithOrders('DG-T5', 3);

        // NULL capacity means unconstrained, never zero — finalizing must succeed.
        $this->finalize($group)->assertOk();
        self::assertSame(1, $this->tripCount($group));

        // A second Group whose maximum is below its membership cannot be finalized.
        $tight = $this->groupWithOrders('DG-T6', 3);
        DB::table('distribution_virtual_slots')->where('id', $tight['id'])->update(['capacity_orders' => 2]);

        $this->finalize($tight)->assertStatus(422);
        self::assertSame(0, $this->tripCount($tight), 'a refused Finalize creates nothing');
    }

    public function test_an_empty_group_cannot_be_finalized(): void
    {
        // Part 11: never create empty Trips.
        $empty = $this->group($this->warehouseA, 'DG-T7');

        $this->finalize($empty)->assertStatus(422);
        self::assertSame(0, $this->tripCount($empty));
    }

    public function test_an_over_prepared_group_cannot_be_finalized(): void
    {
        $group = $this->groupWithOrders('DG-T8', 2);

        // Prepared exceeds what the Group now requires — Required fell after the floor
        // separated stock. Finalizing would send a Trip against a diverged plan.
        DB::table('distribution_group_product_preparation')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $this->company->id,
            'distribution_window_id' => $this->windowId(),
            'virtual_slot_id' => $group['id'],
            'product_id' => $this->honey->id,
            'prepared_qty' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->finalize($group)->assertStatus(422);
        self::assertSame(0, $this->tripCount($group));
    }

    // ── Boundaries ────────────────────────────────────────────────────────────

    public function test_finalize_writes_no_order_status_and_no_inventory(): void
    {
        $group = $this->groupWithOrders('DG-T9', 2);

        $before = [
            'orders' => DB::table('orders')->orderBy('id')->pluck('status', 'id')->toArray(),
            'inventory_items' => DB::table('inventory_items')->count(),
            'stock_ledger_entries' => DB::table('stock_ledger_entries')->count(),
            'loading_sessions' => DB::table('loading_sessions')->count(),
            'loading_tasks' => DB::table('loading_tasks')->count(),
            'wave_product_demand' => DB::table('wave_product_demand')->count(),
            'preparation_wave_items' => DB::table('preparation_wave_items')->count(),
            'distribution_window_orders' => DB::table('distribution_window_orders')->count(),
        ];

        $this->finalize($group)->assertOk();

        self::assertSame(
            $before['orders'],
            DB::table('orders')->orderBy('id')->pluck('status', 'id')->toArray(),
            'Finalize must not move a single order status — Dispatch owns that',
        );

        foreach (['inventory_items', 'stock_ledger_entries', 'loading_sessions', 'loading_tasks',
            'wave_product_demand', 'preparation_wave_items', 'distribution_window_orders'] as $table) {
            self::assertSame($before[$table], DB::table($table)->count(), "{$table} must be untouched");
        }
    }

    public function test_the_group_warehouse_is_derived_through_the_trip_relation(): void
    {
        $group = $this->groupWithOrders('DG-T10', 2);
        $this->finalize($group)->assertOk();

        // The Trip carries NO warehouse column — it resolves through its Group, so the
        // invariant "a Trip executes from its Group's warehouse" holds by construction.
        self::assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('distribution_trips', 'warehouse_id'),
            'warehouse must NOT be copied onto the Trip',
        );

        $trip = \Modules\Logistics\Distribution\Domain\Models\Trip::query()
            ->where('virtual_slot_id', $group['id'])
            ->firstOrFail();

        self::assertSame($this->warehouseA->id, $trip->operationalWarehouseId());
    }

    // ── Security ──────────────────────────────────────────────────────────────

    public function test_a_foreign_tenant_can_neither_finalize_nor_read_a_group_trip(): void
    {
        $group = $this->groupWithOrders('DG-T11', 2);
        $this->finalize($group)->assertOk();

        $windowId = $this->windowId();
        $outsider = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        // NOT FOUND, never 403 — a foreign window must read as non-existent.
        $this->actingAs($outsider)
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/finalize")
            ->assertNotFound();

        $this->actingAs($outsider)
            ->getJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/trips")
            ->assertNotFound();

        self::assertSame(1, $this->tripCount($group), 'the foreign call changed nothing');
    }

    public function test_a_trip_cannot_be_read_across_tenants(): void
    {
        // The Part 21 security fix: TripController had no company scope at all, so any
        // authenticated user could read or mutate any company's trip by uuid.
        $group = $this->groupWithOrders('DG-T12', 2);
        $this->finalize($group)->assertOk();

        $tripUuid = DB::table('distribution_trips')->where('virtual_slot_id', $group['id'])->value('uuid');
        $outsider = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($outsider)->getJson("{$this->tripBase()}/trips/{$tripUuid}")->assertNotFound();
        $this->actingAs($outsider)
            ->patchJson("{$this->tripBase()}/trips/{$tripUuid}/status", ['status' => TripStatus::Cancelled->value])
            ->assertNotFound();

        self::assertSame(
            TripStatus::Loading->value,
            DB::table('distribution_trips')->where('uuid', $tripUuid)->value('status'),
            'the foreign mutation must not have landed',
        );
    }

    public function test_the_trip_list_is_scoped_to_the_acting_company(): void
    {
        $group = $this->groupWithOrders('DG-T13', 2);
        $this->finalize($group)->assertOk();

        $outsider = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        // No company_id filter supplied — previously that returned EVERY tenant's trips.
        $rows = $this->actingAs($outsider)->getJson("{$this->tripBase()}/trips")->assertOk()->json('data');

        self::assertSame([], $rows, 'an unfiltered list must not leak another company\'s trips');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function tripBase(): string
    {
        return '/api/logistics/distribution';
    }

    /** @return array<string, mixed> a Group holding $count eligible orders */
    private function groupWithOrders(string $code, int $count): array
    {
        for ($i = 0; $i < $count; $i++) {
            $o = $this->order($this->warehouseA, 'Maadi');
            $this->line($o, $this->honey->id, 1);
        }

        $this->collect();
        $group = $this->group($this->warehouseA, $code);

        if ($count > 0) {
            $this->addZone($group['id'], $this->zoneMaadi);
        }

        return $group;
    }

    private function finalize(array $group): \Illuminate\Testing\TestResponse
    {
        $windowId = $this->windowId();

        return $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/finalize");
    }

    private function tripCount(array $group): int
    {
        return (int) DB::table('distribution_trips')->where('virtual_slot_id', $group['id'])->count();
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'GT-'.substr(uniqid(), -6),
            'name_ar' => $name, 'name_en' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(int $governorate, string $en, string $ar, int $zoneId): void
    {
        $id = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorate,
            'name_ar' => $ar, 'name_en' => $en,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $id)->update(['distribution_zone_id' => $zoneId]);
    }

    private function order(Warehouse $warehouse, string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-GT-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
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

    private function userFor(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function collect(): void
    {
        $this->actingAs($this->userFor())->postJson(self::BASE.'/windows/collect')->assertOk();
    }

    /**
     * Make sure TODAY's Distribution Window row exists — fixture plumbing only.
     *
     * H1 = Option B: a READ never creates a Window. This fixture used to obtain one as a
     * side effect of `GET /windows/current`, which is exactly the behaviour the ruling
     * removed. Creating it here as a plain idempotent insert leaves every assertion in
     * this class unchanged while no longer depending on a prohibited side effect.
     */
    private function ensureTodayWindow(?string $companyId = null): void
    {
        $company = $companyId ?? $this->company->id;
        $date = now()->toDateString();

        $exists = DB::table('distribution_windows')
            ->where('company_id', $company)
            ->whereDate('window_date', $date)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('distribution_windows')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $company,
            'window_date' => $date,
            'opens_at' => now()->startOfDay(),
            'closes_at' => now()->endOfDay(),
            'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function windowId(): string
    {
        $this->ensureTodayWindow();

        return (string) $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()
            ->json('data.window.id');
    }

    /** @return array<string, mixed> */
    private function group(Warehouse $warehouse, string $code): array
    {
        $windowId = $this->windowId();

        return $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $warehouse->id,
                'code' => $code,
            ])->assertStatus(201)->json('data');
    }

    private function addZone(string $groupId, int $zoneId): void
    {
        $windowId = $this->windowId();

        $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$groupId}/zones", ['zone_id' => $zoneId])
            ->assertOk();
    }
}
