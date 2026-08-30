<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-LOADING-GROUP-GRAIN-READ-AND-EXECUTION-UX-002 — the warehouse Loading read.
 *
 * ┌─ WHAT THIS SUITE EXISTS TO PROVE ────────────────────────────────────────┐
 * │ 1. A Group holding eligible orders is readable WITHOUT a Vehicle, Driver,  │
 * │    Trip or Loading Session — the whole point of the group grain.           │
 * │ 2. Warehouse roles can read it WITHOUT `logistics.distribution.view`.      │
 * │ 3. PREPARED IS NEVER REPORTED AS LOADED.                                   │
 * │ 4. Remaining is Required − LOADED, not Required − Prepared.                │
 * │ 5. Reading writes nothing at all.                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * It deliberately does NOT re-test the canonical aggregation itself —
 * DistributionGroupLoadingPreparationTest already proves Required is the canonical
 * live projection, warehouse-scoped and eligibility-filtered. This suite proves the
 * Loading-side adapter exposes it faithfully under a different permission, and that
 * the execution quantities it adds come from the execution tables.
 */
class GroupLoadingWorkspaceReadTest extends TestCase
{
    use RefreshDatabase;

    private const DIST = '/api/logistics/distribution';

    private const LOADING = '/api/loading';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $zone;

    private Product $honey;

    private Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'Cairo', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zone = $this->zone('Maadi');
        $this->city($governorate, 'Maadi', 'Maadi', $this->zone);

