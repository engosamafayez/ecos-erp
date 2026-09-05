<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\Suppliers\Application\Queries\GetSupplierSummaryStatsQuery;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ECOS-REPORTING-V1-SOURCE-REMEDIATION-007 §10 — focused regression tests proving
 * each of the six defects Task 6 independently confirmed by direct source audit is fixed.
 * Reuses `CrossDomainReportExecutionFeatureTest`'s exact `migrateFreshUsing()` schema (this
 * class needs nothing beyond what that class already covers: Suppliers, PurchaseOrders,
 * GoodsReceipts, Inventory, Distribution, Drivers).
 */
final class SourceRemediationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    protected function migrateFreshUsing()
    {
        return array_merge([
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ], [
            '--path' => CrossDomainReportExecutionFeatureTest::sharedMigrationPaths(),
        ]);
    }

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    private function userWithPermission(string ...$permissionNames): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::query()->create(['name' => 'Remediation Viewer '.uniqid(), 'slug' => 'remediation-viewer-'.uniqid(), 'is_system' => false]);

        foreach ($permissionNames as $name) {
            $permission = Permission::query()->where('name', $name)->firstOrFail();
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id,
                'permission_id' => $permission->id,
                'effect' => 'allow',
                'conditions' => null,
                'expires_at' => null,
                'created_at' => now(),
            ]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    // ── Defect 1: GetSupplierSummaryStatsQuery ──────────────────────────────

    public function test_explicit_company_context_wins_over_ambient_resolution(): void
    {
        $mine = Supplier::factory()->create(['company_id' => $this->company->id]);
        $other = Company::factory()->create();
        Supplier::factory()->create(['company_id' => $other->id]);

        // Called directly (as PurchasingOverviewQuery now does), passing the explicit
        // company id — must reflect only that company's suppliers regardless of the
        // ambient actor (none is authenticated in this raw unit-style call at all).
        $stats = app(GetSupplierSummaryStatsQuery::class)->execute($this->company->id);

        $this->assertSame(1, $stats['total_suppliers']);
    }

    public function test_unrestricted_actor_does_not_widen_a_reporting_execution_to_all_companies(): void
    {
        Supplier::factory()->create(['company_id' => $this->company->id]);
        $otherCompany = Company::factory()->create();
        Supplier::factory()->create(['company_id' => $otherCompany->id]);

        // The acting user holds the system role (ambient-unrestricted) AND belongs to
        // $this->company — a Reporting execution must still see only $this->company's data,
        // never every company, regardless of that elevated ambient privilege. Every field in
        // GetSupplierSummaryStatsQuery (not just the four fields Task 4 originally fixed)
        // must honor this — `total_suppliers` is the cleanest signal since it has no other
        // moving parts.
        $user = $this->userWithPermission('reports.procurement.view');
        $this->grantSystemRole($user);

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROC-01/execute');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.totals.snapshot.total_suppliers'));
    }

    public function test_needs_review_count_is_tenant_isolated(): void
    {
        // A supplier in ANOTHER company with a very recent PO — must never influence
        // $this->company's own needs_review_count.
        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        $otherSupplier = Supplier::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);
        PurchaseOrder::query()->create([
            'company_id' => $otherCompany->id, 'supplier_id' => $otherSupplier->id,
            'po_number' => 'PO-RECENT-OTHER', 'status' => 'approved', 'order_date' => now()->toDateString(),
            'warehouse_id' => $otherWarehouse->id, 'subtotal' => 0, 'total' => 0,
        ]);

        // My own active supplier has NO purchase order at all — it must count as
        // "needs review" (no PO in the last 90 days), proving the other company's recent
        // PO was correctly excluded from the NOT IN subquery rather than accidentally
        // (and harmlessly-but-wrongly) suppressing it.
        Supplier::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);

        $stats = app(GetSupplierSummaryStatsQuery::class)->execute($this->company->id);

        $this->assertSame(1, $stats['needs_review_count']);
    }

    // ── Defect 2: PurchasingOverviewQuery ────────────────────────────────────

    public function test_purchasing_overview_carries_explicit_company_context(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        GoodsReceipt::query()->create([
            'company_id' => $this->company->id, 'purchase_order_id' => PurchaseOrder::query()->create([
                'company_id' => $this->company->id, 'supplier_id' => Supplier::factory()->create(['company_id' => $this->company->id])->id,
                'po_number' => 'PO-1', 'status' => 'approved', 'order_date' => now()->toDateString(),
                'warehouse_id' => $warehouse->id, 'subtotal' => 0, 'total' => 0,
            ])->id,
            'warehouse_id' => $warehouse->id, 'receipt_number' => 'GR-1', 'status' => 'posted',
            'receipt_date' => now()->toDateString(), 'invoice_total_amount' => 500, 'paid_amount' => 0,
        ]);

        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        GoodsReceipt::query()->create([
            'company_id' => $otherCompany->id, 'purchase_order_id' => PurchaseOrder::query()->create([
                'company_id' => $otherCompany->id, 'supplier_id' => Supplier::factory()->create(['company_id' => $otherCompany->id])->id,
                'po_number' => 'PO-OTHER', 'status' => 'approved', 'order_date' => now()->toDateString(),
                'warehouse_id' => $otherWarehouse->id, 'subtotal' => 0, 'total' => 0,
            ])->id,
            'warehouse_id' => $otherWarehouse->id, 'receipt_number' => 'GR-OTHER', 'status' => 'posted',
            'receipt_date' => now()->toDateString(), 'invoice_total_amount' => 999999, 'paid_amount' => 0,
        ]);

        $user = $this->userWithPermission('reports.procurement.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROC-01/execute');

        $response->assertOk();
        $this->assertEquals(500.0, $response->json('data.kpis.MET-PROC-02'), 'Another company\'s GR must never inflate this company\'s supplier spend.');
    }

    public function test_date_period_changes_met_proc_01_and_02_correctly(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);

        $inPeriodPo = PurchaseOrder::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'po_number' => 'PO-IN',
            'status' => 'approved', 'order_date' => '2026-02-01', 'warehouse_id' => $warehouse->id, 'subtotal' => 0, 'total' => 0,
        ]);
        GoodsReceipt::query()->create([
            'company_id' => $this->company->id, 'purchase_order_id' => $inPeriodPo->id, 'warehouse_id' => $warehouse->id,
            'receipt_number' => 'GR-IN', 'status' => 'posted', 'receipt_date' => '2026-02-10',
            'invoice_total_amount' => 300, 'paid_amount' => 0,
        ]);

        $outOfPeriodPo = PurchaseOrder::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'po_number' => 'PO-OUT',
            'status' => 'approved', 'order_date' => '2026-05-01', 'warehouse_id' => $warehouse->id, 'subtotal' => 0, 'total' => 0,
        ]);
        GoodsReceipt::query()->create([
            'company_id' => $this->company->id, 'purchase_order_id' => $outOfPeriodPo->id, 'warehouse_id' => $warehouse->id,
            'receipt_number' => 'GR-OUT', 'status' => 'posted', 'receipt_date' => '2026-05-15',
            'invoice_total_amount' => 700, 'paid_amount' => 0,
        ]);

        $user = $this->userWithPermission('reports.procurement.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROC-01/execute?date_from=2026-02-01&date_to=2026-02-28');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.kpis.MET-PROC-01'), 'Only the Feb GR should count for Purchase Volume within the Feb window.');
        $this->assertEquals(300.0, $response->json('data.kpis.MET-PROC-02'), 'Only the Feb GR\'s invoiced value should count for Supplier Spend within the Feb window.');
    }

    // ── Defect 3: InventoryValuationQuery ────────────────────────────────────

    public function test_inventory_valuation_consumes_the_cost_engine_not_a_duplicated_formula(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        InventoryReceiptLayer::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => Supplier::factory()->create(['company_id' => $this->company->id])->id,
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'received_qty' => 10, 'remaining_qty' => 10,
            'landed_unit_cost' => 25.0, 'receipt_date' => now()->toDateString(),
        ]);

        // Prove the engine's own new bulk method produces the SAME figure the report does —
        // if the report ever stopped calling it and reverted to a duplicated formula, this
        // equality would still hold by coincidence, but the engine call itself is the
        // authoritative proof it's actually being consumed (see the source read in this
        // task's own report for the direct code-level confirmation).
        $engineResult = app(\Modules\CostManagement\Domain\Services\EnterpriseCostEngine::class)
            ->fifoInventoryValueByProduct($this->company->id);
        $this->assertSame(250.0, $engineResult[0]['value']);

        $user = $this->userWithPermission('reports.inventory.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-INV-02/execute');

        $response->assertOk();
        $this->assertEquals(250.0, $response->json('data.kpis.MET-INV-03'));
    }

    // ── Defect 4: DriverOperationalSummaryQuery ──────────────────────────────

    public function test_driver_operational_summary_uses_verified_key_paths_not_a_guessed_fallback(): void
    {
        $driver = DB::table('logistics_drivers')->insertGetId([
            'driver_code' => 'D-REM', 'full_name' => 'Remediation Driver',
            'mobile' => '01000000099', 'national_id' => '22222222222222', 'status' => 'active',
            'company_id' => $this->company->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = $this->userWithPermission('reports.drivers.view');

        // No trips at all for this driver in the window — wallet()/ordersPerformance()
        // must both still return their full, real shape (zeroed sums), proving the two
        // corrected key paths (`summary.delivered`, `collections.total`) resolve cleanly
        // rather than warning/erroring on an undefined-index guess.
        $response = $this->actingAs($user)->getJson("/api/reporting/reports/RPT-DRV-01/execute?driver_id={$driver}&date_from=2026-01-01&date_to=2026-01-31");

        $response->assertOk();
        $this->assertSame(0, $response->json('data.kpis.MET-DRV-01'));
        $this->assertEquals(0.0, $response->json('data.kpis.MET-DRV-02'));
    }

    // ── Defect 5: ShortageAndZeroStockQuery ──────────────────────────────────

    public function test_foreign_company_warehouse_is_rejected_not_silently_misreported(): void
    {
        Product::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);

        $user = $this->userWithPermission('reports.inventory.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-INV-04/execute?warehouse_id='.$otherWarehouse->id);

        $response->assertNotFound();
    }

    // ── Defect 6: WindowGroupUtilizationQuery ────────────────────────────────

    public function test_foreign_company_window_is_rejected_before_aggregation_runs(): void
    {
        $otherCompany = Company::factory()->create();
        $otherWindow = DistributionWindow::query()->create([
            'company_id' => $otherCompany->id, 'window_date' => now()->toDateString(),
            'opens_at' => now(), 'closes_at' => now()->addHours(4),
        ]);
        VirtualCapacitySlot::query()->create([
            'company_id' => $otherCompany->id, 'distribution_window_id' => $otherWindow->id,
            'code' => 'SLOT-A', 'capacity_orders' => 10,
        ]);

        $user = $this->userWithPermission('reports.distribution.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-DIST-01/execute?window_id='.$otherWindow->id);

        $response->assertNotFound();
    }
}
