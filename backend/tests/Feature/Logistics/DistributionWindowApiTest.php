<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DistributionCollectionService;
use Modules\Logistics\Distribution\Domain\Services\DistributionWindowService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-SHIPPING-DISTRIBUTION-WORKSPACE-UI-E2E-001 — PART 4.
 *
 * HTTP feature tests for the Distribution Window/Slot API — the surface the
 * Distribution Workspace consumes. Before this file that surface had ZERO HTTP
 * coverage: DistributionModuleTest exercises other endpoints, and the 23 Core
 * tests call services directly, so authentication, permission and tenant guards
 * on these routes had never been executed.
 *
 * Every test goes through the real router, middleware stack and database.
 */
class DistributionWindowApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $companyA;

    private Company $companyB;

    private Customer $customer;

    private int $zone;

    private int $city;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->customer = Customer::factory()->create();

        $gov = DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'محافظة', 'name_en' => 'Governorate',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zone = (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'API-'.substr(uniqid(), -5),
            'name_ar' => 'Zone', 'name_en' => 'Zone',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->city = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $gov,
            'name_ar' => 'City', 'name_en' => 'City',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $this->city)->update(['distribution_zone_id' => $this->zone]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function userFor(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    private function order(Company $company, string $status = 'in_progress'): Order
    {
        return Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-API-'.uniqid(),
            'order_date' => now()->toDateString(),
            'logistics_city_id' => $this->city,
            'status' => $status,
            'subtotal' => 100, 'total' => 100,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function collect(Company $company): void
    {
        app(DistributionCollectionService::class)
            ->collectForCompany($company->id, CarbonImmutable::now());
    }

    private function windowId(Company $company): string
    {
        $now = CarbonImmutable::now();

        return app(DistributionWindowService::class)
            ->windowFor($company->id, $now->toDateString(), $now)->id;
    }

    // ── Authentication ───────────────────────────────────────────────────────

    public function test_current_window_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/windows/current')->assertStatus(401);
    }

    public function test_late_order_assignment_requires_authentication(): void
    {
        $this->postJson(self::BASE.'/windows/'.$this->windowId($this->companyA).'/late-orders', [
            'order_id' => 'x',
        ])->assertStatus(401);
    }

    // ── Read model ───────────────────────────────────────────────────────────

    public function test_current_window_returns_window_zones_and_slots(): void
    {
        $this->order($this->companyA);
        $this->collect($this->companyA);

        $response = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE.'/windows/current')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'window' => [
                    'id', 'window_date', 'opens_at', 'closes_at', 'status',
                    'status_label', 'accepts_automatic_ingestion',
                    'accepts_manual_assignment',
                ],
                'zones',
                'slots',
            ],
        ]);

        // The payload is UI-ready: the zone summary carries what the board renders.
        $zones = $response->json('data.zones');
        self::assertNotEmpty($zones);
        self::assertArrayHasKey('zone_id', $zones[0]);
        self::assertArrayHasKey('order_count', $zones[0]);
        self::assertArrayHasKey('spans_slots', $zones[0]);
    }

    public function test_window_orders_endpoint_returns_ui_ready_order_rows(): void
    {
        $order = $this->order($this->companyA);
        $this->collect($this->companyA);

        $rows = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE.'/windows/'.$this->windowId($this->companyA).'/orders')
            ->assertOk()
            ->json('data');

        self::assertCount(1, $rows);
        self::assertSame($order->order_number, $rows[0]['order_number']);
        foreach (['assignment_id', 'order_status', 'customer_name', 'phone', 'virtual_slot_id'] as $key) {
            self::assertArrayHasKey($key, $rows[0]);
        }
    }

    public function test_zones_and_slots_endpoints_respond(): void
    {
        $this->order($this->companyA);
        $this->collect($this->companyA);

        $user = $this->userFor($this->companyA);
        $window = $this->windowId($this->companyA);

        $this->actingAs($user)->getJson(self::BASE."/windows/{$window}/zones")->assertOk();
        $this->actingAs($user)->getJson(self::BASE."/windows/{$window}/slots")->assertOk();
        $this->actingAs($user)->getJson(self::BASE."/windows/{$window}/overflows")->assertOk();
    }

    // ── Collection (idempotent) ──────────────────────────────────────────────

    public function test_collect_endpoint_is_idempotent(): void
    {
        $this->order($this->companyA);
        $user = $this->userFor($this->companyA);

        $this->actingAs($user)->postJson(self::BASE.'/windows/collect')->assertOk();
        $first = DistributionWindowOrder::query()->count();

        $this->actingAs($user)->postJson(self::BASE.'/windows/collect')->assertOk();

        self::assertSame($first, DistributionWindowOrder::query()->count(), 'Re-collection must not duplicate assignments.');
    }

    // ── Individual reassignment — persisted, not UI-only ─────────────────────

    public function test_individual_slot_reassignment_persists_and_leaves_zone_and_warehouse_alone(): void
    {
        $order = $this->order($this->companyA);
        $this->collect($this->companyA);

        $window = $this->windowId($this->companyA);
        $assignment = DistributionWindowOrder::query()->where('order_id', $order->id)->firstOrFail();
        $zoneBefore = $assignment->distribution_zone_id;

        $slot = VirtualCapacitySlot::query()->create([
            'company_id' => $this->companyA->id,
            'distribution_window_id' => $window,
            // A Distribution Group is owned by exactly one warehouse (Part 5B);
            // the column is NOT NULL, so a fixture must name the owner.
            'warehouse_id' => $this->slotWarehouseId($this->companyA->id),
            'code' => 'S-'.substr(uniqid(), -5),
            'name' => 'Slot S',
            'capacity_orders' => 50,
        ]);

        $this->actingAs($this->userFor($this->companyA))
            ->patchJson(self::BASE.'/assignments/'.$assignment->id.'/slot', [
                'slot_id' => $slot->id,
                'reason' => 'api test',
            ])
            ->assertOk();

        $fresh = $assignment->fresh();
        self::assertSame($slot->id, $fresh->virtual_slot_id, 'Move must persist in the database.');
        self::assertSame($zoneBefore, $fresh->distribution_zone_id, 'Move must not change the Zone.');
        self::assertNull($order->fresh()->assigned_warehouse_id, 'Distribution must never write the Warehouse.');
    }

    // ── Manual late assignment ───────────────────────────────────────────────

    public function test_manual_late_order_assignment_persists(): void
    {
        $order = $this->order($this->companyA);
        $window = $this->windowId($this->companyA);

        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$window}/late-orders", [
                'order_id' => $order->id,
                'reason' => 'manager pulled it in',
            ])
            ->assertCreated();

        $assignment = DistributionWindowOrder::query()->where('order_id', $order->id)->first();
        self::assertNotNull($assignment, 'Late assignment must create the distribution_window_orders row.');
        self::assertSame($window, $assignment->distribution_window_id);
    }

    public function test_late_order_assignment_validates_input(): void
    {
        $window = $this->windowId($this->companyA);

        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$window}/late-orders", [])
            ->assertStatus(422);
    }

    // ── Tenant boundary ──────────────────────────────────────────────────────

    public function test_company_b_cannot_read_company_a_window(): void
    {
        $this->order($this->companyA);
        $this->collect($this->companyA);
        $windowA = $this->windowId($this->companyA);

        $this->actingAs($this->userFor($this->companyB))
            ->getJson(self::BASE."/windows/{$windowA}/orders")
            ->assertStatus(404);
    }

    public function test_company_b_current_window_never_contains_company_a_orders(): void
    {
        $orderA = $this->order($this->companyA);
        $this->collect($this->companyA);

        $zones = $this->actingAs($this->userFor($this->companyB))
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()
            ->json('data.zones');

        $total = array_sum(array_map(static fn (array $z): int => (int) $z['order_count'], $zones));
        self::assertSame(0, $total, "Company B must not see Company A's orders.");
        self::assertNotNull($orderA->fresh());
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
