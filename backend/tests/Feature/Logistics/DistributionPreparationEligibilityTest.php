<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-5-LIFECYCLE-GROUP-MANAGEMENT-001 — Part 3.
 *
 * Distribution consumes PREPARATION's eligibility answer, it does not compute a
 * second one. An Order carrying an eligible status but postponed out of the
 * current preparation cycle must not appear in active Distribution — and must
 * come back, untouched, when Preparation resumes it.
 *
 * Every write here is to Preparation's OWN columns using Preparation's own
 * semantics (`postponed_at`, `released_at`); no Preparation service, contract or
 * lifecycle is modified.
 */
class DistributionPreparationEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $companyA;

    private Company $companyB;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $zoneMaadi;

    private int $cityMaadi;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->companyA->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'PE-'.substr(uniqid(), -6),
            'name_ar' => 'Maadi', 'name_en' => 'Maadi',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cityMaadi = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorate,
            'name_ar' => 'المعادي', 'name_en' => 'Maadi',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $this->cityMaadi)
            ->update(['distribution_zone_id' => $this->zoneMaadi]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function order(Company $company, string $status = 'in_progress'): Order
    {
        return Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-PE-'.uniqid(),
            'order_date' => now()->toDateString(),
            // Real orders always carry a warehouse; a Group can only hold its own.
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Maadi',
            'governorate' => 'Cairo',
            'status' => $status,
            'subtotal' => 100, 'total' => 100,
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

    private function wave(Company $company): string
    {
        $id = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'PREP-PE-'.substr(uniqid(), -6),
            'planning_date' => now()->toDateString(),
            'starts_at' => now()->copy()->setTime(17, 30),
            'intake_closes_at' => now()->copy()->addDay()->setTime(5, 0),
            'ends_at' => now()->copy()->addDay()->setTime(12, 0),
            'status' => 'collecting',
            'created_at' => now(), 'updated_at' => now(),
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
        ]);

        return $id;
    }

    /**
     * A wave membership, written with PREPARATION's own semantics:
     *   released_at IS NULL   => the membership is ACTIVE
     *   postponed_at NOT NULL => the Order is out of the current cycle
     */
    private function membership(
        Company $company,
        string $waveId,
        Order $order,
        ?string $postponedAt = null,
        ?string $releasedAt = null,
    ): void {
        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'preparation_wave_id' => $waveId,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            // NOT NULL with no default, both of them — the table records who put the
            // Order in the wave and when it was confirmed, and neither is optional.
            'order_confirmed_at' => now(),
            'added_by' => (string) Str::uuid(),
            'added_at' => now(),
            'postponed_at' => $postponedAt,
            'released_at' => $releasedAt,
        ]);
    }

    private function userFor(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    private function refresh(Company $company): array
    {
        return $this->actingAs($this->userFor($company))
            ->postJson(self::BASE.'/windows/collect')
            ->assertOk()->json('data');
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

    private function current(Company $company): array
    {
        $this->ensureTodayWindow($company->id);

        return $this->actingAs($this->userFor($company))
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()->json('data');
    }

    /** @return list<string> order numbers visible in ACTIVE distribution */
    private function pool(Company $company): array
    {
        $this->ensureTodayWindow($company->id);
        $user = $this->userFor($company);
        $windowId = $this->actingAs($user)->getJson(self::BASE.'/windows/current')
            ->assertOk()->json('data.window.id');

        return collect(
            $this->actingAs($user)->getJson(self::BASE."/windows/{$windowId}/orders")
                ->assertOk()->json('data'),
        )->pluck('order_number')->all();
    }

    // ── A. Eligible appears ──────────────────────────────────────────────────

    public function test_a_preparation_eligible_order_appears_in_distribution(): void
    {
        $waveId = $this->wave($this->companyA);
        $order = $this->order($this->companyA);
        $this->membership($this->companyA, $waveId, $order);

        $this->refresh($this->companyA);

        self::assertSame([$order->order_number], $this->pool($this->companyA));
    }

    public function test_an_order_with_no_wave_membership_is_still_eligible(): void
    {
        // Not yet collected into a wave is EARLY, not excluded. Treating "no row"
        // as ineligible would empty the pool every time a wave rolled.
        $order = $this->order($this->companyA);

        $this->refresh($this->companyA);

        self::assertSame([$order->order_number], $this->pool($this->companyA));
    }

    // ── B. Postponed disappears ──────────────────────────────────────────────

    public function test_a_postponed_order_is_not_collected(): void
    {
        $waveId = $this->wave($this->companyA);
        $order = $this->order($this->companyA);
        $this->membership($this->companyA, $waveId, $order, postponedAt: (string) now());

        $result = $this->refresh($this->companyA);

        self::assertSame(0, $result['collected']);
        self::assertSame([], $this->pool($this->companyA));
    }

    public function test_an_already_collected_order_leaves_distribution_when_postponed(): void
    {
        $waveId = $this->wave($this->companyA);
        $kept = $this->order($this->companyA);
        $postponed = $this->order($this->companyA);
        $this->membership($this->companyA, $waveId, $kept);
        $this->membership($this->companyA, $waveId, $postponed);

        $this->refresh($this->companyA);
        self::assertCount(2, $this->pool($this->companyA));

        // Preparation postpones it — Distribution must follow without being told.
        DB::table('preparation_wave_orders')
            ->where('order_id', $postponed->id)
            ->update(['postponed_at' => now()]);

        self::assertSame([$kept->order_number], $this->pool($this->companyA));

        // The assignment row SURVIVES: hiding is reversible, deleting would force a
        // re-collection when Preparation resumes the order.
        self::assertSame(2, DB::table('distribution_window_orders')
            ->where('company_id', $this->companyA->id)->count());
    }

    // ── C/D. Ineligible, then eligible again ─────────────────────────────────

    public function test_an_order_whose_status_is_no_longer_eligible_disappears(): void
    {
        $order = $this->order($this->companyA);
        $this->refresh($this->companyA);
        self::assertCount(1, $this->pool($this->companyA));

        DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled']);

        self::assertSame([], $this->pool($this->companyA));
    }

    public function test_a_resumed_order_returns_to_distribution(): void
    {
        $waveId = $this->wave($this->companyA);
        $order = $this->order($this->companyA);
        $this->membership($this->companyA, $waveId, $order, postponedAt: (string) now());

        $this->refresh($this->companyA);
        self::assertSame([], $this->pool($this->companyA));

        // Preparation's resume path clears postponed_at — never an INSERT.
        DB::table('preparation_wave_orders')
            ->where('order_id', $order->id)
            ->update(['postponed_at' => null]);

        $this->refresh($this->companyA);

        self::assertSame([$order->order_number], $this->pool($this->companyA));
    }

    public function test_a_released_membership_does_not_exclude_an_order(): void
    {
        // Released = history. A postponed-then-released row must not keep excluding
        // the order forever, which is the failure `released_at IS NULL` prevents.
        $waveId = $this->wave($this->companyA);
        $order = $this->order($this->companyA);
        $this->membership(
            $this->companyA, $waveId, $order,
            postponedAt: (string) now(),
            releasedAt: (string) now(),
        );

        $this->refresh($this->companyA);

        self::assertSame([$order->order_number], $this->pool($this->companyA));
    }

    // ── Aggregates agree with the list ───────────────────────────────────────

    public function test_zone_and_group_totals_exclude_postponed_orders(): void
    {
        $waveId = $this->wave($this->companyA);
        $kept = $this->order($this->companyA);
        $this->line($kept, 1);
        $postponed = $this->order($this->companyA);
        $this->line($postponed, 1);
        $this->line($postponed, 2);
        $this->membership($this->companyA, $waveId, $kept);
        $this->membership($this->companyA, $waveId, $postponed);

        $this->refresh($this->companyA);

        $user = $this->userFor($this->companyA);
        $windowId = $this->current($this->companyA)['window']['id'];

        // Put the zone in a Distribution Group so the group rollup is exercised too.
        $group = $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-001',
            ])
            ->assertStatus(201)->json('data');
        $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/zones", [
                'zone_id' => $this->zoneMaadi,
            ])->assertOk();

        DB::table('preparation_wave_orders')
            ->where('order_id', $postponed->id)
            ->update(['postponed_at' => now()]);

        $data = $this->current($this->companyA);

        $zone = collect($data['zones'])->firstWhere('zone_id', $this->zoneMaadi);
        self::assertSame(1, $zone['order_count'], 'The zone must not count a postponed order.');
        self::assertSame(100.0, (float) $zone['total_value']);
        self::assertSame(1, $zone['products_count']);

        $slot = collect($data['slots'])->firstWhere('code', 'DG-001');
        self::assertSame(1, $slot['orders_count'], 'The group must not count a postponed order.');
        self::assertSame(100.0, (float) $slot['total_value']);
        self::assertSame(1, $slot['products_count']);

        // The list and the totals must tell the same story.
        self::assertSame([$kept->order_number], $this->pool($this->companyA));
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────

    public function test_postponement_in_one_company_does_not_affect_another(): void
    {
        $waveA = $this->wave($this->companyA);
        $mine = $this->order($this->companyA);
        $theirs = $this->order($this->companyB);
        $this->membership($this->companyA, $waveA, $mine, postponedAt: (string) now());

        $this->refresh($this->companyA);
        $this->refresh($this->companyB);

        self::assertSame([], $this->pool($this->companyA));
        self::assertSame([$theirs->order_number], $this->pool($this->companyB));
    }

    // ── Blast radius ─────────────────────────────────────────────────────────

    public function test_distribution_never_writes_to_preparation(): void
    {
        $waveId = $this->wave($this->companyA);
        $order = $this->order($this->companyA);
        $this->membership($this->companyA, $waveId, $order);

        $waveBefore = (array) DB::table('preparation_waves')->where('id', $waveId)->first();
        $memberBefore = (array) DB::table('preparation_wave_orders')->where('order_id', $order->id)->first();
        $orderBefore = (array) DB::table('orders')->where('id', $order->id)->first();

        $this->refresh($this->companyA);
        $this->pool($this->companyA);

        self::assertSame($waveBefore, (array) DB::table('preparation_waves')->where('id', $waveId)->first(),
            'The wave must not be mutated.');
        self::assertSame($memberBefore, (array) DB::table('preparation_wave_orders')->where('order_id', $order->id)->first(),
            'Wave membership must not be mutated.');

        // Address binding legitimately sets logistics_city_id; nothing else may move.
        unset($orderBefore['logistics_city_id']);
        $orderAfter = (array) DB::table('orders')->where('id', $order->id)->first();
        unset($orderAfter['logistics_city_id']);
        self::assertSame($orderBefore, $orderAfter, 'Only the city binding may change on an order.');

        foreach ([
            'vehicle_plans', 'vehicle_plan_slots', 'vehicle_plan_slot_orders',
            'vehicle_plan_adjustment_log', 'loading_sessions', 'vehicle_assignments',
            'allocation_records', 'vehicle_inventory_items',
        ] as $table) {
            self::assertSame(0, DB::table($table)->count(), "{$table} must remain untouched.");
        }
    }
}
