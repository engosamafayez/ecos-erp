<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-4-DISTRIBUTION-GROUPS-001.
 *
 * Zone tabs + Distribution Groups + a Preparation-aligned distribution cycle.
 *
 * A Distribution Group is the existing Virtual Capacity Slot surfaced under the
 * approved name. It holds one or more Zones and their Orders, and deliberately
 * holds no vehicle and no driver — those belong to a later phase.
 */
class DistributionGroupsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $companyA;

    private Company $companyB;

    private Customer $customer;

    /**
     * Orders always carry a warehouse in reality — Preparation's collector matches
     * on `assigned_warehouse_id`, and a Distribution Group is owned by exactly one
     * warehouse. A fixture without it models an order that cannot be worked.
     */
    private Warehouse $warehouse;

    private int $governorate;

    private int $zoneMaadi;

    private int $zoneNasr;

    private int $cityMaadi;

    private int $cityNasr;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->companyA->id]);

        $this->governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi');
        $this->zoneNasr = $this->zone('Nasr City');

        $this->cityMaadi = $this->city('Maadi', 'المعادي', $this->zoneMaadi);
        $this->cityNasr = $this->city('Nasr City', 'مدينة نصر', $this->zoneNasr);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'DG-'.substr(uniqid(), -6),
            'name_ar' => $name, 'name_en' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(string $en, string $ar, ?int $zoneId): int
    {
        $id = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $this->governorate,
            'name_ar' => $ar, 'name_en' => $en,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $id)->update(['distribution_zone_id' => $zoneId]);

        return $id;
    }

    private function order(Company $company, string $city, array $extra = []): Order
    {
        return Order::query()->create(array_merge([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-G-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouseFor($company),
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ], $extra));
    }

    /** Company A uses the shared fixture warehouse; any other company gets its own. */
    private function warehouseFor(Company $company): string
    {
        if ($company->id === $this->companyA->id) {
            return $this->warehouse->id;
        }

        return $this->otherWarehouses[$company->id] ??= Warehouse::factory()
            ->create(['company_id' => $company->id])->id;
    }

    /** @var array<string, string> */
    private array $otherWarehouses = [];

    private function line(Order $order, float $qty = 1.0): void
    {
        DB::table('order_lines')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => $qty,
            'unit_price' => 10,
            'line_total' => 10 * $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * An ACTIVE Preparation Wave — the cycle Distribution must align to.
     *
     * `wave_type` is set explicitly. The column DEFAULTS to 'standard', but only an
     * ENGINE wave carries resolved boundaries, and D-P5-1 makes engine part of the
     * canonical selection contract. A fixture relying on the default would describe
     * a wave the scheduler never created.
     */
    private function wave(Company $company, string $status = 'collecting'): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $this->waveWarehouseId = Warehouse::factory()->create(['company_id' => $company->id])->id;

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $company->id,
            'warehouse_id' => $this->waveWarehouseId,
            'wave_type' => 'engine',
            'wave_number' => 'PREP-TEST-'.substr(uniqid(), -6),
            'planning_date' => now()->toDateString(),
            'starts_at' => now()->copy()->setTime(17, 30),
            'intake_closes_at' => now()->copy()->addDay()->setTime(5, 0),
            'ends_at' => now()->copy()->addDay()->setTime(12, 0),
            'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
            'created_by' => (string) \Illuminate\Support\Str::uuid(),
            'updated_by' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        return $id;
    }

    /** The warehouse of the most recently created wave, for scoping the cycle read. */
    private ?string $waveWarehouseId = null;

    /**
     * The operational cycle, read the way the workspace reads it: SCOPED TO A
     * WAREHOUSE. D-P5-1 — a company-wide read reports no cycle at all, because one
     * warehouse's wave cannot speak for the others.
     */
    private function cycle(Company $company): ?array
    {
        return $this->actingAs($this->userFor($company))
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->waveWarehouseId)
            ->assertOk()
            ->json('data.preparation_wave');
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $company,
            'window_date' => $date,
            'opens_at' => now()->startOfDay(),
            'closes_at' => now()->endOfDay(),
            'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function current(Company $company): array
    {
        $this->ensureTodayWindow($company->id);

        return $this->actingAs($this->userFor($company))
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()
            ->json('data');
    }

    private function windowId(Company $company): string
    {
        return $this->current($company)['window']['id'];
    }

    /** @return array<string,mixed> the created group */
    private function createGroup(Company $company, string $code, array $zoneIds): array
    {
        $user = $this->userFor($company);
        $windowId = $this->windowId($company);

        $group = $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                // A Group is owned by exactly one warehouse (Part 5B).
                'warehouse_id' => $this->warehouseFor($company),
                'code' => $code,
            ])
            ->assertStatus(201)
            ->json('data');

        foreach ($zoneIds as $zoneId) {
            $this->actingAs($user)
                ->postJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/zones", [
                    'zone_id' => $zoneId,
                ])
                ->assertOk();
        }

        return $group;
    }

    private function groupsFor(Company $company): array
    {
        return $this->current($company)['slots'];
    }

    // ── 1–2. The distribution cycle is the Preparation Wave's ────────────────

    public function test_the_distribution_cycle_reports_the_active_preparation_wave(): void
    {
        $waveId = $this->wave($this->companyA);

        $cycle = $this->cycle($this->companyA);

        self::assertNotNull($cycle, 'Distribution must report the wave it aligns to.');
        self::assertSame($waveId, $cycle['wave_id']);

        $wave = DB::table('preparation_waves')->where('id', $waveId)->first();

        // Boundaries are REPORTED, not recomputed: a second schedule would be
        // free to drift from Preparation's, which is the whole failure mode.
        self::assertSame($wave->starts_at, $cycle['starts_at']);
        self::assertSame($wave->intake_closes_at, $cycle['cutoff_at']);
        self::assertSame($wave->ends_at, $cycle['ends_at']);
        self::assertSame($wave->status, $cycle['status']);
    }

    public function test_the_cycle_timezone_is_the_companys_operational_timezone(): void
    {
        $this->wave($this->companyA);
        DB::table('companies')->where('id', $this->companyA->id)->update(['timezone' => 'Africa/Cairo']);

        self::assertSame('Africa/Cairo', $this->cycle($this->companyA)['timezone']);
    }

    public function test_a_closed_wave_does_not_govern_the_cycle(): void
    {
        $this->wave($this->companyA, 'closed');

        // Nothing is invented to fill the gap: no active wave means no cycle.
        self::assertNull($this->cycle($this->companyA));
    }

    public function test_the_cycle_is_scoped_to_the_acting_company(): void
    {
        $this->wave($this->companyB);

        // The wave belongs to another company's warehouse — never this company's cycle.
        self::assertNull($this->cycle($this->companyA));
    }

    // ── 3–4. All Orders / Zone tabs ──────────────────────────────────────────

    public function test_all_orders_shows_each_eligible_order_exactly_once(): void
    {
        $multi = $this->order($this->companyA, 'Maadi');
        $this->line($multi, 2);
        $this->line($multi, 3);
        $this->order($this->companyA, 'Nasr City');

        $this->refresh($this->companyA);

        $user = $this->userFor($this->companyA);
        $rows = $this->actingAs($user)
            ->getJson(self::BASE.'/windows/'.$this->windowId($this->companyA).'/orders')
            ->assertOk()->json('data');

        self::assertCount(2, $rows);
        self::assertCount(2, collect($rows)->pluck('order_id')->unique());
    }

    public function test_a_zone_tab_returns_only_that_zones_orders(): void
    {
        $maadi = $this->order($this->companyA, 'Maadi');
        $nasr = $this->order($this->companyA, 'Nasr City');
        $this->refresh($this->companyA);

        $user = $this->userFor($this->companyA);
        $base = self::BASE.'/windows/'.$this->windowId($this->companyA).'/orders';

        $inMaadi = $this->actingAs($user)->getJson($base."?zone_id={$this->zoneMaadi}")->assertOk()->json('data');
        $inNasr = $this->actingAs($user)->getJson($base."?zone_id={$this->zoneNasr}")->assertOk()->json('data');

        self::assertSame([$maadi->order_number], collect($inMaadi)->pluck('order_number')->all());
        self::assertSame([$nasr->order_number], collect($inNasr)->pluck('order_number')->all());
    }

    // ── 5–6. Unassigned ──────────────────────────────────────────────────────

    public function test_unresolved_orders_are_reported_with_their_reason(): void
    {
        $this->order($this->companyA, 'Atlantis');
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);

        $rows = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE.'/windows/'.$this->windowId($this->companyA).'/orders')
            ->assertOk()->json('data');

        $unassigned = collect($rows)->filter(fn (array $r): bool => $r['zone_id'] === null);

        self::assertCount(1, $unassigned);
        self::assertSame('city_not_resolved', $unassigned->first()['unassigned_reason']);
    }

    public function test_the_unassigned_bucket_is_zero_not_absent_when_everything_resolves(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);

        $rows = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE.'/windows/'.$this->windowId($this->companyA).'/orders')
            ->assertOk()->json('data');

        self::assertCount(0, collect($rows)->filter(fn (array $r): bool => $r['zone_id'] === null));
    }

    // ── 7. Full shipping address ─────────────────────────────────────────────

    public function test_the_full_shipping_address_comes_from_the_order(): void
    {
        $this->order($this->companyA, 'Maadi', [
            'customer_name' => 'Recipient Name',
            'billing_phone' => '01000000000',
            'shipping_address' => '2 Shalaby Street',
            'building' => 'B7',
            'floor' => '3',
            'apartment' => '12',
            'landmark' => 'Next to City Stars',
            'area' => 'Degla',
            'address_notes' => 'Ring twice',
        ]);
        $this->refresh($this->companyA);

        $row = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE.'/windows/'.$this->windowId($this->companyA).'/orders')
            ->assertOk()->json('data.0');

        $address = $row['shipping_address'];

        self::assertSame('2 Shalaby Street', $address['street']);
        self::assertSame('B7', $address['building']);
        self::assertSame('3', $address['floor']);
        self::assertSame('12', $address['apartment']);
        self::assertSame('Next to City Stars', $address['landmark']);
        self::assertSame('Degla', $address['area']);
        self::assertSame('Ring twice', $address['notes']);
        self::assertSame('Maadi', $address['city']);
        self::assertSame('Cairo', $address['governorate']);
        self::assertSame('01000000000', $address['phone']);
    }

    public function test_a_missing_address_field_stays_null_and_is_never_reconstructed(): void
    {
        // No street. The zone and city are known — neither may stand in for it.
        $this->order($this->companyA, 'Maadi', ['shipping_address' => null, 'building' => null]);
        $this->refresh($this->companyA);

        $address = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE.'/windows/'.$this->windowId($this->companyA).'/orders')
            ->assertOk()->json('data.0.shipping_address');

        self::assertNull($address['street']);
        self::assertNull($address['building']);
        self::assertSame('Maadi', $address['city'], 'City is still reported, from the city itself.');
    }

    // ── 8. Payment method ────────────────────────────────────────────────────

    public function test_payment_method_uses_the_orders_source_of_truth(): void
    {
        $this->order($this->companyA, 'Maadi', ['payment_method_manual' => 'cod']);
        $this->order($this->companyA, 'Nasr City', ['payment_method' => 'instapay']);
        $this->refresh($this->companyA);

        $rows = collect(
            $this->actingAs($this->userFor($this->companyA))
                ->getJson(self::BASE.'/windows/'.$this->windowId($this->companyA).'/orders')
                ->assertOk()->json('data'),
        )->keyBy('order_number');

        $methods = $rows->pluck('payment_method_effective')->sort()->values()->all();

        self::assertSame(['cod', 'instapay'], $methods);
    }

    // ── 9–11. Creating groups ────────────────────────────────────────────────

    public function test_a_distribution_group_can_be_created_from_one_zone(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);

        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi]);

        $groups = $this->groupsFor($this->companyA);

        self::assertCount(1, $groups);
        self::assertSame('DG-001', $groups[0]['code']);
        self::assertSame('draft', $groups[0]['status']);
        self::assertSame([$this->zoneMaadi], $groups[0]['zone_ids']);
        self::assertSame(1, $groups[0]['zones_count']);
    }

    public function test_one_group_can_hold_several_zones(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->order($this->companyA, 'Nasr City');
        $this->refresh($this->companyA);

        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi, $this->zoneNasr]);

        $group = $this->groupsFor($this->companyA)[0];

        // A group is NOT a vehicle: one zone or several is a planning decision.
        self::assertSame(2, $group['zones_count']);
        self::assertEqualsCanonicalizing([$this->zoneMaadi, $this->zoneNasr], $group['zone_ids']);
        self::assertEqualsCanonicalizing(['Maadi', 'Nasr City'], $group['zone_names']);
    }

    public function test_a_zone_cannot_end_up_in_two_groups(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);

        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi]);
        $this->createGroup($this->companyA, 'DG-002', [$this->zoneMaadi]);

        $groups = collect($this->groupsFor($this->companyA))->keyBy('code');

        // The unique index on (window, zone) means the second assignment MOVES the
        // zone rather than duplicating it. What must never happen is the zone
        // counting in both groups at once.
        self::assertSame([], $groups['DG-001']['zone_ids']);
        self::assertSame([$this->zoneMaadi], $groups['DG-002']['zone_ids']);
        self::assertSame(1, DB::table('distribution_slot_zones')
            ->where('distribution_zone_id', $this->zoneMaadi)->count());
    }

    // ── 12. Order ownership ──────────────────────────────────────────────────

    public function test_an_order_belongs_to_at_most_one_group_in_a_window(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);

        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi]);
        $this->createGroup($this->companyA, 'DG-002', [$this->zoneMaadi]);

        $slotIds = DB::table('distribution_window_orders')
            ->where('company_id', $this->companyA->id)
            ->pluck('virtual_slot_id');

        // One column, one value — an order cannot be in two groups by construction.
        self::assertCount(1, $slotIds->unique());

        $groups = collect($this->groupsFor($this->companyA))->keyBy('code');
        self::assertSame(0, $groups['DG-001']['orders_count']);
        self::assertSame(1, $groups['DG-002']['orders_count']);
    }

    public function test_orders_already_collected_join_the_group_when_their_zone_does(): void
    {
        // Collected BEFORE any group exists — the assignment carries no slot yet.
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);

        self::assertSame(1, DB::table('distribution_window_orders')->whereNull('virtual_slot_id')->count());

        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi]);

        self::assertSame(0, DB::table('distribution_window_orders')->whereNull('virtual_slot_id')->count());
        self::assertSame(1, $this->groupsFor($this->companyA)[0]['orders_count']);
    }

    // ── 13–14. Group summary ─────────────────────────────────────────────────

    public function test_group_totals_match_the_orders_it_holds(): void
    {
        $a = $this->order($this->companyA, 'Maadi', ['total' => 120, 'deposit_amount' => 120]); // paid
        $this->line($a, 1);
        $this->line($a, 2);                                                                     // 2 products
        $b = $this->order($this->companyA, 'Maadi', ['total' => 80]);                           // unpaid
        $this->line($b, 1);
        $c = $this->order($this->companyA, 'Nasr City', ['total' => 50]);
        $this->line($c, 1);

        $this->refresh($this->companyA);
        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi, $this->zoneNasr]);

        $group = $this->groupsFor($this->companyA)[0];

        self::assertSame(3, $group['orders_count']);
        self::assertSame(4, $group['products_count'], 'Products are summed per order, not per line join.');
        self::assertSame(250.0, (float) $group['total_value']);
        self::assertSame(1, $group['paid_orders']);
        self::assertSame(2, $group['unpaid_orders']);
    }

    public function test_group_orders_are_retrievable_by_group(): void
    {
        $maadi = $this->order($this->companyA, 'Maadi');
        $this->order($this->companyA, 'Nasr City');
        $this->refresh($this->companyA);

        $group = $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi]);

        $rows = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE.'/windows/'.$this->windowId($this->companyA)."/orders?slot_id={$group['id']}")
            ->assertOk()->json('data');

        self::assertSame([$maadi->order_number], collect($rows)->pluck('order_number')->all());
    }

    // ── 15. Persistence ──────────────────────────────────────────────────────

    public function test_groups_persist_across_requests(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);
        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi]);

        // A fresh request, a fresh actor — the group is state, not session data.
        $groups = $this->groupsFor($this->companyA);

        self::assertCount(1, $groups);
        self::assertSame('DG-001', $groups[0]['code']);
        self::assertSame(1, DB::table('distribution_virtual_slots')->count());
    }

    // ── 16. Tenant isolation ─────────────────────────────────────────────────

    public function test_groups_are_invisible_to_another_company(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->order($this->companyB, 'Maadi');
        $this->refresh($this->companyA);
        $this->refresh($this->companyB);

        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi]);

        self::assertCount(1, $this->groupsFor($this->companyA));
        self::assertCount(0, $this->groupsFor($this->companyB));
    }

    public function test_group_creation_is_refused_without_a_company_scope(): void
    {
        // Resolve the window FIRST. windowId() authenticates as a company-A user,
        // and actingAs() mutates the test's actor - inlining it into the call
        // below would silently re-authenticate and test nothing.
        $windowId = $this->windowId($this->companyA);

        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'X',
            ])
            ->assertStatus(403);
    }

    // ── 17–23. Blast radius ──────────────────────────────────────────────────

    public function test_grouping_mutates_no_other_domain(): void
    {
        $waveId = $this->wave($this->companyA);
        $order = $this->order($this->companyA, 'Maadi');
        $this->line($order, 2);
        $this->refresh($this->companyA);

        $before = [
            'wave' => (array) DB::table('preparation_waves')->where('id', $waveId)->first(),
            'order' => (array) DB::table('orders')->where('id', $order->id)->first(),
            // Cast each row to an array: `get()` returns fresh stdClass instances on
            // every call, so an identity comparison would fail on the object hash
            // even when every column is unchanged.
            'lines' => DB::table('order_lines')->where('order_id', $order->id)
                ->orderBy('id')->get()->map(static fn (object $r): array => (array) $r)->all(),
        ];

        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi]);

        self::assertSame($before['wave'], (array) DB::table('preparation_waves')->where('id', $waveId)->first(),
            'Preparation must not be mutated.');
        self::assertSame($before['order'], (array) DB::table('orders')->where('id', $order->id)->first(),
            'The order itself must not be mutated.');
        self::assertSame(
            $before['lines'],
            DB::table('order_lines')->where('order_id', $order->id)
                ->orderBy('id')->get()->map(static fn (object $r): array => (array) $r)->all(),
            'Order lines must not be mutated.',
        );

        foreach ([
            'vehicle_plans', 'vehicle_plan_slots', 'vehicle_plan_slot_orders',
            'vehicle_plan_adjustment_log', 'loading_sessions', 'vehicle_assignments',
            'allocation_records', 'vehicle_inventory_items',
            'stock_ledger_entries', 'goods_receipts', 'purchase_orders',
        ] as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            self::assertSame(0, DB::table($table)->count(), "{$table} must remain untouched.");
        }
    }

    public function test_a_group_carries_no_vehicle_and_no_driver(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);
        $this->createGroup($this->companyA, 'DG-001', [$this->zoneMaadi]);

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('distribution_virtual_slots');

        // The absence is the contract: a group cannot acquire a vehicle by
        // accident, because there is nowhere to put one.
        self::assertNotContains('vehicle_id', $columns);
        self::assertNotContains('driver_id', $columns);
    }
}
