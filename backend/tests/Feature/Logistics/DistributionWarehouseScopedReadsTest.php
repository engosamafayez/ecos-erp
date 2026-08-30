<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-5A-WAREHOUSE-SCOPED-READS-001.
 *
 * D-P5-1: Distribution is warehouse-scoped, and the cycle comes from the same
 * wave resolver Preparation's own scheduler uses.
 *
 *   Order.assigned_warehouse_id -> Preparation Wave -> Distribution
 *
 * The selection contract under test:
 *   company_id + warehouse_id + planning_date ordering + wave_type=engine
 *   + WaveManager::ACTIVE_STATUSES (collecting, preparing)
 *
 * Read-scoping only. Nothing here writes to Preparation.
 */
class DistributionWarehouseScopedReadsTest extends TestCase
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
            'code' => 'WS-'.substr(uniqid(), -6),
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

    private function order(Company $company, ?Warehouse $warehouse, string $city): Order
    {
        return Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-WS-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse?->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    /**
     * A Preparation Wave. Defaults describe the canonical current cycle:
     * an ENGINE wave in a WaveManager ACTIVE_STATUS.
     */
    private function wave(
        Company $company,
        Warehouse $warehouse,
        string $status = 'collecting',
        string $waveType = 'engine',
        ?string $planningDate = null,
    ): string {
        $id = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'wave_number' => 'PREP-WS-'.substr(uniqid(), -8),
            'planning_date' => $planningDate ?? now()->toDateString(),
            'starts_at' => now()->copy()->setTime(17, 30),
            'intake_closes_at' => now()->copy()->addDay()->setTime(5, 0),
            'ends_at' => now()->copy()->addDay()->setTime(12, 0),
            'status' => $status,
            'wave_type' => $waveType,
            'created_at' => now(), 'updated_at' => now(),
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
        ]);

        return $id;
    }

    private function userFor(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    private function refresh(Company $company): void
    {
        $this->actingAs($this->userFor($company))
            ->postJson(self::BASE.'/windows/collect')
            ->assertOk();
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

    /** GET /windows/current, optionally scoped to a warehouse. */
    private function current(Company $company, ?Warehouse $warehouse = null): array
    {
        $this->ensureTodayWindow($company->id);

        $query = $warehouse === null ? '' : '?warehouse_id='.$warehouse->id;

        return $this->actingAs($this->userFor($company))
            ->getJson(self::BASE.'/windows/current'.$query)
            ->assertOk()
            ->json('data');
    }

    /** @return list<string> order numbers visible for this warehouse scope */
    private function pool(Company $company, ?Warehouse $warehouse = null): array
    {
        $this->ensureTodayWindow($company->id);
        $user = $this->userFor($company);
        $windowId = $this->actingAs($user)->getJson(self::BASE.'/windows/current')
            ->assertOk()->json('data.window.id');

        $query = $warehouse === null ? '' : '?warehouse_id='.$warehouse->id;

        return collect(
            $this->actingAs($user)->getJson(self::BASE."/windows/{$windowId}/orders".$query)
                ->assertOk()->json('data'),
        )->pluck('order_number')->all();
    }

    // ── 1–2. Each warehouse selects its OWN wave ─────────────────────────────

    public function test_each_warehouse_selects_its_own_wave(): void
    {
        $waveA = $this->wave($this->company, $this->warehouseA);
        $waveB = $this->wave($this->company, $this->warehouseB);

        self::assertSame($waveA, $this->current($this->company, $this->warehouseA)['preparation_wave']['wave_id']);
        self::assertSame($waveB, $this->current($this->company, $this->warehouseB)['preparation_wave']['wave_id']);
    }

    public function test_concurrent_waves_for_different_warehouses_do_not_cross(): void
    {
        // B's wave is created LAST, so a `starts_at`/insertion-order tie-break would
        // hand B's wave to A. Warehouse scoping is what makes that impossible.
        $waveA = $this->wave($this->company, $this->warehouseA);
        $waveB = $this->wave($this->company, $this->warehouseB);

        $cycleA = $this->current($this->company, $this->warehouseA)['preparation_wave'];
        $cycleB = $this->current($this->company, $this->warehouseB)['preparation_wave'];

        self::assertSame($waveA, $cycleA['wave_id']);
        self::assertSame($this->warehouseA->id, $cycleA['warehouse_id']);

        self::assertSame($waveB, $cycleB['wave_id']);
        self::assertSame($this->warehouseB->id, $cycleB['warehouse_id']);

        self::assertNotSame($cycleA['wave_id'], $cycleB['wave_id']);
    }

    // ── 3. Wrong-warehouse data excluded ─────────────────────────────────────

    public function test_a_warehouse_never_sees_another_warehouses_orders(): void
    {
        $this->wave($this->company, $this->warehouseA);
        $this->wave($this->company, $this->warehouseB);

        $inA = $this->order($this->company, $this->warehouseA, 'Maadi');
        $inB = $this->order($this->company, $this->warehouseB, 'Nasr City');

        $this->refresh($this->company);

        self::assertSame([$inA->order_number], $this->pool($this->company, $this->warehouseA));
        self::assertSame([$inB->order_number], $this->pool($this->company, $this->warehouseB));
    }

    public function test_zones_and_slots_are_warehouse_scoped(): void
    {
        $this->wave($this->company, $this->warehouseA);
        $this->order($this->company, $this->warehouseA, 'Maadi');
        $this->order($this->company, $this->warehouseB, 'Nasr City');
        $this->refresh($this->company);

        $zonesA = collect($this->current($this->company, $this->warehouseA)['zones']);
        $zonesB = collect($this->current($this->company, $this->warehouseB)['zones']);

        // A delivers only into Maadi; B only into Nasr City. Neither may see the
        // other's zone, and the zone is NOT inferred from geography.
        self::assertSame([$this->zoneMaadi], $zonesA->pluck('zone_id')->all());
        self::assertSame([$this->zoneNasr], $zonesB->pluck('zone_id')->all());
        self::assertSame(1, $zonesA->firstWhere('zone_id', $this->zoneMaadi)['order_count']);
    }

    public function test_distribution_groups_report_only_the_scoped_warehouses_orders(): void
    {
        $this->wave($this->company, $this->warehouseA);
        $this->order($this->company, $this->warehouseA, 'Maadi');
        $this->order($this->company, $this->warehouseB, 'Maadi');   // SAME zone, other warehouse
        $this->refresh($this->company);

        $user = $this->userFor($this->company);
        $windowId = $this->current($this->company)['window']['id'];

        $group = $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouseA->id,
                'code' => 'DG-001',
            ])
            ->assertStatus(201)->json('data');
        $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/zones", [
                'zone_id' => $this->zoneMaadi,
            ])->assertOk();

        $slotA = collect($this->current($this->company, $this->warehouseA)['slots'])->firstWhere('code', 'DG-001');
        $slotB = collect($this->current($this->company, $this->warehouseB)['slots'])->firstWhere('code', 'DG-001');

        // Part 5B closed the gap this test used to document. The Group is now OWNED
        // by warehouse A: B cannot see it at all, and only A's order is inside it.
        // Before ownership existed, both orders landed in the one shared group.
        self::assertSame(1, $slotA['orders_count']);
        self::assertSame($this->warehouseA->id, $slotA['warehouse_id']);
        self::assertNull($slotB, 'A group belongs to one warehouse; the other must not see it.');

        self::assertSame(1, DB::table('distribution_window_orders')
            ->whereNotNull('virtual_slot_id')->count(),
            'Only the owning warehouse\'s order joined the group.');
    }

    // ── 5–8. Only a canonical wave may govern ────────────────────────────────

    public function test_a_draft_wave_is_not_a_distribution_cycle(): void
    {
        $this->wave($this->company, $this->warehouseA, status: 'draft');

        self::assertNull($this->current($this->company, $this->warehouseA)['preparation_wave']);
    }

    public function test_a_shortage_blocked_wave_is_not_a_distribution_cycle(): void
    {
        // D-P5-1 §6: ACTIVE_STATUSES stays as it is, so a blocked wave yields no
        // cycle. Recorded as intended behaviour, not an accident.
        $this->wave($this->company, $this->warehouseA, status: 'shortage_blocked');

        self::assertNull($this->current($this->company, $this->warehouseA)['preparation_wave']);
    }

    public function test_a_preparing_wave_is_a_distribution_cycle(): void
    {
        $waveId = $this->wave($this->company, $this->warehouseA, status: 'preparing');

        self::assertSame($waveId, $this->current($this->company, $this->warehouseA)['preparation_wave']['wave_id']);
    }

    public function test_a_manual_wave_is_not_a_distribution_cycle(): void
    {
        // A manual wave "has no resolved boundaries", so it has no start, cutoff or
        // end to display — reporting it would print an empty clock.
        $this->wave($this->company, $this->warehouseA, waveType: 'manual');

        self::assertNull($this->current($this->company, $this->warehouseA)['preparation_wave']);
    }

    public function test_the_newest_planning_date_wins_not_the_oldest(): void
    {
        $this->wave($this->company, $this->warehouseA, planningDate: now()->subDays(3)->toDateString());
        $newest = $this->wave($this->company, $this->warehouseA, planningDate: now()->toDateString());

        self::assertSame($newest, $this->current($this->company, $this->warehouseA)['preparation_wave']['wave_id']);
    }

    // ── 9. Company isolation preserved ───────────────────────────────────────

    public function test_another_companys_wave_never_governs_this_company(): void
    {
        $theirWarehouse = Warehouse::factory()->create(['company_id' => $this->otherCompany->id]);
        $this->wave($this->otherCompany, $theirWarehouse);

        // Same company, no wave of its own -> no cycle. Never the other company's.
        self::assertNull($this->current($this->company, $this->warehouseA)['preparation_wave']);
    }

    public function test_a_warehouse_id_from_another_company_yields_no_cycle_and_no_orders(): void
    {
        $theirWarehouse = Warehouse::factory()->create(['company_id' => $this->otherCompany->id]);
        $this->wave($this->otherCompany, $theirWarehouse);
        $theirOrder = $this->order($this->otherCompany, $theirWarehouse, 'Maadi');

        $this->refresh($this->otherCompany);
        $this->refresh($this->company);

        // Asking for a warehouse outside the tenant leaks nothing: the wave lookup is
        // company-scoped, and the window is the acting company's own.
        $data = $this->current($this->company, $theirWarehouse);

        self::assertNull($data['preparation_wave']);
        self::assertNotContains($theirOrder->order_number, $this->pool($this->company, $theirWarehouse));
    }

    // ── 4/7. Omission, and orders with no warehouse ──────────────────────────

    public function test_omitting_the_warehouse_keeps_company_wide_data_but_yields_no_cycle(): void
    {
        $this->wave($this->company, $this->warehouseA);
        $a = $this->order($this->company, $this->warehouseA, 'Maadi');
        $b = $this->order($this->company, $this->warehouseB, 'Nasr City');
        $this->refresh($this->company);

        $data = $this->current($this->company);

        // DATA is unchanged — the certified company-wide behaviour of this endpoint.
        self::assertEqualsCanonicalizing(
            [$a->order_number, $b->order_number],
            $this->pool($this->company),
        );

        // The CYCLE is not guessed. One warehouse's wave cannot speak for the others.
        self::assertNull($data['preparation_wave']);
        self::assertNull($data['warehouse_id']);
    }

    public function test_an_order_with_no_warehouse_is_never_claimed_by_a_warehouse(): void
    {
        $this->wave($this->company, $this->warehouseA);
        $orphan = $this->order($this->company, null, 'Maadi');
        $this->refresh($this->company);

        // It belongs to no warehouse cycle and must not be silently adopted by one.
        self::assertNotContains($orphan->order_number, $this->pool($this->company, $this->warehouseA));
        self::assertNotContains($orphan->order_number, $this->pool($this->company, $this->warehouseB));

        // It is still reachable company-wide, so it is not lost — the dedicated
        // "No Warehouse" bucket remains a separate, still-required piece of work.
        self::assertContains($orphan->order_number, $this->pool($this->company));
    }

    // ── 12. Read-scoping only ────────────────────────────────────────────────

    public function test_scoped_reads_mutate_nothing(): void
    {
        $waveId = $this->wave($this->company, $this->warehouseA);
        $order = $this->order($this->company, $this->warehouseA, 'Maadi');
        $this->refresh($this->company);

        $before = [
            'wave' => (array) DB::table('preparation_waves')->where('id', $waveId)->first(),
            'order' => (array) DB::table('orders')->where('id', $order->id)->first(),
            'zones' => DB::table('distribution_zones')->count(),
            'assignments' => DB::table('distribution_window_orders')->count(),
        ];

        $this->current($this->company, $this->warehouseA);
        $this->current($this->company, $this->warehouseB);
        $this->pool($this->company, $this->warehouseA);

        self::assertSame($before['wave'], (array) DB::table('preparation_waves')->where('id', $waveId)->first());
        self::assertSame($before['order'], (array) DB::table('orders')->where('id', $order->id)->first());
        self::assertSame($before['zones'], DB::table('distribution_zones')->count());
        self::assertSame($before['assignments'], DB::table('distribution_window_orders')->count());

        foreach ([
            'vehicle_plans', 'vehicle_plan_slots', 'vehicle_plan_slot_orders',
            'loading_sessions', 'vehicle_assignments', 'allocation_records',
        ] as $table) {
            self::assertSame(0, DB::table($table)->count(), "{$table} must remain untouched.");
        }
    }
}
