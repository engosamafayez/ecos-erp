<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-5C — Group management.
 *
 * Add / Remove / Move a Zone inside a Distribution Group, under the rules Part 5B
 * put in the database:
 *
 *   RULE 1  A Group belongs to exactly one Warehouse.
 *   RULE 2  A Zone is geography and is NOT warehouse-owned.
 *   RULE 3  Per (warehouse, window) a Zone is in at most one Group.
 *   RULE 4  The same Zone may sit in Groups of DIFFERENT warehouses.
 *   RULE 5  Acting on Group A never touches another warehouse's claim.
 *   RULE 6/7 A Group only ever shows and counts its own warehouse's Orders.
 *   RULE 8  A Zone with no eligible Orders is not given manufactured membership.
 */
class DistributionGroupManagementTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Company $otherCompany;

    private Customer $customer;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private int $zoneMaadi;

    private int $zoneNasr;

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

        $this->zoneMaadi = $this->zone('Maadi');
        $this->zoneNasr = $this->zone('Nasr City');

        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneMaadi);
        $this->city($governorate, 'Nasr City', 'مدينة نصر', $this->zoneNasr);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'GM-'.substr(uniqid(), -6),
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

    private function order(Warehouse $warehouse, string $city, float $total = 100.0): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-GM-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => $total, 'total' => $total,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function line(Order $order, float $qty = 1.0): void
    {
        DB::table('order_lines')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => $qty,
            'unit_price' => 10,
            'line_total' => 10 * $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userFor(?Company $company = null): User
    {
        return User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
    }

    private function refresh(): void
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
            'id' => (string) Str::uuid(),
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

        return $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current')->assertOk()->json('data.window.id');
    }

    /** @return array<string,mixed> */
    private function group(Warehouse $warehouse, string $code): array
    {
        return $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/windows/'.$this->windowId().'/slots', [
                'warehouse_id' => $warehouse->id,
                'code' => $code,
            ])->assertStatus(201)->json('data');
    }

    private function addZone(string $groupId, int $zoneId): TestResponse
    {
        return $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/windows/'.$this->windowId()."/slots/{$groupId}/zones", [
                'zone_id' => $zoneId,
            ]);
    }

    private function removeZone(string $groupId, int $zoneId): TestResponse
    {
        return $this->actingAs($this->userFor())
            ->deleteJson(self::BASE.'/windows/'.$this->windowId()."/slots/{$groupId}/zones/{$zoneId}");
    }

    private function moveZone(string $fromId, string $toId, int $zoneId): TestResponse
    {
        return $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/windows/'.$this->windowId()."/slots/{$toId}/zones/move", [
                'zone_id' => $zoneId,
                'from_slot_id' => $fromId,
            ]);
    }

    /** @return array<string,mixed>|null */
    private function readGroup(Warehouse $warehouse, string $code): ?array
    {
        return collect(
            $this->actingAs($this->userFor())
                ->getJson(self::BASE.'/windows/current?warehouse_id='.$warehouse->id)
                ->assertOk()->json('data.slots'),
        )->firstWhere('code', $code);
    }

    private function slotOf(Order $order): ?string
    {
        return DB::table('distribution_window_orders')->where('order_id', $order->id)->value('virtual_slot_id');
    }

    // ── 1–3. Creation and ownership ──────────────────────────────────────────

    public function test_a_group_is_created_against_the_selected_warehouse(): void
    {
        $group = $this->group($this->warehouseA, 'DG-001');

        $read = $this->readGroup($this->warehouseA, 'DG-001');
        self::assertNotNull($read);
        self::assertSame($this->warehouseA->id, $read['warehouse_id']);
        self::assertSame($group['id'], $read['slot_id']);
    }

    public function test_a_group_without_a_warehouse_is_rejected(): void
    {
        $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/windows/'.$this->windowId().'/slots', ['code' => 'DG-X'])
            ->assertStatus(422);
    }

    public function test_an_empty_group_is_a_valid_state_and_stays_visible(): void
    {
        // No new status was invented for this: a Group with no zone links simply
        // reports zeros, which the existing model already represents.
        $this->group($this->warehouseA, 'DG-EMPTY');

        $read = $this->readGroup($this->warehouseA, 'DG-EMPTY');
        self::assertNotNull($read, 'An empty group must remain visible.');
        self::assertSame(0, $read['zones_count']);
        self::assertSame(0, $read['orders_count']);
        self::assertSame([], $read['zone_ids']);
    }

    // ── 4–7. Add Zone, and shared geography ──────────────────────────────────

    public function test_adding_a_zone_pulls_that_warehouses_orders_into_the_group(): void
    {
        $order = $this->order($this->warehouseA, 'Maadi');
        $this->line($order);
        $this->refresh();

        $group = $this->group($this->warehouseA, 'DG-001');
        $this->addZone($group['id'], $this->zoneMaadi)->assertOk();

        $read = $this->readGroup($this->warehouseA, 'DG-001');
        self::assertSame(1, $read['zones_count']);
        self::assertSame(1, $read['orders_count']);
        self::assertSame(1, $read['products_count']);
        self::assertSame(100.0, (float) $read['total_value']);
        self::assertSame($group['id'], $this->slotOf($order));
    }

    public function test_the_same_zone_can_be_planned_by_two_warehouses_at_once(): void
    {
        $inA = $this->order($this->warehouseA, 'Maadi');
        $inB = $this->order($this->warehouseB, 'Maadi');
        $this->refresh();

        $groupA = $this->group($this->warehouseA, 'DG-A');
        $groupB = $this->group($this->warehouseB, 'DG-B');

        $this->addZone($groupA['id'], $this->zoneMaadi)->assertOk();
        $this->addZone($groupB['id'], $this->zoneMaadi)->assertOk();

        // RULE 4 — geography is shared; each warehouse keeps its own claim.
        self::assertSame($groupA['id'], $this->slotOf($inA));
        self::assertSame($groupB['id'], $this->slotOf($inB));

        self::assertSame(1, $this->readGroup($this->warehouseA, 'DG-A')['orders_count']);
        self::assertSame(1, $this->readGroup($this->warehouseB, 'DG-B')['orders_count']);
    }

    public function test_a_zone_belongs_to_one_group_per_warehouse_and_window(): void
    {
        $order = $this->order($this->warehouseA, 'Maadi');
        $this->refresh();

        $first = $this->group($this->warehouseA, 'DG-A1');
        $second = $this->group($this->warehouseA, 'DG-A2');

        $this->addZone($first['id'], $this->zoneMaadi)->assertOk();
        $this->addZone($second['id'], $this->zoneMaadi)->assertOk();

        // RULE 3 — one link for this warehouse, and the order follows it.
        self::assertSame(1, DB::table('distribution_slot_zones')
            ->where('warehouse_id', $this->warehouseA->id)
            ->where('distribution_zone_id', $this->zoneMaadi)->count());
        self::assertSame($second['id'], $this->slotOf($order));
        self::assertSame(0, $this->readGroup($this->warehouseA, 'DG-A1')['orders_count']);
    }

    public function test_a_zone_with_work_only_for_another_warehouse_is_refused(): void
    {
        $this->order($this->warehouseB, 'Maadi');
        $this->refresh();

        $groupA = $this->group($this->warehouseA, 'DG-A');

        $this->addZone($groupA['id'], $this->zoneMaadi)->assertStatus(422);

        // RULE 8 — no manufactured membership, and no partial write.
        self::assertSame(0, DB::table('distribution_slot_zones')->count());
        self::assertSame(0, DB::table('distribution_window_orders')->whereNotNull('virtual_slot_id')->count());
    }

    // ── 8–9. Remove Zone ─────────────────────────────────────────────────────

    public function test_removing_a_zone_frees_it_without_touching_the_orders(): void
    {
        $order = $this->order($this->warehouseA, 'Maadi');
        $this->line($order);
        $this->refresh();

        $group = $this->group($this->warehouseA, 'DG-001');
        $this->addZone($group['id'], $this->zoneMaadi)->assertOk();

        $before = (array) DB::table('orders')->where('id', $order->id)->first();

        $this->removeZone($group['id'], $this->zoneMaadi)->assertOk();

        $read = $this->readGroup($this->warehouseA, 'DG-001');
        self::assertSame(0, $read['zones_count']);
        self::assertSame(0, $read['orders_count']);
        self::assertSame(0, $read['products_count']);
        self::assertSame(0.0, (float) $read['total_value']);

        // The Order is untouched and keeps its Zone — only the GROUP membership went.
        self::assertSame($before, (array) DB::table('orders')->where('id', $order->id)->first());
        self::assertNull($this->slotOf($order));
        self::assertSame(
            $this->zoneMaadi,
            (int) DB::table('distribution_window_orders')->where('order_id', $order->id)
                ->value('distribution_zone_id'),
        );
    }

    public function test_removing_a_zone_leaves_another_warehouses_claim_alone(): void
    {
        $inA = $this->order($this->warehouseA, 'Maadi');
        $inB = $this->order($this->warehouseB, 'Maadi');
        $this->refresh();

        $groupA = $this->group($this->warehouseA, 'DG-A');
        $groupB = $this->group($this->warehouseB, 'DG-B');
        $this->addZone($groupA['id'], $this->zoneMaadi)->assertOk();
        $this->addZone($groupB['id'], $this->zoneMaadi)->assertOk();

        $this->removeZone($groupA['id'], $this->zoneMaadi)->assertOk();

        // RULE 5 — B is entirely unaffected.
        self::assertNull($this->slotOf($inA));
        self::assertSame($groupB['id'], $this->slotOf($inB));
        self::assertSame(1, $this->readGroup($this->warehouseB, 'DG-B')['orders_count']);
        self::assertSame(1, DB::table('distribution_slot_zones')
            ->where('distribution_zone_id', $this->zoneMaadi)->count());
    }

    // ── 10–12. Move Zone ─────────────────────────────────────────────────────

    public function test_a_zone_moves_between_groups_of_the_same_warehouse(): void
    {
        $order = $this->order($this->warehouseA, 'Maadi');
        $this->line($order);
        $this->refresh();

        $from = $this->group($this->warehouseA, 'DG-A1');
        $to = $this->group($this->warehouseA, 'DG-A2');
        $this->addZone($from['id'], $this->zoneMaadi)->assertOk();

        $this->moveZone($from['id'], $to['id'], $this->zoneMaadi)->assertOk();

        self::assertSame(0, $this->readGroup($this->warehouseA, 'DG-A1')['orders_count']);
        self::assertSame(1, $this->readGroup($this->warehouseA, 'DG-A2')['orders_count']);
        self::assertSame($to['id'], $this->slotOf($order));

        // Atomic: exactly one link, never both and never neither.
        self::assertSame(1, DB::table('distribution_slot_zones')
            ->where('distribution_zone_id', $this->zoneMaadi)->count());
    }

    public function test_a_cross_warehouse_move_is_rejected_with_no_partial_write(): void
    {
        $inA = $this->order($this->warehouseA, 'Maadi');
        $this->refresh();

        $groupA = $this->group($this->warehouseA, 'DG-A');
        $groupB = $this->group($this->warehouseB, 'DG-B');
        $this->addZone($groupA['id'], $this->zoneMaadi)->assertOk();

        $this->moveZone($groupA['id'], $groupB['id'], $this->zoneMaadi)->assertStatus(422);

        // Nothing moved, nothing duplicated, nothing detached.
        self::assertSame($groupA['id'], $this->slotOf($inA));
        self::assertSame(1, DB::table('distribution_slot_zones')
            ->where('distribution_zone_id', $this->zoneMaadi)->count());
        self::assertSame(1, $this->readGroup($this->warehouseA, 'DG-A')['orders_count']);
        self::assertSame(0, $this->readGroup($this->warehouseB, 'DG-B')['orders_count']);
    }

    public function test_moving_a_zone_the_source_group_does_not_hold_is_rejected(): void
    {
        $this->order($this->warehouseA, 'Maadi');
        $this->refresh();

        $from = $this->group($this->warehouseA, 'DG-A1');
        $to = $this->group($this->warehouseA, 'DG-A2');

        $this->moveZone($from['id'], $to['id'], $this->zoneMaadi)->assertStatus(422);

        self::assertSame(0, DB::table('distribution_slot_zones')->count());
    }

    // ── 13–15. Totals and scoping ────────────────────────────────────────────

    public function test_group_totals_follow_every_zone_change(): void
    {
        $maadi = $this->order($this->warehouseA, 'Maadi', 100.0);
        $this->line($maadi);
        $nasr = $this->order($this->warehouseA, 'Nasr City', 50.0);
        $this->line($nasr);
        $this->line($nasr);
        $this->refresh();

        $group = $this->group($this->warehouseA, 'DG-001');

        $this->addZone($group['id'], $this->zoneMaadi)->assertOk();
        $afterFirst = $this->readGroup($this->warehouseA, 'DG-001');
        self::assertSame(1, $afterFirst['orders_count']);
        self::assertSame(100.0, (float) $afterFirst['total_value']);

        $this->addZone($group['id'], $this->zoneNasr)->assertOk();
        $afterSecond = $this->readGroup($this->warehouseA, 'DG-001');
        self::assertSame(2, $afterSecond['zones_count']);
        self::assertSame(2, $afterSecond['orders_count']);
        self::assertSame(3, $afterSecond['products_count']);
        self::assertSame(150.0, (float) $afterSecond['total_value']);

        $this->removeZone($group['id'], $this->zoneMaadi)->assertOk();
        $afterRemove = $this->readGroup($this->warehouseA, 'DG-001');
        self::assertSame(1, $afterRemove['zones_count']);
        self::assertSame(1, $afterRemove['orders_count']);
        self::assertSame(2, $afterRemove['products_count']);
        self::assertSame(50.0, (float) $afterRemove['total_value']);
    }

    public function test_group_orders_and_products_never_include_another_warehouse(): void
    {
        $mine = $this->order($this->warehouseA, 'Maadi');
        $this->line($mine);
        $theirs = $this->order($this->warehouseB, 'Maadi');
        $this->line($theirs);
        $this->line($theirs);
        $this->refresh();

        $group = $this->group($this->warehouseA, 'DG-A');
        $this->addZone($group['id'], $this->zoneMaadi)->assertOk();

        $read = $this->readGroup($this->warehouseA, 'DG-A');
        self::assertSame(1, $read['orders_count'], 'RULE 6/7 — only this warehouse.');
        self::assertSame(1, $read['products_count']);
        self::assertNull($this->slotOf($theirs));
    }

    // ── 19. Tenant isolation ─────────────────────────────────────────────────

    public function test_another_company_cannot_manage_these_groups(): void
    {
        $this->order($this->warehouseA, 'Maadi');
        $this->refresh();

        $group = $this->group($this->warehouseA, 'DG-A');
        $this->addZone($group['id'], $this->zoneMaadi)->assertOk();

        $windowId = $this->windowId();
        $intruder = $this->userFor($this->otherCompany);

        // Another company's window id is not theirs to address — 404, not 403.
        $this->actingAs($intruder)
            ->deleteJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/zones/{$this->zoneMaadi}")
            ->assertStatus(404);

        self::assertSame(1, DB::table('distribution_slot_zones')->count());
    }

    public function test_zone_management_is_refused_without_a_company_scope(): void
    {
        $this->order($this->warehouseA, 'Maadi');
        $this->refresh();
        $group = $this->group($this->warehouseA, 'DG-A');
        $windowId = $this->windowId();

        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)
            ->deleteJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/zones/{$this->zoneMaadi}")
            ->assertStatus(403);
    }

    // ── 20. Blast radius ─────────────────────────────────────────────────────

    public function test_group_management_mutates_no_other_domain(): void
    {
        $order = $this->order($this->warehouseA, 'Maadi');
        $this->line($order);
        $this->refresh();

        $from = $this->group($this->warehouseA, 'DG-A1');
        $to = $this->group($this->warehouseA, 'DG-A2');

        $orderBefore = (array) DB::table('orders')->where('id', $order->id)->first();
        $linesBefore = DB::table('order_lines')->where('order_id', $order->id)
            ->orderBy('id')->get()->map(static fn (object $r): array => (array) $r)->all();
        $zonesBefore = DB::table('distribution_zones')->count();

        $this->addZone($from['id'], $this->zoneMaadi)->assertOk();
        $this->moveZone($from['id'], $to['id'], $this->zoneMaadi)->assertOk();
        $this->removeZone($to['id'], $this->zoneMaadi)->assertOk();

        self::assertSame($orderBefore, (array) DB::table('orders')->where('id', $order->id)->first());
        self::assertSame($linesBefore, DB::table('order_lines')->where('order_id', $order->id)
            ->orderBy('id')->get()->map(static fn (object $r): array => (array) $r)->all());
        self::assertSame($zonesBefore, DB::table('distribution_zones')->count());

        foreach ([
            'preparation_waves', 'preparation_wave_orders',
            'vehicle_plans', 'vehicle_plan_slots', 'vehicle_plan_slot_orders',
            'loading_sessions', 'vehicle_assignments', 'allocation_records',
        ] as $table) {
            self::assertSame(0, DB::table($table)->count(), "{$table} must remain untouched.");
        }
    }
}
