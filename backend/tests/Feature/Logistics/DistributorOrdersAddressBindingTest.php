<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Geography\Domain\Services\OrderCityBinder;
use Modules\Logistics\Geography\Domain\Services\OrderCityResolver;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-1-2-3-001 — Parts 1, 2 and 3.
 *
 * The workflow under test:
 *   eligible orders (ADR-042) -> address binding -> zone assignment -> grouping
 *
 * Everything goes through the real router, middleware and database. The single
 * `POST /windows/collect` endpoint drives all three parts, so most tests assert
 * against its effects rather than against an internal call.
 */
class DistributorOrdersAddressBindingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $companyA;

    private Company $companyB;

    private Customer $customer;

    private int $governorate;

    private int $zoneMaadi;

    private int $zoneNasr;

    private int $cityMaadi;

    private int $cityNasr;

    /** A city deliberately left OUT of any zone. */
    private int $cityUnzoned;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة',
            'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi Zone');
        $this->zoneNasr = $this->zone('Nasr Zone');

        $this->cityMaadi = $this->city('Maadi', 'المعادي', $this->zoneMaadi);
        $this->cityNasr = $this->city('Nasr City', 'مدينة نصر', $this->zoneNasr);
        $this->cityUnzoned = $this->city('Helwan', 'حلوان', null);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'PZ-'.substr(uniqid(), -6),
            'name_ar' => $name,
            'name_en' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(string $en, string $ar, ?int $zoneId): int
    {
        $id = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $this->governorate,
            'name_ar' => $ar,
            'name_en' => $en,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('logistics_cities')->where('id', $id)->update(['distribution_zone_id' => $zoneId]);

        return $id;
    }

    /**
     * An Order the way the platform really creates them: free-text address, and
     * `logistics_city_id` NULL. That NULL is the whole point — it is the state
     * 100% of production orders were in when the audit ran.
     */
    private function order(
        Company $company,
        ?string $city = 'Maadi',
        string $status = 'in_progress',
        ?string $governorate = 'Cairo',
        float $total = 100.0,
        float $deposit = 0.0,
    ): Order {
        return Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-PZ-'.uniqid(),
            'order_date' => now()->toDateString(),
            'city' => $city,
            'governorate' => $governorate,
            'status' => $status,
            'subtotal' => $total, 'total' => $total,
            'deposit_amount' => $deposit,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    /** A real Product row — `order_lines.product_id` carries an FK to `products`. */
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

    private function userFor(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    /** Drive the real endpoint: bind -> collect -> reconcile. */
    private function refresh(Company $company): array
    {
        return $this->actingAs($this->userFor($company))
            ->postJson(self::BASE.'/windows/collect')
            ->assertOk()
            ->json('data');
    }

    private function poolFor(Company $company): array
    {
        $user = $this->userFor($company);

        $this->ensureTodayWindow($company->id);

        $windowId = $this->actingAs($user)
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()
            ->json('data.window.id');

        return $this->actingAs($user)
            ->getJson(self::BASE.'/windows/'.$windowId.'/orders')
            ->assertOk()
            ->json('data');
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

    private function zonesFor(Company $company): array
    {
        $this->ensureTodayWindow($company->id);

        return $this->actingAs($this->userFor($company))
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()
            ->json('data.zones');
    }

    // ── 1–4. Eligibility is ADR-042, and only ADR-042 ────────────────────────

    public function test_eligible_statuses_come_from_the_adr042_contract(): void
    {
        $this->assertSame(
            ['in_progress', 'confirmed'],
            array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::fulfilmentEligible()),
        );

        // The Distribution config must not restate the list — it must derive it.
        $this->assertSame(
            array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::fulfilmentEligible()),
            (array) config('distribution.eligible_order_statuses'),
        );
    }

    public function test_in_progress_orders_are_collected(): void
    {
        $this->order($this->companyA, 'Maadi', 'in_progress');

        $this->assertSame(1, $this->refresh($this->companyA)['collected']);
    }

    public function test_confirmed_orders_are_collected(): void
    {
        $this->order($this->companyA, 'Maadi', 'confirmed');

        $this->assertSame(1, $this->refresh($this->companyA)['collected']);
    }

    public function test_non_eligible_statuses_are_never_collected(): void
    {
        foreach (['cancelled', 'delivered', 'returned', 'awaiting_payment', 'awaiting_stock'] as $status) {
            $this->order($this->companyA, 'Maadi', $status);
        }

        $result = $this->refresh($this->companyA);

        $this->assertSame(0, $result['collected']);
        $this->assertSame(0, $result['cities_bound'], 'Binding must respect the same eligibility list.');
        $this->assertEmpty($this->poolFor($this->companyA));
    }

    // ── 5–8. Address binding ─────────────────────────────────────────────────

    public function test_city_is_resolved_and_persisted_from_the_order_address(): void
    {
        $order = $this->order($this->companyA, 'Maadi');
        $this->assertNull($order->logistics_city_id, 'Precondition: orders start unbound.');

        $result = $this->refresh($this->companyA);

        $this->assertSame(1, $result['cities_bound']);
        $this->assertSame(0, $result['cities_unresolved']);
        $this->assertSame(
            $this->cityMaadi,
            (int) DB::table('orders')->where('id', $order->id)->value('logistics_city_id'),
        );
    }

    public function test_city_resolves_from_the_arabic_name_as_well(): void
    {
        $order = $this->order($this->companyA, 'المعادي');

        $this->refresh($this->companyA);

        $this->assertSame(
            $this->cityMaadi,
            (int) DB::table('orders')->where('id', $order->id)->value('logistics_city_id'),
        );
    }

    public function test_city_resolves_through_the_alias_table(): void
    {
        DB::table('logistics_city_aliases')->insert([
            'city_id' => $this->cityNasr,
            'provider' => 'woocommerce',
            'alias' => 'Madinet Nasr',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->order($this->companyA, 'Madinet Nasr');

        $this->refresh($this->companyA);

        $this->assertSame(
            $this->cityNasr,
            (int) DB::table('orders')->where('id', $order->id)->value('logistics_city_id'),
        );
    }

    public function test_unresolvable_address_stays_unassigned_with_a_reason(): void
    {
        $noMatch = $this->order($this->companyA, 'Atlantis');
        $noCity = $this->order($this->companyA, null);

        $result = $this->refresh($this->companyA);

        $this->assertSame(0, $result['cities_bound']);
        $this->assertSame(2, $result['cities_unresolved']);
        $this->assertSame(
            ['city_not_resolved' => 1, 'address_incomplete' => 1],
            $result['city_failure_reasons'],
        );

        // The city is NOT guessed.
        $this->assertNull(DB::table('orders')->where('id', $noMatch->id)->value('logistics_city_id'));
        $this->assertNull(DB::table('orders')->where('id', $noCity->id)->value('logistics_city_id'));

        // And the orders are still VISIBLE, each stating why.
        $reasons = collect($this->poolFor($this->companyA))->pluck('unassigned_reason', 'order_number');
        $this->assertCount(2, $reasons);
        $this->assertSame('city_not_resolved', $reasons[$noMatch->order_number]);
        $this->assertSame('address_incomplete', $reasons[$noCity->order_number]);
    }

    public function test_an_ambiguous_city_name_is_never_guessed(): void
    {
        // A second governorate carrying a city of the same name.
        $otherGov = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'الجيزة', 'name_en' => 'Giza',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->insert([
            'governorate_id' => $otherGov,
            'name_ar' => 'المعادي', 'name_en' => 'Maadi',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // No governorate on the order, so the tie cannot be broken.
        $order = $this->order($this->companyA, 'Maadi', 'in_progress', null);

        $result = $this->refresh($this->companyA);

        $this->assertSame(0, $result['cities_bound']);
        $this->assertSame(['city_ambiguous' => 1], $result['city_failure_reasons']);
        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('logistics_city_id'));
    }

    public function test_governorate_breaks_an_ambiguous_city_tie(): void
    {
        $otherGov = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'الجيزة', 'name_en' => 'Giza',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->insert([
            'governorate_id' => $otherGov,
            'name_ar' => 'المعادي', 'name_en' => 'Maadi',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->order($this->companyA, 'Maadi', 'in_progress', 'Cairo');

        $this->refresh($this->companyA);

        $this->assertSame(
            $this->cityMaadi,
            (int) DB::table('orders')->where('id', $order->id)->value('logistics_city_id'),
        );
    }

    public function test_binding_is_idempotent_and_never_rewrites_a_bound_city(): void
    {
        $order = $this->order($this->companyA, 'Maadi');

        $first = $this->refresh($this->companyA);
        $this->assertSame(1, $first['cities_bound']);

        // An operator corrects the city by hand to a DIFFERENT one.
        DB::table('orders')->where('id', $order->id)->update(['logistics_city_id' => $this->cityNasr]);

        $second = $this->refresh($this->companyA);

        $this->assertSame(0, $second['cities_bound'], 'A second run must bind nothing.');
        $this->assertSame(0, $second['collected'], 'And must not re-collect.');
        $this->assertSame(
            $this->cityNasr,
            (int) DB::table('orders')->where('id', $order->id)->value('logistics_city_id'),
            'A manual correction must survive the next sweep.',
        );
    }

    // ── 9–11. Zone assignment ────────────────────────────────────────────────

    public function test_zone_is_resolved_from_the_bound_city(): void
    {
        $maadi = $this->order($this->companyA, 'Maadi');
        $nasr = $this->order($this->companyA, 'Nasr City');

        $this->refresh($this->companyA);

        $pool = collect($this->poolFor($this->companyA))->keyBy('order_number');

        $this->assertSame($this->zoneMaadi, $pool[$maadi->order_number]['zone_id']);
        $this->assertSame('Maadi', $pool[$maadi->order_number]['city_name']);
        $this->assertNull($pool[$maadi->order_number]['unassigned_reason']);

        $this->assertSame($this->zoneNasr, $pool[$nasr->order_number]['zone_id']);
        $this->assertSame('Nasr City', $pool[$nasr->order_number]['city_name']);
    }

    public function test_a_city_with_no_zone_configured_stays_unassigned_with_that_reason(): void
    {
        $order = $this->order($this->companyA, 'Helwan');

        $result = $this->refresh($this->companyA);

        // The CITY resolved — only the zone mapping is missing. The two failures
        // are different problems and must not be reported as the same one.
        $this->assertSame(1, $result['cities_bound']);
        $this->assertSame(
            $this->cityUnzoned,
            (int) DB::table('orders')->where('id', $order->id)->value('logistics_city_id'),
        );

        $row = collect($this->poolFor($this->companyA))->firstWhere('order_number', $order->order_number);
        $this->assertNull($row['zone_id']);
        $this->assertSame('zone_not_configured', $row['unassigned_reason']);
    }

    public function test_an_order_collected_before_binding_is_rezoned_by_the_next_sweep(): void
    {
        // Collect while the city is unknown: the assignment is pinned to zone NULL
        // and can never be re-collected, because it already has an assignment.
        $order = $this->order($this->companyA, 'Atlantis');
        $this->refresh($this->companyA);

        $before = collect($this->poolFor($this->companyA))->firstWhere('order_number', $order->order_number);
        $this->assertNull($before['zone_id']);
        $this->assertSame('city_not_resolved', $before['unassigned_reason']);

        // The address is corrected to something resolvable.
        DB::table('orders')->where('id', $order->id)->update(['city' => 'Maadi']);

        $result = $this->refresh($this->companyA);

        $this->assertSame(1, $result['cities_bound']);
        $this->assertSame(0, $result['collected'], 'It is already in the window.');
        $this->assertSame(1, $result['rezoned'], 'Reconciliation must repair it.');

        $after = collect($this->poolFor($this->companyA))->firstWhere('order_number', $order->order_number);
        $this->assertSame($this->zoneMaadi, $after['zone_id']);
        $this->assertNull($after['unassigned_reason']);
        $this->assertSame('auto', $after['assignment_source'], 'Re-zoning is not a manual move.');
    }

    // ── 12–13. Grouping counts each order exactly once ───────────────────────

    public function test_each_order_appears_exactly_once_in_the_pool(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);

        $pool = $this->poolFor($this->companyA);

        $this->assertCount(2, $pool);
        $this->assertCount(2, collect($pool)->pluck('order_id')->unique());
    }

    public function test_a_multi_product_order_appears_once_and_reports_its_product_count(): void
    {
        $order = $this->order($this->companyA, 'Maadi');
        $this->line($order, 3);
        $this->line($order, 5);

        $this->refresh($this->companyA);

        $pool = $this->poolFor($this->companyA);

        // The failure this guards against is a JOIN to order_lines, which would
        // emit one row per line and double the order in every count.
        $this->assertCount(1, $pool);
        $this->assertSame(2, $pool[0]['products_count']);
        $this->assertSame(8.0, (float) $pool[0]['total_quantity']);

        $zone = collect($this->zonesFor($this->companyA))->firstWhere('zone_id', $this->zoneMaadi);
        $this->assertSame(1, $zone['order_count'], 'Zone order count must not be inflated by lines.');
        $this->assertSame(2, $zone['products_count']);
        $this->assertSame(100.0, (float) $zone['total_value'], 'Nor may the value be multiplied.');
    }

    public function test_zone_grouping_reports_paid_and_unpaid_counts(): void
    {
        $this->order($this->companyA, 'Maadi', 'in_progress', 'Cairo', 100.0, 100.0); // paid
        $this->order($this->companyA, 'Maadi', 'in_progress', 'Cairo', 100.0, 40.0);  // partial
        $this->order($this->companyA, 'Maadi', 'in_progress', 'Cairo', 100.0, 0.0);   // unpaid

        $this->refresh($this->companyA);

        $zone = collect($this->zonesFor($this->companyA))->firstWhere('zone_id', $this->zoneMaadi);

        $this->assertSame(3, $zone['order_count']);
        $this->assertSame(1, $zone['paid_orders'], 'Only a fully-covered order is paid.');
        $this->assertSame(2, $zone['unpaid_orders']);

        $states = collect($this->poolFor($this->companyA))->pluck('payment_state')->sort()->values()->all();
        $this->assertSame(['paid', 'partially_paid', 'unpaid'], $states);
    }

    // ── 14. Tenant isolation ─────────────────────────────────────────────────

    public function test_a_company_never_sees_another_companys_orders(): void
    {
        $mine = $this->order($this->companyA, 'Maadi');
        $theirs = $this->order($this->companyB, 'Maadi');

        $this->refresh($this->companyA);
        $this->refresh($this->companyB);

        $poolA = collect($this->poolFor($this->companyA))->pluck('order_number');
        $poolB = collect($this->poolFor($this->companyB))->pluck('order_number');

        $this->assertContains($mine->order_number, $poolA);
        $this->assertNotContains($theirs->order_number, $poolA);

        $this->assertContains($theirs->order_number, $poolB);
        $this->assertNotContains($mine->order_number, $poolB);
    }

    public function test_binding_only_touches_the_acting_companys_orders(): void
    {
        $theirs = $this->order($this->companyB, 'Maadi');

        $this->refresh($this->companyA);

        $this->assertNull(
            DB::table('orders')->where('id', $theirs->id)->value('logistics_city_id'),
            'Binding must be company-scoped.',
        );
    }

    public function test_an_actor_without_a_company_is_refused(): void
    {
        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)->postJson(self::BASE.'/windows/collect')->assertStatus(403);
        $this->actingAs($user)->getJson(self::BASE.'/windows/current')->assertStatus(403);
    }

    // ── 15–16. Blast radius ──────────────────────────────────────────────────

    public function test_the_sweep_touches_no_vehicle_or_loading_table(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->refresh($this->companyA);

        foreach ([
            'vehicle_plans', 'vehicle_plan_slots', 'vehicle_plan_slot_orders',
            'vehicle_plan_adjustment_log', 'loading_sessions', 'vehicle_assignments',
            'allocation_records', 'vehicle_inventory_items',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must remain untouched.");
        }
    }

    public function test_binding_changes_only_the_city_column_on_an_order(): void
    {
        $order = $this->order($this->companyA, 'Maadi');

        $before = (array) DB::table('orders')->where('id', $order->id)->first();

        $this->refresh($this->companyA);

        $after = (array) DB::table('orders')->where('id', $order->id)->first();

        unset($before['logistics_city_id'], $after['logistics_city_id']);

        // `updated_at` is included in this comparison on purpose: binding is
        // bookkeeping, not an edit, and it is surfaced to operators as "Last
        // updated". Bumping it would misreport who last changed the order.
        $this->assertSame($before, $after);
    }

    // ── Resolver unit-level guarantees ───────────────────────────────────────

    public function test_resolver_reports_a_distinct_reason_for_each_failure_mode(): void
    {
        $resolver = app(OrderCityResolver::class);

        $this->assertSame(
            ['city_id' => $this->cityMaadi, 'reason' => null],
            $resolver->resolve('  maadi  ', 'Cairo'),
            'Matching is case- and whitespace-insensitive.',
        );
        $this->assertSame(OrderCityResolver::REASON_ADDRESS_INCOMPLETE, $resolver->resolve(null)['reason']);
        $this->assertSame(OrderCityResolver::REASON_ADDRESS_INCOMPLETE, $resolver->resolve('   ')['reason']);
        $this->assertSame(OrderCityResolver::REASON_CITY_NOT_RESOLVED, $resolver->resolve('Atlantis')['reason']);
    }

    public function test_binder_reports_what_it_examined(): void
    {
        $this->order($this->companyA, 'Maadi');
        $this->order($this->companyA, 'Atlantis');

        $result = app(OrderCityBinder::class)->bindForCompany($this->companyA->id);

        $this->assertSame(2, $result['examined']);
        $this->assertSame(1, $result['bound']);
        $this->assertSame(1, $result['unresolved']);
    }
}
