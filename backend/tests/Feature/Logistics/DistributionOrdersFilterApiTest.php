<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Services\DistributionCollectionService;
use Modules\Logistics\Distribution\Domain\Services\DistributionWindowService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-SHIPPING-DISTRIBUTION-API-COMPLETION-002 — PART 9.
 *
 * HTTP coverage for the four capabilities added in this task:
 *   payment_method filter · start_date/end_date range · zone_name · sorting
 *
 * Pagination is deliberately absent — the response remains an unwrapped array,
 * and every assertion here reads `data` as a list to prove that contract held.
 */
class DistributionOrdersFilterApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $companyA;

    private Company $companyB;

    private Customer $customer;

    private int $zone;

    private int $city;

    private int $governorate;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->companyA->id]);

        $this->governorate = DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'محافظة', 'name_en' => 'Governorate',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zone = (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'FL-'.substr(uniqid(), -5),
            'name_ar' => 'Zone AR', 'name_en' => 'Zone EN '.substr(uniqid(), -4),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->city = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $this->governorate,
            'name_ar' => 'City', 'name_en' => 'City',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $this->city)
            ->update(['distribution_zone_id' => $this->zone]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function userFor(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    /** @param array<string,mixed> $extra */
    private function order(Company $company, array $extra = []): Order
    {
        return Order::query()->create(array_merge([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-FL-'.uniqid(),
            'order_date' => now()->toDateString(),
            'logistics_city_id' => $this->city,
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ], $extra));
    }

    private function window(Company $company): DistributionWindow
    {
        $now = CarbonImmutable::now();

        return app(DistributionWindowService::class)->windowFor($company->id, $now->toDateString(), $now);
    }

    private function collect(Company $company): void
    {
        app(DistributionCollectionService::class)->collectForCompany($company->id, CarbonImmutable::now());
    }

    private function receivedDay(Order $order): string
    {
        return CarbonImmutable::parse(
            (string) DB::table('orders')->where('id', $order->id)->value('created_at'),
        )->toDateString();
    }

    private function base(Company $company): string
    {
        return self::BASE.'/windows/'.$this->window($company)->id.'/orders';
    }

    // ── A — payment_method ───────────────────────────────────────────────────

    public function test_payment_method_matches_excludes_and_is_optional(): void
    {
        $this->order($this->companyA, ['payment_method' => 'cod']);
        $this->collect($this->companyA);

        $user = $this->userFor($this->companyA);
        $base = $this->base($this->companyA);

        $rows = $this->actingAs($user)->getJson($base.'?payment_method=cod')->assertOk()->json('data');
        self::assertCount(1, $rows);
        self::assertSame('cod', $rows[0]['payment_method']);

        self::assertSame([], $this->actingAs($user)->getJson($base.'?payment_method=bank_transfer')->assertOk()->json('data'));

        // Omitted → unchanged behaviour.
        self::assertCount(1, $this->actingAs($user)->getJson($base)->assertOk()->json('data'));
    }

    // ── B/C/D — received_at range ────────────────────────────────────────────

    public function test_date_range_is_inclusive_at_both_boundaries(): void
    {
        $order = $this->order($this->companyA);
        $this->collect($this->companyA);

        $day = $this->receivedDay($order);
        $before = CarbonImmutable::parse($day)->subDay()->toDateString();
        $after = CarbonImmutable::parse($day)->addDay()->toDateString();

        $user = $this->userFor($this->companyA);
        $base = $this->base($this->companyA);

        self::assertCount(1, $this->actingAs($user)->getJson($base."?start_date={$day}")->assertOk()->json('data'));
        self::assertCount(1, $this->actingAs($user)->getJson($base."?end_date={$day}")->assertOk()->json('data'));
        self::assertCount(1, $this->actingAs($user)->getJson($base."?start_date={$day}&end_date={$day}")->assertOk()->json('data'));

        self::assertSame([], $this->actingAs($user)->getJson($base."?end_date={$before}")->assertOk()->json('data'));
        self::assertSame([], $this->actingAs($user)->getJson($base."?start_date={$after}")->assertOk()->json('data'));
    }

    public function test_invalid_dates_and_reversed_range_are_rejected(): void
    {
        $user = $this->userFor($this->companyA);
        $base = $this->base($this->companyA);

        $this->actingAs($user)->getJson($base.'?start_date=not-a-date')->assertStatus(422);
        $this->actingAs($user)->getJson($base.'?start_date=2026-08-10&end_date=2026-08-01')->assertStatus(422);
    }

    // ── E — zone_name ────────────────────────────────────────────────────────

    public function test_zone_name_is_returned_and_agrees_with_zone_id(): void
    {
        $this->order($this->companyA);
        $this->collect($this->companyA);

        $row = $this->actingAs($this->userFor($this->companyA))
            ->getJson($this->base($this->companyA))
            ->assertOk()
            ->json('data.0');

        self::assertArrayHasKey('zone_name', $row);
        self::assertSame($this->zone, $row['zone_id']);

        // The name must belong to the zone the ASSIGNMENT points at, so a row can
        // never carry a zone_name that contradicts its own zone_id.
        $expected = DB::table('distribution_zones')->where('id', $this->zone)->value('name_en');
        self::assertSame($expected, $row['zone_name']);
    }

    // ── Sorting ──────────────────────────────────────────────────────────────

    public function test_sorting_respects_whitelist_direction_and_safe_fallback(): void
    {
        $this->order($this->companyA, ['order_number' => 'ORD-FL-AAA']);
        $this->order($this->companyA, ['order_number' => 'ORD-FL-ZZZ']);
        $this->collect($this->companyA);

        $user = $this->userFor($this->companyA);
        $base = $this->base($this->companyA);

        $asc = $this->actingAs($user)->getJson($base.'?sort_by=order_number&sort_dir=asc')->assertOk()->json('data');
        $desc = $this->actingAs($user)->getJson($base.'?sort_by=order_number&sort_dir=desc')->assertOk()->json('data');

        self::assertSame('ORD-FL-AAA', $asc[0]['order_number']);
        self::assertSame('ORD-FL-ZZZ', $desc[0]['order_number']);

        // An unrecognised sort field must fall back safely — never reach orderBy raw.
        $fallback = $this->actingAs($user)
            ->getJson($base.'?sort_by='.urlencode('o.total; DROP TABLE orders'))
            ->assertOk()
            ->json('data');
        self::assertCount(2, $fallback);
        self::assertSame('ORD-FL-AAA', $fallback[0]['order_number']);

        // Invalid direction is rejected by validation.
        $this->actingAs($user)->getJson($base.'?sort_dir=sideways')->assertStatus(422);
    }

    // ── F — composition ──────────────────────────────────────────────────────

    public function test_new_filters_compose_with_existing_ones_using_and(): void
    {
        $order = $this->order($this->companyA, [
            'assigned_warehouse_id' => $this->warehouse->id,
            'payment_method' => 'cod',
        ]);
        $this->collect($this->companyA);

        $day = $this->receivedDay($order);
        $user = $this->userFor($this->companyA);
        $base = $this->base($this->companyA);

        $all = "?warehouse_id={$this->warehouse->id}&governorate_id={$this->governorate}"
            ."&zone_id={$this->zone}&payment_method=cod&order_status=new"
            ."&distribution_status=auto&late=0&start_date={$day}&end_date={$day}";

        self::assertCount(1, $this->actingAs($user)->getJson($base.$all)->assertOk()->json('data'));

        // Flip exactly one condition — AND semantics must exclude the row.
        self::assertSame([], $this->actingAs($user)->getJson(
            $base.str_replace('payment_method=cod', 'payment_method=bank_transfer', $all),
        )->assertOk()->json('data'));
    }

    // ── G — authorization / tenancy with the new filters attached ────────────

    public function test_new_filters_never_bypass_tenant_or_permission_guards(): void
    {
        $this->order($this->companyA, ['payment_method' => 'cod']);
        $this->collect($this->companyA);
        $baseA = $this->base($this->companyA);

        $this->getJson($baseA.'?payment_method=cod')->assertStatus(401);

        $this->actingAsUnprivileged($this->userFor($this->companyA))
            ->getJson($baseA.'?payment_method=cod')
            ->assertForbidden();

        $this->actingAs($this->userFor($this->companyB))
            ->getJson($baseA.'?payment_method=cod')
            ->assertStatus(404);
    }

    // ── PART 10 — the response contract must NOT have gained pagination ──────

    public function test_response_remains_an_unwrapped_array(): void
    {
        $this->order($this->companyA);
        $this->collect($this->companyA);

        $json = $this->actingAs($this->userFor($this->companyA))
            ->getJson($this->base($this->companyA))
            ->assertOk()
            ->json();

        self::assertArrayHasKey('data', $json);
        self::assertIsList($json['data']);
        self::assertArrayNotHasKey('meta', $json, 'Pagination is deferred to its own coordinated task.');
    }
}
