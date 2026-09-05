<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ECOS-REPORTING-CROSS-DOMAIN-AND-FINANCIAL-REPORTS-004 §21 — end-to-end, real-MySQL
 * verification of the second tranche (Inventory, Procurement, Preparation, Distribution,
 * Drivers). Fast-baseline schema (§20): curated `--path` list, extending Task 3's own with
 * every module this tranche's handlers actually touch.
 */
final class CrossDomainReportExecutionFeatureTest extends TestCase
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
            '--path' => self::sharedMigrationPaths(),
        ]);
    }

    /**
     * Exposed as a static method (a PHPUnit `TestCase` cannot be `new`'d directly — its
     * constructor requires a test-name argument) so other Reporting Feature test classes
     * needing the same schema (e.g. `SourceRemediationRegressionTest`,
     * TASK-ECOS-REPORTING-V1-SOURCE-REMEDIATION-007) can reuse this exact path list rather
     * than maintaining a second copy — the same cross-class-consistency reasoning already
     * documented below (Laravel's `RefreshDatabaseState::$migrated` flag is process-wide,
     * not per-class).
     *
     * @return list<string>
     */
    public static function sharedMigrationPaths(): array
    {
        return [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_07_07_000002_add_company_id_to_users_table.php',
            'Modules/Organization/Companies/Infrastructure/Database/Migrations',
            'Modules/IAM/Infrastructure/Database/Migrations',
            'Modules/MasterData/Units/Infrastructure/Database/Migrations',
            'Modules/MasterData/Categories/Infrastructure/Database/Migrations/2026_06_23_100100_create_categories_table.php',
            'database/migrations/2026_07_02_300000_add_type_to_categories_table.php',
            'Modules/MasterData/Categories/Infrastructure/Database/Migrations/2026_07_04_100000_refactor_categories_type_to_scope.php',
            'Modules/Organization/Brands/Infrastructure/Database/Migrations/2026_07_05_140000_create_brands_table.php',
            'Modules/Organization/Branches/Infrastructure/Database/Migrations/2026_06_22_130000_create_branches_table.php',
            'Modules/MasterData/Warehouses/Infrastructure/Database/Migrations/2026_06_23_100200_create_warehouses_table.php',
            'Modules/MasterData/Warehouses/Infrastructure/Database/Migrations/2026_07_05_160000_remove_branch_id_from_warehouses_table.php',
            'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_23_110000_create_products_table.php',
            'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_23_111000_add_enrichment_fields_to_products_table.php',
            'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_25_230002_add_cost_intelligence_to_products_table.php',
            'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_25_250002_add_current_fifo_cost_to_products_table.php',
            'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_29_000001_add_manufacturing_fields_to_products_table.php',
            'Modules/Sales/Customers/Tests/Support/Migrations/2026_07_06_000001_add_company_id_to_products_table_test_only.php',
            'Modules/Sales/Customers/Tests/Support/Migrations/2026_07_06_100001_migrate_products_to_brand_ownership_test_only.php',
            'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations',
            'Modules/Inventory/ReceiptLayers/Infrastructure/Database/Migrations',
            'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_170000_create_channels_table.php',
            'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_600000_add_sync_customers_and_webhook_ids_to_channels.php',
            'Modules/Commerce/ProductMappings/Infrastructure/Database/Migrations/2026_06_23_180000_create_product_channel_mappings_table.php',
            'Modules/Operations/DemandAnalysis/Infrastructure/Database/Migrations',
            'Modules/Operations/Preparation/Infrastructure/Database/Migrations',
            'database/migrations/2026_07_05_200100_create_feature_flags_table.php',
            'Modules/CostManagement/Infrastructure/Database/Migrations/2026_07_02_200004_create_pricing_reviews_table.php',
            'Modules/Manufacturing/BillsOfMaterials/Infrastructure/Database/Migrations/2026_06_23_220000_create_bills_of_materials_tables.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_000001_create_order_notes_table.php',
            'database/migrations/2026_07_16_000001_create_enterprise_events_table.php',
            'database/migrations/2026_07_16_000002_create_enterprise_event_processing_log_table.php',
            'database/migrations/2026_07_16_000003_create_enterprise_dead_letter_queue_table.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_16_000001_create_distribution_zones_table.php',
            'Modules/Logistics/ShippingCompanies/Infrastructure/Database/Migrations/2026_07_23_100000_create_logistics_shipping_companies_table.php',
            'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100000_create_logistics_vehicles_table.php',
            'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100001_create_logistics_drivers_table.php',
            'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100003_create_logistics_driver_vehicle_assignments_table.php',
            'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_08_21_110000_add_tenant_and_uuid_to_logistics_drivers.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100000_create_distribution_trips_table.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100001_create_distribution_trip_orders_table.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100003_create_distribution_delivery_stops_table.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_29_120000_add_expected_collection_at_handoff_to_delivery_stops.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100008_create_distribution_payment_collections_table.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_29_120000_create_driver_trip_movements_table.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_11_100000_create_distribution_windows_table.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_11_100001_create_distribution_virtual_slots_table.php',
            'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_11_100003_create_distribution_window_orders_table.php',
            'Modules/Purchasing/Suppliers/Infrastructure/Database/Migrations',
            'Modules/Purchasing/PurchaseOrders/Infrastructure/Database/Migrations',
            'Modules/Purchasing/GoodsReceipts/Infrastructure/Database/Migrations',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_200000_create_orders_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_200001_create_order_lines_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_14_100001_add_fulfillment_quantities_to_order_lines.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_201000_add_ecommerce_fields_to_orders_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_210000_add_billing_financials_fees_coupons_to_orders.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_25_220002_add_assigned_warehouse_to_orders_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_25_240000_add_inventory_lifecycle_to_orders_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_100003_add_manual_order_fields_to_orders_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_200000_add_location_to_orders_table.php',
            'database/migrations/2026_07_14_000001_add_enterprise_address_fields_to_orders.php',
            'database/migrations/2026_07_14_000002_add_customer_snapshot_fields_to_orders.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_300000_create_order_events_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_600000_add_delivery_fields_to_orders_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_10_000001_add_confirmed_at_to_orders_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_11_000001_add_customer_name_to_orders_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_14_100000_enhance_order_events_for_activity_timeline.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_14_100002_add_internal_notes_and_creator_to_orders.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_100000_extend_order_events_enterprise_audit.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_200000_add_actor_role_to_order_events.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_300000_add_actor_email_to_order_events.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_18_100000_add_reservation_status_to_orders_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_18_100001_create_order_reservation_audits_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_08_910002_add_preparation_completed_at_to_orders_table.php',
            'database/migrations/2026_07_13_000002_add_reschedule_fields_to_orders.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_09_15_100002_add_hold_reason_code_to_orders_table.php',
            'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_06_23_160000_create_customers_table.php',
            'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_06_100001_create_customer_addresses_table.php',
            'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_08_910001_add_company_id_to_customers_table.php',
            'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_22_200000_create_customer_brands_table.php',
            'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_09_15_100000_create_customer_blocks_table.php',
            'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_09_15_100001_create_order_block_overrides_table.php',
            'Modules/Reporting/Infrastructure/Database/Migrations/2026_09_04_100000_seed_reporting_permissions_table.php',
            // TASK-ECOS-REPORTING-V1-FINAL-COVERAGE-AND-SOURCE-CLOSURE-005 — Laravel's
            // RefreshDatabaseState::$migrated flag is process-wide, not per-class: only
            // the FIRST test class to run in a given PHPUnit invocation actually gets its
            // migrateFreshUsing() executed, and every other RefreshDatabase test class in
            // the same run reuses that same schema. Since this suite always runs alongside
            // the Task 5 Feature test class, every Reporting Feature test class's migration
            // list must be a superset covering Task 5's needs too, regardless of run order.
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_400000_create_order_financial_snapshots_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_400001_create_order_line_snapshots_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_400002_enhance_order_financial_snapshots_table.php',
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_400003_enhance_order_line_snapshots_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_10_100000_create_finance_fiscal_years_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_10_100001_create_finance_fiscal_periods_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_10_100002_create_finance_accounts_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_10_100003_create_finance_cost_centers_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_10_100006_create_finance_journal_entries_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_10_100007_create_finance_journal_lines_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_10_100011_seed_finance_permissions_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_10_100012_add_category_to_finance_accounts.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_10_100013_add_type_to_finance_journal_entries.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_11_100000_create_finance_customer_invoices_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_11_100002_create_finance_customer_receipts_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_11_100003_create_finance_receipt_allocations_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_11_100004_create_finance_customer_ledger_entries_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_11_100005_create_finance_supplier_bills_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_11_100007_create_finance_supplier_payments_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_11_100008_create_finance_payment_allocations_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_11_100009_create_finance_supplier_ledger_entries_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_11_100018_seed_finance_f2_permissions_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_25_100008_create_finance_vat_periods_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_08_25_100010_create_finance_control_exceptions_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_09_02_100000_add_contra_allocation_columns_to_finance_payment_allocations_table.php',
            'Modules/Finance/Infrastructure/Database/Migrations/2026_09_02_100001_add_contra_allocation_columns_to_finance_receipt_allocations_table.php',
        ];
    }

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function userWithPermission(string ...$permissionNames): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::query()->create(['name' => 'Report Viewer '.uniqid(), 'slug' => 'report-viewer-'.uniqid(), 'is_system' => false]);

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

    // ── B: authorization — one check per new category ──────────────────────

    public function test_authorized_inventory_request_succeeds(): void
    {
        $user = $this->userWithPermission('reports.inventory.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-INV-01/execute');
        $response->assertOk();
        $this->assertSame('RPT-INV-01', $response->json('data.report_id'));
    }

    public function test_authorized_procurement_request_succeeds(): void
    {
        $user = $this->userWithPermission('reports.procurement.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROC-01/execute');
        $response->assertOk();
    }

    public function test_authorized_preparation_request_succeeds(): void
    {
        $user = $this->userWithPermission('reports.preparation.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PREP-01/execute');
        $response->assertOk();
    }

    public function test_authorized_distribution_request_succeeds(): void
    {
        $user = $this->userWithPermission('reports.distribution.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-DIST-02/execute');
        $response->assertOk();
    }

    public function test_authorized_drivers_request_requires_its_own_permission(): void
    {
        $user = $this->userWithPermission('reports.inventory.view'); // wrong category on purpose
        $driver = DB::table('logistics_drivers')->insertGetId([
            'driver_code' => 'D-1', 'full_name' => 'Driver One',
            'mobile' => '01000000000', 'national_id' => '11111111111111', 'status' => 'active',
            'company_id' => $this->company->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson("/api/reporting/reports/RPT-DRV-03/execute?driver_id={$driver}&month=2026-04");
        $response->assertForbidden();
    }

    // ── D/Regression: the two real defects this task found and fixed ───────

    public function test_supplier_summary_stats_is_tenant_scoped_after_the_fix(): void
    {
        $otherCompany = Company::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        PurchaseOrder::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => Supplier::factory()->create(['company_id' => $this->company->id])->id,
            'po_number' => 'PO-MINE', 'status' => 'approved', 'order_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id,
            'subtotal' => 0, 'total' => 0,
        ]);
        // A purchase order belonging to a DIFFERENT company — must never be counted.
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        PurchaseOrder::query()->create([
            'company_id' => $otherCompany->id, 'supplier_id' => Supplier::factory()->create(['company_id' => $otherCompany->id])->id,
            'po_number' => 'PO-OTHER', 'status' => 'approved', 'order_date' => now()->toDateString(), 'warehouse_id' => $otherWarehouse->id,
            'subtotal' => 0, 'total' => 0,
        ]);

        $user = $this->userWithPermission('reports.procurement.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROC-01/execute');

        $response->assertOk();
        // TASK-ECOS-REPORTING-V1-SOURCE-REMEDIATION-007: PurchasingOverviewQuery's `totals`
        // shape changed — GetSupplierSummaryStatsQuery's own snapshot figures (including
        // open_pos_total) now live under `totals.snapshot`, not at the top level, since
        // MET-PROC-01/02 are no longer mapped to those non-period-bound figures.
        $this->assertSame(1, $response->json('data.totals.snapshot.open_pos_total'), 'GetSupplierSummaryStatsQuery must count only the caller\'s own company\'s open POs after the tenant-scoping fix.');
    }

    public function test_supplier_analytics_query_executes_without_an_ambiguous_column_error(): void
    {
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $po = PurchaseOrder::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'po_number' => 'PO-1',
            'status' => 'approved', 'order_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id,
            'subtotal' => 0, 'total' => 0,
        ]);
        GoodsReceipt::query()->create([
            'company_id' => $this->company->id, 'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id, 'receipt_number' => 'GR-1', 'status' => 'posted',
            'receipt_date' => now()->toDateString(), 'invoice_total_amount' => 500, 'paid_amount' => 200,
        ]);

        $user = $this->userWithPermission('reports.procurement.view');
        // The specific query historically threw "column reference company_id is ambiguous"
        // for any real, scoped, non-system caller — proving no exception is thrown here IS
        // the regression proof.
        $response = $this->actingAs($user)->getJson("/api/reporting/reports/RPT-PROC-02/execute?supplier_id={$supplier->id}");

        $response->assertOk();
        $this->assertSame(1, $response->json('data.totals.analytics.total_purchases'));
    }

    // ── D: source authority correctness ─────────────────────────────────────

    public function test_stock_on_hand_reads_available_and_reserved_correctly(): void
    {
        $user = $this->userWithPermission('reports.inventory.view');
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        InventoryItem::query()->create([
            'company_id' => $this->company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'on_hand_qty' => 100, 'reserved_qty' => 30,
        ]);

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-INV-01/execute');

        $response->assertOk();
        $this->assertEquals(70.0, $response->json('data.kpis.MET-INV-01'));
        $this->assertEquals(30.0, $response->json('data.kpis.MET-INV-02'));
    }

    public function test_inventory_valuation_uses_the_same_fifo_formula_as_the_cost_engine(): void
    {
        $user = $this->userWithPermission('reports.inventory.view');
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        InventoryReceiptLayer::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'received_qty' => 10, 'remaining_qty' => 10,
            'landed_unit_cost' => 25.0, 'receipt_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-INV-02/execute');

        $response->assertOk();
        $this->assertEquals(250.0, $response->json('data.kpis.MET-INV-03'));
    }

    public function test_delivery_performance_reads_delivery_stop_not_a_reconstructed_figure(): void
    {
        $user = $this->userWithPermission('reports.distribution.view');
        $trip = Trip::query()->create(['company_id' => $this->company->id, 'trip_number' => 'T-1', 'name' => 'Trip 1', 'capacity' => 60]);
        $order = $this->minimalOrder();
        DeliveryStop::query()->create(['trip_id' => $trip->id, 'order_id' => $order, 'status' => DeliveryStopStatus::Delivered->value, 'attempted_at' => now(), 'completed_at' => now()->addMinutes(20), 'sequence' => 1]);
        $order2 = $this->minimalOrder();
        DeliveryStop::query()->create(['trip_id' => $trip->id, 'order_id' => $order2, 'status' => DeliveryStopStatus::Failed->value, 'attempted_at' => now(), 'sequence' => 2]);

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-DIST-02/execute');

        $response->assertOk();
        $this->assertEquals(50.0, $response->json('data.kpis.MET-DIST-01'));
    }

    // ── E: read-only guarantee ───────────────────────────────────────────────

    public function test_report_execution_produces_no_domain_mutation(): void
    {
        $user = $this->userWithPermission('reports.inventory.view', 'reports.procurement.view');
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        $item = InventoryItem::query()->create([
            'company_id' => $this->company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'on_hand_qty' => 50, 'reserved_qty' => 10,
        ]);

        $beforeItems = DB::table('inventory_items')->count();
        $beforeUpdatedAt = $item->fresh()->updated_at;

        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-INV-01/execute')->assertOk();
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-INV-04/execute?warehouse_id='.$warehouse->id)->assertOk();
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROC-01/execute')->assertOk();

        $this->assertSame($beforeItems, DB::table('inventory_items')->count());
        $this->assertEquals($beforeUpdatedAt, $item->fresh()->updated_at);
    }

    // ── F: query count evidence ──────────────────────────────────────────────

    public function test_stock_on_hand_uses_a_bounded_query_count_regardless_of_row_count(): void
    {
        $user = $this->userWithPermission('reports.inventory.view');
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        foreach (range(1, 3) as $i) {
            InventoryItem::query()->create(['company_id' => $this->company->id, 'warehouse_id' => $warehouse->id, 'product_id' => Product::factory()->create(['company_id' => $this->company->id])->id, 'on_hand_qty' => 10, 'reserved_qty' => 0]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-INV-01/execute?per_page=50')->assertOk();
        $countFor3 = count(DB::getQueryLog());

        foreach (range(1, 12) as $i) {
            InventoryItem::query()->create(['company_id' => $this->company->id, 'warehouse_id' => $warehouse->id, 'product_id' => Product::factory()->create(['company_id' => $this->company->id])->id, 'on_hand_qty' => 10, 'reserved_qty' => 0]);
        }
        DB::flushQueryLog();
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-INV-01/execute?per_page=50')->assertOk();
        $countFor15 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($countFor3 + 2, $countFor15, 'Query count grew with row count — suspected N+1.');
    }

    private function minimalOrder(): string
    {
        $customer = \Modules\Sales\Customers\Domain\Models\Customer::factory()->create(['company_id' => $this->company->id]);

        return \Modules\Commerce\Orders\Domain\Models\Order::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(), 'status' => 'delivered',
            'subtotal' => 0, 'total' => 0, 'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0, 'discount_amount' => 0,
        ])->id;
    }
}
