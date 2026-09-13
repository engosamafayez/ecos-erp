<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\CountSessions\Application\Actions\ApproveCountSessionAction;
use Modules\Inventory\CountSessions\Application\Actions\CompleteCountSessionAction;
use Modules\Inventory\CountSessions\Application\Actions\CreateCountSessionAction;
use Modules\Inventory\CountSessions\Application\Actions\StartCountSessionAction;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountLine;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountSession;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-OPS-01-IMPLEMENTATION-044A-R1 — Inventory Control analytics
 * tenant isolation.
 *
 * Characterization + regression tests: InventoryDashboardService,
 * VarianceAnalyticsService and WarehousePerformanceService issued raw
 * query-builder queries with NO company filter at all, behind a route gated
 * only by `auth:sanctum` — any authenticated user of any company could read
 * every other company's inventory-count accuracy, shrinkage/adjustment
 * values, top variance products (real names/SKUs) and warehouse names.
 *
 * NOT YET EXECUTED — written and reviewed statically; this session's shell
 * execution was blocked by an unrelated environment issue before these could
 * be run. See the engineering report.
 *
 * Deliberately run with `$grantsBaselineAuthorization = false` — see
 * WarehouseTenantIsolationTest for why.
 */
final class InventoryAnalyticsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private const PERMISSION = 'inventory.count.view';

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** A company-scoped operator: real inventory.count.view permission, no is_system role. */
    private function operatorFor(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-inventory-analytics-viewer'],
            ['name' => 'Test Inventory Analytics Viewer', 'is_system' => false],
        );

        [$module, $resource, $action] = explode('.', self::PERMISSION);
        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION],
            ['module' => $module, 'resource' => $resource, 'action' => $action],
        );

        if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function unrestrictedUser(): User
    {
        $user = User::factory()->create(['company_id' => null]);

        return $this->grantSystemRole($user);
    }

    /** A real approved count session with one line, for one company/warehouse/product. */
    private function approvedSession(Company $company, Warehouse $warehouse, Product $product, float $onHand, float $countedQty): InventoryCountSession
    {
        InventoryItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);

        if ($countedQty < $onHand) {
            // Negative variance needs a consumable receipt layer (FIFO).
            InventoryReceiptLayer::query()->create([
                'company_id' => $company->id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'received_qty' => $onHand,
                'remaining_qty' => $onHand,
                'landed_unit_cost' => 80.0,
                'receipt_date' => now()->toDateString(),
            ]);
        }

        $session = app(CreateCountSessionAction::class)->execute([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'notes' => 'tenant-isolation-fixture',
        ]);

        app(StartCountSessionAction::class)->execute($session);

        InventoryCountLine::query()
            ->where('session_id', $session->id)
            ->where('product_id', $product->id)
            ->update(['counted_qty' => $countedQty]);

        app(CompleteCountSessionAction::class)->execute($session->refresh());
        app(ApproveCountSessionAction::class)->execute($session->refresh());

        return $session->refresh();
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function test_dashboard_recent_sessions_excludes_another_companys_session(): void
    {
        $ownCompany = Company::factory()->create();
        $ownWarehouse = Warehouse::factory()->create(['company_id' => $ownCompany->id, 'name' => 'Own Warehouse']);
        $ownProduct = Product::factory()->create();
        $ownSession = $this->approvedSession($ownCompany, $ownWarehouse, $ownProduct, 10.0, 10.0);

        $foreignCompany = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreignCompany->id, 'name' => 'Foreign Warehouse']);
        $foreignProduct = Product::factory()->create();
        $this->approvedSession($foreignCompany, $foreignWarehouse, $foreignProduct, 10.0, 10.0);

        $this->actingAsUnprivileged($this->operatorFor($ownCompany));

        $response = $this->getJson('/api/inventory/dashboard')->assertOk();

        $recentIds = array_column($response->json('data.recent_sessions'), 'id');
        self::assertContains($ownSession->id, $recentIds);

        $recentNames = array_column($response->json('data.recent_sessions'), 'warehouse_name');
        self::assertNotContains('Foreign Warehouse', $recentNames);

        // KPIs are counted, not just filtered lists — must reflect only the own session.
        self::assertEquals(1, $response->json('data.kpis.total_counted_products'));
    }

    public function test_dashboard_top_variance_products_exclude_another_companys_products(): void
    {
        $ownCompany = Company::factory()->create();
        $ownWarehouse = Warehouse::factory()->create(['company_id' => $ownCompany->id]);
        $ownProduct = Product::factory()->create(['name' => 'Own Shortfall Product']);
        $this->approvedSession($ownCompany, $ownWarehouse, $ownProduct, 10.0, 4.0);

        $foreignCompany = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreignCompany->id]);
        $foreignProduct = Product::factory()->create(['name' => 'Foreign Shortfall Product']);
        $this->approvedSession($foreignCompany, $foreignWarehouse, $foreignProduct, 10.0, 2.0);

        $this->actingAsUnprivileged($this->operatorFor($ownCompany));

        $response = $this->getJson('/api/inventory/dashboard')->assertOk();

        $names = array_column($response->json('data.top_negative'), 'product_name');
        self::assertContains('Own Shortfall Product', $names);
        self::assertNotContains('Foreign Shortfall Product', $names);
    }

    public function test_dashboard_requires_authenticated_company_scope(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create();
        $this->approvedSession($company, $warehouse, $product, 10.0, 10.0);

        $user = User::factory()->create(['company_id' => null]);
        $role = Role::firstOrCreate(
            ['slug' => 'test-companyless-analytics-viewer'],
            ['name' => 'Test Companyless Analytics Viewer', 'is_system' => false],
        );
        [$module, $resource, $action] = explode('.', self::PERMISSION);
        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION],
            ['module' => $module, 'resource' => $resource, 'action' => $action],
        );
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $this->actingAsUnprivileged($user);

        $response = $this->getJson('/api/inventory/dashboard')->assertOk();

        self::assertSame([], $response->json('data.recent_sessions'), 'A NULL company must not mean "return everything".');
        self::assertEquals(0, $response->json('data.kpis.total_counted_products'));
    }

    public function test_dashboard_route_rejects_a_user_without_the_view_permission(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $role = Role::firstOrCreate(
            ['slug' => 'test-no-inventory-count-permission'],
            ['name' => 'Test No Inventory Count Permission', 'is_system' => false],
        );
        $user->roles()->attach($role->id);

        $this->actingAsUnprivileged($user);

        $this->getJson('/api/inventory/dashboard')->assertForbidden();
        $this->getJson('/api/inventory/variance-analytics')->assertForbidden();
        $this->getJson('/api/inventory/warehouse-performance')->assertForbidden();
    }

    public function test_unrestricted_user_retains_cross_company_dashboard_visibility(): void
    {
        $a = Company::factory()->create();
        $wa = Warehouse::factory()->create(['company_id' => $a->id]);
        $pa = Product::factory()->create();
        $this->approvedSession($a, $wa, $pa, 10.0, 10.0);

        $b = Company::factory()->create();
        $wb = Warehouse::factory()->create(['company_id' => $b->id]);
        $pb = Product::factory()->create();
        $this->approvedSession($b, $wb, $pb, 10.0, 10.0);

        $this->actingAsUnprivileged($this->unrestrictedUser());

        $response = $this->getJson('/api/inventory/dashboard')->assertOk();
        self::assertEquals(2, $response->json('data.kpis.total_counted_products'), 'The documented is_system capability must be preserved.');
    }

    // ── Variance analytics ───────────────────────────────────────────────────

    public function test_variance_analytics_by_warehouse_excludes_another_companys_warehouse(): void
    {
        $ownCompany = Company::factory()->create();
        $ownWarehouse = Warehouse::factory()->create(['company_id' => $ownCompany->id, 'name' => 'Own Warehouse']);
        $ownProduct = Product::factory()->create();
        $this->approvedSession($ownCompany, $ownWarehouse, $ownProduct, 10.0, 8.0);

        $foreignCompany = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreignCompany->id, 'name' => 'Foreign Warehouse']);
        $foreignProduct = Product::factory()->create();
        $this->approvedSession($foreignCompany, $foreignWarehouse, $foreignProduct, 10.0, 6.0);

        $this->actingAsUnprivileged($this->operatorFor($ownCompany));

        $response = $this->getJson('/api/inventory/variance-analytics')->assertOk();

        $warehouseNames = array_column($response->json('data.by_warehouse'), 'warehouse_name');
        self::assertContains('Own Warehouse', $warehouseNames);
        self::assertNotContains('Foreign Warehouse', $warehouseNames);
    }

    // ── Warehouse performance ────────────────────────────────────────────────

    public function test_warehouse_performance_excludes_another_companys_warehouse(): void
    {
        $ownCompany = Company::factory()->create();
        $ownWarehouse = Warehouse::factory()->create(['company_id' => $ownCompany->id, 'name' => 'Own Warehouse']);
        $ownProduct = Product::factory()->create();
        $this->approvedSession($ownCompany, $ownWarehouse, $ownProduct, 10.0, 10.0);

        $foreignCompany = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreignCompany->id, 'name' => 'Foreign Warehouse']);
        $foreignProduct = Product::factory()->create();
        $this->approvedSession($foreignCompany, $foreignWarehouse, $foreignProduct, 10.0, 10.0);

        $this->actingAsUnprivileged($this->operatorFor($ownCompany));

        $response = $this->getJson('/api/inventory/warehouse-performance')->assertOk();

        $names = array_column($response->json('data'), 'warehouse_name');
        self::assertContains('Own Warehouse', $names);
        self::assertNotContains('Foreign Warehouse', $names);
    }
}
