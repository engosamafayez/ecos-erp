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
 * TASK-SHIPPING-DISTRIBUTION-WORKSPACE-API-READ-MODEL-001 — PART 7 + PART 15.
 *
 * HTTP coverage for the extended read model:
 *   GET /windows/{window}/late-orders   (new)
 *   GET /windows/{window}/orders        (extended filters + fields)
 *
 * Every filter is asserted server-side. Nothing here re-derives lateness,
 * warehouse or zone in the test — those come from the API, which is the point.
 */
class DistributionReadModelApiTest extends TestCase
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
            'code' => 'RM-'.substr(uniqid(), -5),
            'name_ar' => 'Zone', 'name_en' => 'Zone',
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
            'order_number' => 'ORD-RM-'.uniqid(),
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

    /** Force an order to look like it arrived after the window's cutoff. */
    private function markReceivedAfterCutoff(Order $order, DistributionWindow $window): void
    {
        $cutoff = $window->cutoff_reached_at ?? $window->closes_at;

        DB::table('orders')->where('id', $order->id)
            ->update(['created_at' => $cutoff->copy()->addMinute()]);
    }

    /**
     * Set payment_status directly.
     *
     * `orders.payment_status` is NOT in Order::$fillable and no production writer
     * populates it — OrderController only READS it as a filter. That is a
     * pre-existing Orders-domain gap (reported, not fixed here): the read model
     * correctly surfaces the real column, so the column must be seeded directly
     * to exercise the filter.
     */
    private function setPaymentStatus(Order $order, string $status): void
    {
        DB::table('orders')->where('id', $order->id)->update(['payment_status' => $status]);
    }

    // ── LATE ORDERS — auth, permission, tenant ───────────────────────────────

    public function test_late_orders_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/windows/'.$this->window($this->companyA)->id.'/late-orders')
            ->assertStatus(401);
    }

    public function test_late_orders_denied_for_unprivileged_user(): void
    {
        $window = $this->window($this->companyA);

        $this->actingAsUnprivileged($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$window->id}/late-orders")
            ->assertForbidden();
    }

    public function test_late_orders_allowed_for_same_company(): void
    {
        $window = $this->window($this->companyA);

        $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$window->id}/late-orders")
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_late_orders_denied_across_companies(): void
    {
        $windowA = $this->window($this->companyA);

        $this->actingAs($this->userFor($this->companyB))
            ->getJson(self::BASE."/windows/{$windowA->id}/late-orders")
            ->assertStatus(404);
    }

    // ── LATE ORDERS — set semantics ──────────────────────────────────────────

    public function test_late_orders_is_empty_when_nothing_arrived_after_cutoff(): void
    {
        $this->order($this->companyA);
        $window = $this->window($this->companyA);

        $rows = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$window->id}/late-orders")
            ->assertOk()
            ->json('data');

        self::assertSame([], $rows, 'An on-time order must not appear as late.');
    }

    public function test_late_orders_returns_an_order_received_after_cutoff(): void
    {
        $window = $this->window($this->companyA);
        $order = $this->order($this->companyA, ['assigned_warehouse_id' => $this->warehouse->id]);
        $this->markReceivedAfterCutoff($order, $window);

        $rows = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$window->id}/late-orders")
            ->assertOk()
            ->json('data');

        self::assertCount(1, $rows);
        self::assertSame($order->order_number, $rows[0]['order_number']);
    }

    public function test_late_orders_excludes_orders_already_assigned_to_the_window(): void
    {
        $window = $this->window($this->companyA);
        $order = $this->order($this->companyA);
        $this->markReceivedAfterCutoff($order, $window);

        // Once collected/assigned it is no longer awaiting triage.
        $this->collect($this->companyA);

        $rows = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$window->id}/late-orders")
            ->assertOk()
            ->json('data');

        self::assertSame([], $rows, 'An assigned order must not remain in the late list.');
    }

    public function test_late_orders_never_leak_across_companies(): void
    {
        $windowA = $this->window($this->companyA);
        $windowB = $this->window($this->companyB);

        $orderA = $this->order($this->companyA);
        $this->markReceivedAfterCutoff($orderA, $windowA);

        $rows = $this->actingAs($this->userFor($this->companyB))
            ->getJson(self::BASE."/windows/{$windowB->id}/late-orders")
            ->assertOk()
            ->json('data');

        self::assertSame([], $rows, "Company B must not see Company A's late orders.");
    }

    public function test_late_order_row_carries_every_field_the_workspace_needs(): void
    {
        $window = $this->window($this->companyA);
        $order = $this->order($this->companyA, ['assigned_warehouse_id' => $this->warehouse->id]);
        $this->setPaymentStatus($order, 'unpaid');
        $this->markReceivedAfterCutoff($order, $window);

        $row = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$window->id}/late-orders")
            ->assertOk()
            ->json('data.0');

        foreach ([
            'order_id', 'order_number', 'customer_name', 'phone',
            'warehouse_id', 'warehouse_name', 'governorate_id', 'governorate_name',
            'zone_id', 'zone_name', 'order_status', 'payment_status',
            'received_at', 'cutoff_at', 'late_reason', 'assignment_state',
            'current_window_eligible',
        ] as $key) {
            self::assertArrayHasKey($key, $row, "Missing read-model field: {$key}");
        }

        // Values are read from the domain, never invented.
        self::assertSame($this->warehouse->id, $row['warehouse_id']);
        self::assertSame($this->governorate, $row['governorate_id']);
        self::assertSame($this->zone, $row['zone_id']);
        self::assertSame('unpaid', $row['payment_status']);
        self::assertSame('received_after_cutoff', $row['late_reason']);
        self::assertSame('unassigned', $row['assignment_state']);
    }

    // ── ORDERS — extended read model ─────────────────────────────────────────

    public function test_orders_row_carries_the_new_read_model_fields(): void
    {
        $order = $this->order($this->companyA, ['assigned_warehouse_id' => $this->warehouse->id]);
        $this->setPaymentStatus($order, 'paid');
        $this->collect($this->companyA);
        $window = $this->window($this->companyA);

        $row = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$window->id}/orders")
            ->assertOk()
            ->json('data.0');

        foreach ([
            'warehouse_id', 'warehouse_name', 'governorate_id', 'governorate_name',
            'payment_status', 'distribution_status', 'is_late', 'received_at',
        ] as $key) {
            self::assertArrayHasKey($key, $row, "Missing read-model field: {$key}");
        }

        // payment_method retained alongside payment_status — no breaking change.
        self::assertArrayHasKey('payment_method', $row);
        self::assertSame($this->warehouse->id, $row['warehouse_id']);
        self::assertSame('paid', $row['payment_status']);
        self::assertFalse($row['is_late']);
    }

    // ── ORDERS — each filter, server-side ────────────────────────────────────

    public function test_each_filter_narrows_server_side(): void
    {
        $order = $this->order($this->companyA, ['assigned_warehouse_id' => $this->warehouse->id]);
        $this->setPaymentStatus($order, 'paid');
        $this->collect($this->companyA);

        $window = $this->window($this->companyA);
        $user = $this->userFor($this->companyA);
        $base = self::BASE."/windows/{$window->id}/orders";

        // Matching values return the row.
        foreach ([
            "?zone_id={$this->zone}",
            "?governorate_id={$this->governorate}",
            '?warehouse_id='.$this->warehouse->id,
            '?order_status=new',
            '?payment_status=paid',
            '?distribution_status=auto',
            '?late=0',
        ] as $query) {
            $rows = $this->actingAs($user)->getJson($base.$query)->assertOk()->json('data');
            self::assertCount(1, $rows, "Filter should have matched: {$query}");
        }

        // Non-matching values exclude it.
        foreach ([
            '?governorate_id=999999',
            '?order_status=delivered',
            '?payment_status=refunded',
            '?distribution_status=manual_late',
            '?late=1',
        ] as $query) {
            $rows = $this->actingAs($user)->getJson($base.$query)->assertOk()->json('data');
            self::assertSame([], $rows, "Filter should have excluded: {$query}");
        }
    }

    public function test_filters_compose_in_a_single_query(): void
    {
        $order = $this->order($this->companyA, ['assigned_warehouse_id' => $this->warehouse->id]);
        $this->setPaymentStatus($order, 'paid');
        $this->collect($this->companyA);

        $window = $this->window($this->companyA);
        $user = $this->userFor($this->companyA);
        $base = self::BASE."/windows/{$window->id}/orders";

        // All true together → match.
        $rows = $this->actingAs($user)->getJson(
            $base."?governorate_id={$this->governorate}&zone_id={$this->zone}"
            .'&warehouse_id='.$this->warehouse->id
            .'&order_status=new&payment_status=paid&distribution_status=auto&late=0',
        )->assertOk()->json('data');
        self::assertCount(1, $rows);

        // One condition flipped → the composition excludes it.
        $rows = $this->actingAs($user)->getJson(
            $base."?governorate_id={$this->governorate}&zone_id={$this->zone}"
            .'&warehouse_id='.$this->warehouse->id
            .'&order_status=new&payment_status=paid&distribution_status=auto&late=1',
        )->assertOk()->json('data');
        self::assertSame([], $rows, 'Filters must AND together, not OR.');
    }

    public function test_orders_endpoint_rejects_invalid_filter_values(): void
    {
        $window = $this->window($this->companyA);

        $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$window->id}/orders?zone_id=not-an-int")
            ->assertStatus(422);
    }
}
