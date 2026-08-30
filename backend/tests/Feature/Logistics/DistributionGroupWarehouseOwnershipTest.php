<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-5B — Group warehouse ownership.
 *
 * A Distribution Group is the Virtual Vehicle Planning Container for ONE
 * warehouse. It may hold several Zones, but never Orders from more than one
 * warehouse.
 *
 * Part 5A scoped what a Group REPORTED. This suite is about what a Group IS:
 * ownership lives in the database, and the cross-warehouse case is refused
 * server-side rather than filtered away afterwards.
 */
class DistributionGroupWarehouseOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Company $otherCompany;

    private Customer $customer;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private int $zoneMaadi;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->otherCompany = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouseA = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->warehouseB = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'GW-'.substr(uniqid(), -6),
            'name_ar' => 'Maadi', 'name_en' => 'Maadi',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $city = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorate,
            'name_ar' => 'المعادي', 'name_en' => 'Maadi',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $city)->update(['distribution_zone_id' => $this->zoneMaadi]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function order(Company $company, ?Warehouse $warehouse): Order
    {
        return Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-GW-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse?->id,
            'city' => 'Maadi',
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function userFor(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    private function refresh(Company $company): void
    {
        $this->actingAs($this->userFor($company))
            ->postJson(self::BASE.'/windows/collect')->assertOk();
    }

    /**
     * Make sure TODAY's Distribution Window row exists — fixture plumbing only.
     *
     * H1 = Option B: a READ never creates a Window. These fixtures used to obtain one as a
     * side effect of `GET /windows/current`, which is exactly the behaviour the ruling
     * removed. Creating it here as a plain idempotent insert keeps every assertion in this
     * class unchanged while no longer depending on a prohibited side effect.
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
            'id' => (string) Str::uuid(),
            'company_id' => $company,
            'window_date' => $date,
            'opens_at' => now()->startOfDay(),
            'closes_at' => now()->endOfDay(),
            'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function windowId(Company $company): string
    {
        $this->ensureTodayWindow($company->id);

        return $this->actingAs($this->userFor($company))
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()->json('data.window.id');
    }

    /** @return array<string,mixed> */
    private function createGroup(Company $company, Warehouse $warehouse, string $code): array
    {
        return $this->actingAs($this->userFor($company))
            ->postJson(self::BASE.'/windows/'.$this->windowId($company).'/slots', [
                'warehouse_id' => $warehouse->id,
                'code' => $code,
            ])
            ->assertStatus(201)
            ->json('data');
    }

    private function attachZone(Company $company, string $groupId, int $zoneId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->userFor($company))
            ->postJson(self::BASE.'/windows/'.$this->windowId($company)."/slots/{$groupId}/zones", [
                'zone_id' => $zoneId,
            ]);
    }

    /** @return list<array<string,mixed>> groups visible for a warehouse scope */
    private function groupsFor(Company $company, ?Warehouse $warehouse = null): array
    {
        $query = $warehouse === null ? '' : '?warehouse_id='.$warehouse->id;

        return $this->actingAs($this->userFor($company))
            ->getJson(self::BASE.'/windows/current'.$query)
            ->assertOk()->json('data.slots');
    }

    // ── 1. Ownership exists in the database ──────────────────────────────────

    public function test_the_group_table_carries_warehouse_ownership(): void
    {
        self::assertTrue(Schema::hasColumn('distribution_virtual_slots', 'warehouse_id'));
        self::assertTrue(Schema::hasColumn('distribution_slot_zones', 'warehouse_id'));

        // NOT NULL — ownership cannot be omitted at the storage layer.
        $column = DB::select('SHOW COLUMNS FROM distribution_virtual_slots WHERE Field = ?', ['warehouse_id'])[0];
        self::assertSame('NO', $column->Null);
    }

    public function test_a_created_group_records_its_warehouse(): void
    {
        $group = $this->createGroup($this->company, $this->warehouseA, 'DG-001');

        self::assertSame(
            $this->warehouseA->id,
            DB::table('distribution_virtual_slots')->where('id', $group['id'])->value('warehouse_id'),
        );
    }

    // ── 3. Creation REQUIRES a warehouse ─────────────────────────────────────

    public function test_a_group_cannot_be_created_without_a_warehouse(): void
    {
        $this->actingAs($this->userFor($this->company))
            ->postJson(self::BASE.'/windows/'.$this->windowId($this->company).'/slots', ['code' => 'DG-X'])
            ->assertStatus(422);

        self::assertSame(0, DB::table('distribution_virtual_slots')->count());
    }

    public function test_a_group_cannot_be_created_for_another_companys_warehouse(): void
    {
        $theirWarehouse = Warehouse::factory()->create(['company_id' => $this->otherCompany->id]);

        // 404, not 403: a warehouse outside the tenant boundary must not be
        // confirmed to exist.
        $this->actingAs($this->userFor($this->company))
            ->postJson(self::BASE.'/windows/'.$this->windowId($this->company).'/slots', [
                'warehouse_id' => $theirWarehouse->id,
                'code' => 'DG-X',
            ])
            ->assertStatus(404);

        self::assertSame(0, DB::table('distribution_virtual_slots')->count());
    }

    // ── 4/5. Read and totals are scoped by OWNERSHIP ─────────────────────────

    public function test_a_group_is_invisible_to_another_warehouse(): void
    {
        $this->createGroup($this->company, $this->warehouseA, 'DG-A');

        self::assertSame(['DG-A'], collect($this->groupsFor($this->company, $this->warehouseA))->pluck('code')->all());
        self::assertSame([], $this->groupsFor($this->company, $this->warehouseB));
    }

    public function test_group_totals_count_only_the_owning_warehouses_orders(): void
    {
        $mine = $this->order($this->company, $this->warehouseA);
        $this->order($this->company, $this->warehouseB);   // same zone, other warehouse
        $this->refresh($this->company);

        $group = $this->createGroup($this->company, $this->warehouseA, 'DG-A');
        $this->attachZone($this->company, $group['id'], $this->zoneMaadi)->assertOk();

        $slot = collect($this->groupsFor($this->company, $this->warehouseA))->firstWhere('code', 'DG-A');

        self::assertSame(1, $slot['orders_count']);
        self::assertSame(100.0, (float) $slot['total_value']);
        self::assertSame($this->warehouseA->id, $slot['warehouse_id']);

        // The decisive assertion: only the OWNER's order actually joined the group.
        $members = DB::table('distribution_window_orders')
            ->whereNotNull('virtual_slot_id')->pluck('order_id');
        self::assertSame([$mine->id], $members->all());
    }

    // ── 6/7. Cross-warehouse protection, server-side ─────────────────────────

    public function test_a_zone_holding_another_warehouses_orders_is_refused(): void
    {
        $this->order($this->company, $this->warehouseB);
        $this->refresh($this->company);

        $group = $this->createGroup($this->company, $this->warehouseA, 'DG-A');

        $response = $this->attachZone($this->company, $group['id'], $this->zoneMaadi);

        self::assertContains($response->status(), [409, 422], 'The attachment must be refused.');

        // NO PARTIAL WRITE: no zone link, and no order was moved.
        self::assertSame(0, DB::table('distribution_slot_zones')->count());
        self::assertSame(0, DB::table('distribution_window_orders')->whereNotNull('virtual_slot_id')->count());
    }

    public function test_two_warehouses_can_each_plan_the_same_zone_in_their_own_group(): void
    {
        $inA = $this->order($this->company, $this->warehouseA);
        $inB = $this->order($this->company, $this->warehouseB);
        $this->refresh($this->company);

        // The OLD unique index was (window, zone), which made this impossible — the
        // second attach silently stole the zone from the first group.
        $groupA = $this->createGroup($this->company, $this->warehouseA, 'DG-A');
        $groupB = $this->createGroup($this->company, $this->warehouseB, 'DG-B');

        $this->attachZone($this->company, $groupA['id'], $this->zoneMaadi)->assertOk();
        $this->attachZone($this->company, $groupB['id'], $this->zoneMaadi)->assertOk();

        $slotA = collect($this->groupsFor($this->company, $this->warehouseA))->firstWhere('code', 'DG-A');
        $slotB = collect($this->groupsFor($this->company, $this->warehouseB))->firstWhere('code', 'DG-B');

        self::assertSame(1, $slotA['orders_count']);
        self::assertSame(1, $slotB['orders_count']);

        // Each order sits in ITS OWN warehouse's group — never the other's.
        self::assertSame(
            $groupA['id'],
            DB::table('distribution_window_orders')->where('order_id', $inA->id)->value('virtual_slot_id'),
        );
        self::assertSame(
            $groupB['id'],
            DB::table('distribution_window_orders')->where('order_id', $inB->id)->value('virtual_slot_id'),
        );
    }

    // ── 8. Uniqueness within one warehouse still holds ───────────────────────

    public function test_a_zone_cannot_belong_to_two_groups_of_the_same_warehouse(): void
    {
        $order = $this->order($this->company, $this->warehouseA);
        $this->refresh($this->company);

        $first = $this->createGroup($this->company, $this->warehouseA, 'DG-A1');
        $second = $this->createGroup($this->company, $this->warehouseA, 'DG-A2');

        $this->attachZone($this->company, $first['id'], $this->zoneMaadi)->assertOk();
        $this->attachZone($this->company, $second['id'], $this->zoneMaadi)->assertOk();

        // Within ONE warehouse the zone still moves rather than duplicating: one row,
        // one owning group, and the order follows it.
        self::assertSame(1, DB::table('distribution_slot_zones')
            ->where('warehouse_id', $this->warehouseA->id)
            ->where('distribution_zone_id', $this->zoneMaadi)->count());

        self::assertSame(
            $second['id'],
            DB::table('distribution_window_orders')->where('order_id', $order->id)->value('virtual_slot_id'),
        );
    }

    public function test_the_uniqueness_index_now_includes_the_warehouse(): void
    {
        $index = collect(DB::select('SHOW INDEX FROM distribution_slot_zones'))
            ->where('Key_name', 'dist_slot_zones_window_wh_zone_unique')
            ->pluck('Column_name');

        self::assertEqualsCanonicalizing(
            ['distribution_window_id', 'warehouse_id', 'distribution_zone_id'],
            $index->all(),
        );

        // The old, warehouse-blind constraint is gone.
        self::assertCount(0, collect(DB::select('SHOW INDEX FROM distribution_slot_zones'))
            ->where('Key_name', 'dist_slot_zones_window_zone_unique')->all());
    }

    // ── 9. Company isolation ─────────────────────────────────────────────────

    public function test_another_company_never_sees_this_companys_groups(): void
    {
        $this->createGroup($this->company, $this->warehouseA, 'DG-A');

        self::assertSame([], $this->groupsFor($this->otherCompany));
    }

    public function test_group_creation_is_refused_without_a_company_scope(): void
    {
        $windowId = $this->windowId($this->company);
        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouseA->id,
                'code' => 'DG-X',
            ])
            ->assertStatus(403);
    }

    // ── 10/12. Existing behaviour and blast radius ───────────────────────────

    public function test_orders_with_no_warehouse_never_join_a_group(): void
    {
        $orphan = $this->order($this->company, null);
        $this->order($this->company, $this->warehouseA);
        $this->refresh($this->company);

        $group = $this->createGroup($this->company, $this->warehouseA, 'DG-A');
        $this->attachZone($this->company, $group['id'], $this->zoneMaadi)->assertOk();

        // A warehouse-less order cannot be adopted by a warehouse-owned group.
        self::assertNull(
            DB::table('distribution_window_orders')->where('order_id', $orphan->id)->value('virtual_slot_id'),
        );
    }

    public function test_group_ownership_changes_mutate_no_other_domain(): void
    {
        $order = $this->order($this->company, $this->warehouseA);
        $this->refresh($this->company);

        $before = (array) DB::table('orders')->where('id', $order->id)->first();
        $zonesBefore = DB::table('distribution_zones')->count();
        $windowsBefore = DB::table('distribution_windows')->count();

        $group = $this->createGroup($this->company, $this->warehouseA, 'DG-A');
        $this->attachZone($this->company, $group['id'], $this->zoneMaadi)->assertOk();

        self::assertSame($before, (array) DB::table('orders')->where('id', $order->id)->first(),
            'The order row must not change — only its distribution assignment does.');
        self::assertSame($zonesBefore, DB::table('distribution_zones')->count());
        self::assertSame($windowsBefore, DB::table('distribution_windows')->count());

        foreach ([
            'vehicle_plans', 'vehicle_plan_slots', 'vehicle_plan_slot_orders',
            'loading_sessions', 'vehicle_assignments', 'allocation_records',
            'preparation_waves', 'preparation_wave_orders',
        ] as $table) {
            self::assertSame(0, DB::table($table)->count(), "{$table} must remain untouched.");
        }
    }
}
