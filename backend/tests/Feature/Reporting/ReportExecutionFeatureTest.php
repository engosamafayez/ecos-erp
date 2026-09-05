<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Tests\TestCase;

/**
 * TASK-ECOS-REPORTING-QUERY-EXECUTION-AND-FIRST-REPORTS-003 §15 (B-F) — end-to-end,
 * real-MySQL verification of the execution surface for the first tranche.
 *
 * Fast-baseline schema (§16): a curated `--path` list, not the full ~700-migration chain
 * — the same convention `BlockedOrderFulfillmentTest`/`CustomerIntelligenceMetricsTest`
 * already use, extended with the two Distribution delivery-stop migrations
 * RPT-SALES-04 needs and this task's own Reporting permissions-seed migration.
 */
final class ReportExecutionFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    /** IDENTICAL base list to BlockedOrderFulfillmentTest::migrateFreshUsing(), plus this task's own additions (see class docblock). */
    protected function migrateFreshUsing()
    {
        return array_merge([
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ], [
            '--path' => [
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
                'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations/2026_06_24_800000_create_inventory_items_table.php',
                'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations/2026_06_24_810000_create_stock_ledger_entries_table.php',
                'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations/2026_07_20_100000_fix_inventory_items_soft_delete_unique.php',
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_170000_create_channels_table.php',
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_600000_add_sync_customers_and_webhook_ids_to_channels.php',
                'Modules/Commerce/ProductMappings/Infrastructure/Database/Migrations/2026_06_23_180000_create_product_channel_mappings_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_05_100100_create_preparation_waves_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_05_100200_create_preparation_wave_orders_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_08_13_100000_add_postponed_at_to_preparation_wave_orders.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_08_15_100002_add_membership_release_to_preparation_wave_orders.php',
                'Modules/Operations/DemandAnalysis/Infrastructure/Database/Migrations/2026_07_16_200001_create_wave_material_demand_table.php',
                'database/migrations/2026_07_05_200100_create_feature_flags_table.php',
                'Modules/CostManagement/Infrastructure/Database/Migrations/2026_07_02_200004_create_pricing_reviews_table.php',
                'Modules/Manufacturing/BillsOfMaterials/Infrastructure/Database/Migrations/2026_06_23_220000_create_bills_of_materials_tables.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_000001_create_order_notes_table.php',
                'database/migrations/2026_07_16_000001_create_enterprise_events_table.php',
                'database/migrations/2026_07_16_000002_create_enterprise_event_processing_log_table.php',
                'database/migrations/2026_07_16_000003_create_enterprise_dead_letter_queue_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_210005_add_assignment_fields_to_orders_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_16_000001_create_distribution_zones_table.php',
                'Modules/Logistics/ShippingCompanies/Infrastructure/Database/Migrations/2026_07_23_100000_create_logistics_shipping_companies_table.php',
                'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100000_create_logistics_vehicles_table.php',
                'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100001_create_logistics_drivers_table.php',
                'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100003_create_logistics_driver_vehicle_assignments_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100000_create_distribution_trips_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100001_create_distribution_trip_orders_table.php',
                // Task 3 addition — RPT-SALES-04 reads distribution_delivery_stops directly.
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100003_create_distribution_delivery_stops_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_29_120000_add_expected_collection_at_handoff_to_delivery_stops.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_110000_create_preparation_sessions_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_210004_create_preparation_session_orders_table.php',
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
                // Task 3 addition — this task's own Reporting permissions seed.
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
            ],
        ]);
    }

    private Company $company;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function userWithPermission(string ...$permissionNames): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::query()->create(['name' => 'Report Viewer '.uniqid(), 'slug' => 'report-viewer-'.uniqid(), 'is_system' => false]);

        foreach ($permissionNames as $name) {
            $permission = Permission::query()->where('name', $name)->firstOrFail();
            DB::table('role_permissions')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
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

    private function userWithNoPermissions(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function order(Company $company, OrderStatus $status, string $orderDate, ?Customer $customer = null, ?string $requestedDeliveryDate = null): Order
    {
        return Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer?->id ?? Customer::factory()->create(['company_id' => $company->id])->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => $orderDate,
            'requested_delivery_date' => $requestedDeliveryDate,
            'status' => $status->value,
            'subtotal' => 0,
            'total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'discount_amount' => 0,
        ]);
    }

    private function line(Order $order, float $quantity, float $unitPrice): void
    {
        $order->lines()->create([
            'product_id' => \Modules\Inventory\Products\Domain\Models\Product::factory()->create(['company_id' => $order->company_id])->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
        ]);
    }

    // ── A/B: authorization ──────────────────────────────────────────────────

    public function test_authorized_request_succeeds(): void
    {
        $user = $this->userWithPermission('reports.sales.view');
        $this->order($this->company, OrderStatus::InProgress, '2026-08-01');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute');

        $response->assertOk();
        $this->assertSame('RPT-SALES-01', $response->json('data.report_id'));
    }

    public function test_unauthorized_request_is_denied(): void
    {
        $user = $this->userWithNoPermissions();

        $response = $this->actingAsUnprivileged($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute');

        $response->assertForbidden();
    }

    public function test_permission_for_one_category_does_not_grant_another(): void
    {
        // Holds ONLY reports.customers.view — proves the dynamic per-report permission
        // resolution actually reads the requested report's OWN permission, not a blanket
        // "any reports.* passes" check.
        $user = $this->userWithPermission('reports.customers.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute');

        $response->assertForbidden();
    }

    public function test_unknown_report_id_returns_404(): void
    {
        $user = $this->userWithPermission('reports.sales.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-NOT-REAL/execute');

        $response->assertNotFound();
    }

    public function test_previously_unwired_report_now_executes_after_full_v1_coverage(): void
    {
        // TASK-ECOS-REPORTING-V1-FINAL-COVERAGE-AND-SOURCE-CLOSURE-005 — RPT-EXEC-01 was
        // this Task 3 test's own example of "a real, is_v1 catalogue entry with no handler
        // yet" (asserting a 501). Task 5 wired all 35 catalogue entries, so that premise no
        // longer holds for ANY real report ID in this system — the underlying 501 code path
        // itself is still covered at the unit level (ReportExecutionServiceTest, with a
        // synthetic unregistered handler), so this Feature-level test is repurposed to prove
        // the positive: full V1 coverage means this specific report now executes cleanly.
        $user = $this->userWithPermission('reports.executive.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-EXEC-01/execute');

        $response->assertOk();
        $this->assertSame('RPT-EXEC-01', $response->json('data.report_id'));
    }

    // ── B: tenant isolation ──────────────────────────────────────────────────

    public function test_tenant_isolation_excludes_another_companys_orders(): void
    {
        $otherCompany = Company::factory()->create();
        $this->order($this->company, OrderStatus::InProgress, '2026-08-01');
        $this->order($otherCompany, OrderStatus::InProgress, '2026-08-01'); // must never appear

        $userA = $this->userWithPermission('reports.customers.view');

        $response = $this->actingAs($userA)->getJson('/api/reporting/reports/RPT-CUST-01/execute?period_days=365');

        $response->assertOk();
        // Only company A's customer(s) should be counted — company B's customer is invisible.
        $this->assertSame(1, $response->json('data.kpis.MET-CUST-01'));
    }

    // ── C: filters ───────────────────────────────────────────────────────────

    public function test_valid_date_filters_are_accepted(): void
    {
        $user = $this->userWithPermission('reports.sales.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute?date_from=2026-01-01&date_to=2026-01-31');

        $response->assertOk();
        $this->assertSame('2026-01-01', $response->json('data.period.from'));
        $this->assertSame('2026-01-31', $response->json('data.period.to'));
    }

    public function test_invalid_date_filter_is_rejected(): void
    {
        $user = $this->userWithPermission('reports.sales.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute?date_from=not-a-date');

        $response->assertStatus(422);
    }

    public function test_date_from_after_date_to_is_rejected(): void
    {
        $user = $this->userWithPermission('reports.sales.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute?date_from=2026-02-01&date_to=2026-01-01');

        $response->assertStatus(422);
    }

    public function test_date_boundary_is_inclusive(): void
    {
        $user = $this->userWithPermission('reports.sales.view');
        $order = $this->order($this->company, OrderStatus::InProgress, '2026-03-15');
        $this->line($order, 2, 100.0);

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute?date_from=2026-03-15&date_to=2026-03-15');

        $response->assertOk();
        $this->assertEquals(200.0, $response->json('data.kpis.MET-SALES-01'));
    }

    // ── D: source authority ─────────────────────────────────────────────────

    public function test_gross_and_net_sales_match_the_documented_formula_exactly(): void
    {
        $user = $this->userWithPermission('reports.sales.view');
        $order = $this->order($this->company, OrderStatus::InProgress, '2026-04-01');
        $this->line($order, 3, 50.0); // 150 gross
        $order->update(['discount_amount' => 10.0]);
        $order->coupons()->create(['code' => 'SAVE5', 'discount' => 5.0]);
        $order->fees()->create(['name' => 'Handling', 'total' => 2.0]);

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute?date_from=2026-04-01&date_to=2026-04-01');

        $response->assertOk();
        $this->assertEquals(150.0, $response->json('data.kpis.MET-SALES-01'));
        // Net = Gross - discount_amount - coupons + fees = 150 - 10 - 5 + 2 = 137
        $this->assertEquals(137.0, $response->json('data.kpis.MET-SALES-02'));
    }

    public function test_cancelled_orders_are_excluded_from_gross_and_net_sales(): void
    {
        $user = $this->userWithPermission('reports.sales.view');
        $order = $this->order($this->company, OrderStatus::Cancelled, '2026-04-02');
        $this->line($order, 10, 1000.0); // would dominate the total if wrongly included

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute?date_from=2026-04-02&date_to=2026-04-02');

        $response->assertOk();
        $this->assertEquals(0.0, $response->json('data.kpis.MET-SALES-01'));
        $this->assertSame(1, $response->json('data.kpis.MET-SALES-06'));
    }

    public function test_delivered_sales_only_counts_delivered_orders(): void
    {
        $user = $this->userWithPermission('reports.sales.view');
        $delivered = $this->order($this->company, OrderStatus::Delivered, '2026-04-03');
        $this->line($delivered, 1, 300.0);
        $inProgress = $this->order($this->company, OrderStatus::InProgress, '2026-04-03');
        $this->line($inProgress, 1, 999.0);

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute?date_from=2026-04-03&date_to=2026-04-03');

        $response->assertOk();
        $this->assertEquals(300.0, $response->json('data.kpis.MET-SALES-03'));
    }

    public function test_on_time_delivery_reads_distribution_not_a_reconstructed_figure(): void
    {
        $user = $this->userWithPermission('reports.sales.view');

        $onTime = $this->order($this->company, OrderStatus::Delivered, '2026-05-01', requestedDeliveryDate: '2026-05-10');
        $trip = Trip::query()->create(['company_id' => $this->company->id, 'trip_number' => 'T-1', 'name' => 'Trip 1', 'capacity' => 60]);
        DeliveryStop::query()->create(['trip_id' => $trip->id, 'order_id' => $onTime->id, 'status' => DeliveryStopStatus::Delivered->value, 'completed_at' => '2026-05-09 10:00:00', 'sequence' => 1]);

        $late = $this->order($this->company, OrderStatus::Delivered, '2026-05-01', requestedDeliveryDate: '2026-05-10');
        $tripLate = Trip::query()->create(['company_id' => $this->company->id, 'trip_number' => 'T-2', 'name' => 'Trip 2', 'capacity' => 60]);
        DeliveryStop::query()->create(['trip_id' => $tripLate->id, 'order_id' => $late->id, 'status' => DeliveryStopStatus::Delivered->value, 'completed_at' => '2026-05-12 10:00:00', 'sequence' => 1]);

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-04/execute?date_from=2026-05-10&date_to=2026-05-10');

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.totals.delivered_orders_with_requested_date'));
        $this->assertEquals(1, $response->json('data.totals.on_time_count'));
        $this->assertEquals(50.0, $response->json('data.kpis.MET-SALES-10'));
    }

    public function test_customer_blocked_state_is_read_from_the_canonical_block_authority(): void
    {
        $user = $this->userWithPermission('reports.customers.view');
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'phone' => '01099998888']);
        CustomerBlock::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'normalized_phone' => '01099998888',
            'is_active' => true,
            'block_reason' => 'test',
            'blocked_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-CUST-02/execute');

        $response->assertOk();
        $row = collect($response->json('data.rows'))->firstWhere('customer_id', (string) $customer->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['is_blocked']);
    }

    // ── E: read-only guarantee ───────────────────────────────────────────────

    public function test_report_execution_produces_no_domain_mutation(): void
    {
        $user = $this->userWithPermission('reports.sales.view');
        $order = $this->order($this->company, OrderStatus::InProgress, '2026-04-01');
        $this->line($order, 2, 40.0);

        $beforeOrders = DB::table('orders')->count();
        $beforeLines = DB::table('order_lines')->count();
        $beforeCustomers = DB::table('customers')->count();
        $orderUpdatedAt = $order->fresh()->updated_at;

        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute')->assertOk();
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-02/execute?dimension=customer')->assertOk();
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-03/execute')->assertOk();

        $this->assertSame($beforeOrders, DB::table('orders')->count());
        $this->assertSame($beforeLines, DB::table('order_lines')->count());
        $this->assertSame($beforeCustomers, DB::table('customers')->count());
        $this->assertEquals($orderUpdatedAt, $order->fresh()->updated_at);
    }

    // ── F: query behaviour ───────────────────────────────────────────────────

    public function test_customer_360_list_uses_a_bounded_query_count_regardless_of_row_count(): void
    {
        $user = $this->userWithPermission('reports.customers.view');

        Customer::factory()->count(3)->create(['company_id' => $this->company->id]);
        DB::enableQueryLog();
        DB::flushQueryLog(); // discard the factory-creation queries themselves — only the endpoint's own queries are measured
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-CUST-02/execute?per_page=50')->assertOk();
        $queryCountFor3 = count(DB::getQueryLog());

        Customer::factory()->count(12)->create(['company_id' => $this->company->id]);
        DB::flushQueryLog(); // discard the 12 new factory-creation queries before measuring again
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-CUST-02/execute?per_page=50')->assertOk();
        $queryCountFor15 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded: query count must not scale with row count (no per-row N+1) — allow a
        // small fixed slack (e.g. differing pagination internals) rather than asserting
        // exact equality.
        $this->assertLessThanOrEqual($queryCountFor3 + 2, $queryCountFor15, 'Query count grew with row count — suspected N+1.');
    }
}