        $this->honey = Product::factory()->create();
        $this->coffee = Product::factory()->create();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Visibility without any transport — the contract
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_group_with_eligible_orders_is_listed_without_vehicle_driver_or_trip(): void
    {
        $group = $this->groupWith(['honey' => 10]);

        $body = $this->actingAs($this->userFor())
            ->getJson(self::LOADING.'/groups?warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->json('data');

        self::assertSame('resolved', $body['resolution']);

        $row = collect($body['groups'])->firstWhere('slot_id', $group['id']);
        self::assertNotNull($row, 'the group must be listed with no transport at all');

        // No trip, no vehicle, no driver, no loading assignment — and it is STILL listed.
        self::assertNull($row['transport']['trip']);
        self::assertNull($row['transport']['vehicle']);
        self::assertNull($row['transport']['driver']);
        self::assertFalse($row['transport']['has_loading_assignment']);

        self::assertSame(1, $row['orders_count']);
    }

    public function test_the_manifest_renders_products_with_no_transport(): void
    {
        $group = $this->groupWith(['honey' => 10, 'coffee' => 4]);

        $data = $this->actingAs($this->userFor())
            ->getJson(self::LOADING.'/groups/'.$group['id'])
            ->assertOk()
            ->json('data');

        self::assertNull($data['transport']['trip']);
        self::assertCount(2, $data['products']);

        $honey = $this->productRow($data, $this->honey->id);
        self::assertSame(10.0, $this->num($honey['quantity_required']));

        // Loading has not started, so Loaded is 0 — produced by the ABSENCE of
        // execution rows, not by a fallback rule.
        self::assertSame(0.0, $this->num($honey['quantity_loaded']));
        self::assertNull($honey['loading_status']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Prepared is not Loaded — the defect this suite guards hardest
    // ─────────────────────────────────────────────────────────────────────────

    public function test_prepared_is_never_reported_as_loaded(): void
    {
        $group = $this->groupWith(['honey' => 10]);

        // Fully prepared, nothing loaded.
        $this->setPrepared($group, $this->honey->id, 10);

        $honey = $this->productRow($this->manifest($group), $this->honey->id);

        self::assertSame(10.0, $this->num($honey['quantity_required']));
        self::assertSame(10.0, $this->num($honey['quantity_prepared']));

        // The whole point: a fully prepared product that never went onto a vehicle
        // is NOT loaded. If Loaded ever mirrors Prepared, this fails.
        self::assertSame(0.0, $this->num($honey['quantity_loaded']));
    }

    public function test_remaining_is_required_minus_loaded_not_required_minus_prepared(): void
    {
        $group = $this->groupWith(['honey' => 10]);
        $this->setPrepared($group, $this->honey->id, 10);

        $honey = $this->productRow($this->manifest($group), $this->honey->id);

        // Required − Prepared would be 0. Required − Loaded is 10, and 10 is the
        // number an operator standing at the vehicle needs.
        self::assertSame(10.0, $this->num($honey['quantity_remaining']));
        self::assertNotSame(
            $this->num($honey['quantity_required']) - $this->num($honey['quantity_prepared']),
            $this->num($honey['quantity_remaining']),
            'Remaining must be remaining-to-LOAD, not remaining-to-prepare',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Loaded comes from the execution tables
    // ─────────────────────────────────────────────────────────────────────────

    public function test_loaded_is_read_from_loading_tasks_and_reduces_remaining(): void
    {
        $group = $this->groupWith(['honey' => 10]);
        $this->setPrepared($group, $this->honey->id, 10);

        $this->recordLoad($group['id'], $this->honey->id, planned: 10, loaded: 6);

        $data = $this->manifest($group);
        $honey = $this->productRow($data, $this->honey->id);

        // The brief's own worked example: Required 10, Prepared 10, Loaded 6, Remaining 4.
        self::assertSame(10.0, $this->num($honey['quantity_required']));
        self::assertSame(10.0, $this->num($honey['quantity_prepared']));
        self::assertSame(6.0, $this->num($honey['quantity_loaded']));
        self::assertSame(4.0, $this->num($honey['quantity_remaining']));

        self::assertSame(10.0, $this->num($data['totals']['required']));
        self::assertSame(10.0, $this->num($data['totals']['prepared']));
        self::assertSame(6.0, $this->num($data['totals']['loaded']));
        self::assertSame(4.0, $this->num($data['totals']['remaining']));

        // Transport is now visible by name, from the canonical pairing.
        self::assertNotNull($data['transport']['trip']);
        self::assertTrue($data['transport']['has_loading_assignment']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Permission — the reason this endpoint exists
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_warehouse_role_without_distribution_view_can_read_groups_and_manifest(): void
    {
        $group = $this->groupWith(['honey' => 10]);

        // ONLY operations.preparation.view. No logistics.distribution.view, and no
        // system role — exactly the Warehouse Operator's real position.
        $user = $this->userWithOnly('operations.preparation.view');

        $this->actingAsUnprivileged($user)
            ->getJson(self::LOADING.'/groups?warehouse_id='.$this->warehouse->id)
            ->assertOk();

        $this->actingAsUnprivileged($user)
            ->getJson(self::LOADING.'/groups/'.$group['id'])
            ->assertOk();

        // And the same subject is still refused the Distribution route, proving the
        // adapter widened access to Loading WITHOUT widening Distribution.
        $windowId = $this->windowId();
        $this->actingAsUnprivileged($user)
            ->getJson(self::DIST."/windows/{$windowId}/products?slot_id={$group['id']}")
            ->assertStatus(403);
    }

    public function test_a_user_without_preparation_view_is_refused(): void
    {
        $group = $this->groupWith(['honey' => 10]);
        $user = $this->userWithOnly('sales.orders.view');

        $this->actingAsUnprivileged($user)
            ->getJson(self::LOADING.'/groups?warehouse_id='.$this->warehouse->id)
            ->assertStatus(403);

        $this->actingAsUnprivileged($user)
            ->getJson(self::LOADING.'/groups/'.$group['id'])
            ->assertStatus(403);
    }

    public function test_a_group_of_another_company_is_not_readable(): void
    {
        $group = $this->groupWith(['honey' => 10]);

        $otherCompany = Company::factory()->create();
        $intruder = User::factory()->create(['company_id' => $otherCompany->id]);

        // 404, not an empty manifest: a foreign uuid must not be usable to probe
        // whether a Group exists.
        $this->actingAs($intruder)
            ->getJson(self::LOADING.'/groups/'.$group['id'])
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. The read writes nothing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reading_the_workspace_creates_no_session_assignment_trip_or_task(): void
    {
        $group = $this->groupWith(['honey' => 10]);

        $before = $this->executionRowCounts();

        $this->actingAs($this->userFor())
            ->getJson(self::LOADING.'/groups?warehouse_id='.$this->warehouse->id)
            ->assertOk();

        $this->actingAs($this->userFor())
            ->getJson(self::LOADING.'/groups/'.$group['id'])
            ->assertOk();

        // Reading twice, to catch a locate-or-create that only fires on a second pass.
        $this->actingAs($this->userFor())
            ->getJson(self::LOADING.'/groups/'.$group['id'])
            ->assertOk();

        self::assertSame($before, $this->executionRowCounts(), 'the workspace read must write nothing');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * JSON has ONE number type, so `10.0` is serialised as `10` and decodes to an int.
     * The value is exact either way; casting keeps the assertions about the QUANTITY
     * rather than about PHP's json_decode typing.
     */
    private function num(mixed $value): float
    {
        return (float) $value;
    }

    /** @return array<string, int> */
    private function executionRowCounts(): array
    {
        return [
            'loading_sessions' => (int) DB::table('loading_sessions')->count(),
            'vehicle_assignments' => (int) DB::table('vehicle_assignments')->count(),
            'loading_tasks' => (int) DB::table('loading_tasks')->count(),
            'distribution_trips' => (int) DB::table('distribution_trips')->count(),
            'vehicle_inventory_items' => (int) DB::table('vehicle_inventory_items')->count(),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function productRow(array $data, string $productId): array
    {
        $row = collect($data['products'])->firstWhere('product_id', $productId);
        self::assertNotNull($row, "product {$productId} missing from the manifest");

        return $row;
    }

    /** @param array<string, mixed> $group @return array<string, mixed> */
    private function manifest(array $group): array
    {
        return $this->actingAs($this->userFor())
            ->getJson(self::LOADING.'/groups/'.$group['id'])
            ->assertOk()
            ->json('data');
    }

    /**
     * A Trip + Loading Session + Vehicle Assignment + Loading Task, written directly.
     *
     * Deliberately fixture-level rather than driven through the write path: this suite
     * verifies the READ, and building the execution context by hand keeps the assertion
     * about where Loaded is read FROM rather than about how it got there. The write path
     * has its own certified suites.
     */
    private function recordLoad(string $groupId, string $productId, float $planned, float $loaded): void
    {
        $tripId = DB::table('distribution_trips')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'virtual_slot_id' => $groupId,
            'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'test trip',
            'status' => 'loading',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert([
            'id' => $sessionId,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'session_number' => 'LS-'.substr(uniqid(), -6),
            'operational_date' => now()->toDateString(),
            'status' => 'loading',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $assignmentId = (string) Str::uuid();
        DB::table('vehicle_assignments')->insert([
            'id' => $assignmentId,
            'company_id' => $this->company->id,
            'loading_session_id' => $sessionId,
            'trip_id' => $tripId,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => '1336',
            'vehicle_type_snapshot' => 'van',
            'assignment_number' => 'VA-'.substr(uniqid(), -6),
            'status' => 'loading',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('loading_tasks')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'loading_session_id' => $sessionId,
            'vehicle_assignment_id' => $assignmentId,
            // No FK on either column; the group grain carries no pool provenance, and
            // the nullability migration for it is not applied here.
            'pool_entry_id' => (string) Str::uuid(),
            'preparation_wave_id' => (string) Str::uuid(),
            'product_id' => $productId,
            'sku_snapshot' => 'SKU',
            'name_snapshot' => 'product',
            'quantity_planned' => $planned,
            'quantity_loaded' => $loaded,
            'status' => 'loaded',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWithOnly(string $permission): User
    {
        [$module, $resource, $action] = explode('.', $permission);

        $perm = Permission::firstOrCreate(
            ['name' => $permission],
            ['module' => $module, 'resource' => $resource, 'action' => $action],
        );

        $role = Role::create([
            'slug' => 'wh-'.Str::random(8),
            'name' => 'Warehouse actor',
            'is_system' => false,
        ]);
        $role->permissions()->attach($perm->id);

        $user = $this->userFor();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * A Group holding one order with the given products, collected and zoned.
     *
     * @param  array<string, float>  $products  keyed by property name (honey|coffee)
     * @return array<string, mixed>
     */
    private function groupWith(array $products): array
    {
        $order = $this->order();

        foreach ($products as $name => $qty) {
            $this->line($order, $this->{$name}->id, $qty);
        }

        $this->collect();
        $group = $this->group('DG-LW-'.substr(uniqid(), -5));
        $this->addZone($group['id'], $this->zone);

        return $group;
    }

    /** @param array<string, mixed> $group */
    private function setPrepared(array $group, string $productId, float $qty): void
    {
        $windowId = $this->windowId();

        $this->actingAs($this->userFor())
            ->putJson(
                self::DIST."/windows/{$windowId}/slots/{$group['id']}/preparation/{$productId}",
                ['prepared_qty' => $qty],
            )->assertOk();
    }

    private function order(): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-LW-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Maadi',
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

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'LW-'.substr(uniqid(), -6),
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

    private function userFor(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function collect(): void
    {
        $this->actingAs($this->userFor())
            ->postJson(self::DIST.'/windows/collect')
            ->assertOk();
    }

    private function windowId(): string
    {
        return (string) $this->actingAs($this->userFor())
            ->getJson(self::DIST.'/windows/current')
            ->assertOk()
            ->json('data.window.id');
    }

    /** @return array<string, mixed> */
    private function group(string $code): array
    {
        // Resolved FIRST: calling it inside the actingAs chain would re-authenticate
        // as a different user mid-request.
        $windowId = $this->windowId();

        return $this->actingAs($this->userFor())
            ->postJson(self::DIST."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => $code,
            ])->assertStatus(201)->json('data');
    }

    private function addZone(string $groupId, int $zoneId): void
    {
        $windowId = $this->windowId();

        $this->actingAs($this->userFor())
            ->postJson(self::DIST."/windows/{$windowId}/slots/{$groupId}/zones", ['zone_id' => $zoneId])
            ->assertOk();
    }
}
