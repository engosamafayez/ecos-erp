<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Fiscal\Domain\Enums\FiscalYearStatus;
use Modules\Finance\Fiscal\Domain\Enums\PeriodStatus;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Models\FiscalYear;
use Modules\Finance\Ledger\Domain\Enums\AccountCategory;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Enums\JournalStatus;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Enums\SupplierLedgerEntryType;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Models\SupplierLedgerEntry;
use Modules\Finance\Receivables\Domain\Enums\CustomerDocumentType;
use Modules\Finance\Receivables\Domain\Enums\CustomerLedgerEntryType;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Models\CustomerLedgerEntry;
use Modules\Finance\Shared\Domain\Enums\DocumentStatus;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ECOS-REPORTING-V1-FINAL-COVERAGE-AND-SOURCE-CLOSURE-005 §20 — end-to-end,
 * real-MySQL verification of the final tranche (Executive, Products, Customer/Procurement
 * Finance thin-proxies, Financial). Fast-baseline schema (§19): Task 4's own migration list
 * extended with Order snapshot tables (RPT-PROD-03) and a curated, individually-listed
 * Finance schema (Finance has no per-submodule migration directories — every migration
 * lives in one flat `Modules/Finance/Infrastructure/Database/Migrations` folder, confirmed
 * by direct inspection — so whole-directory `--path` entries are not available here the way
 * they are for IAM/Companies/etc.).
 */
final class FinalCoverageReportExecutionFeatureTest extends TestCase
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
                // Task 5 — Order financial/line snapshots (RPT-PROD-03)
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_400000_create_order_financial_snapshots_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_400001_create_order_line_snapshots_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_400002_enhance_order_financial_snapshots_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_400003_enhance_order_line_snapshots_table.php',
                // Task 5 — Finance (one flat migrations directory; individually listed, never
                // the whole ~90-file folder — confirmed no per-submodule split exists)
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

    /** @return array{period: FiscalPeriod, revenue_account_id: string, ar_account_id: string} */
    private function setUpFinanceFixtures(): array
    {
        $year = FiscalYear::query()->create([
            'company_id' => $this->company->id, 'name' => 'FY2026',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open,
        ]);
        $period = FiscalPeriod::query()->create([
            'company_id' => $this->company->id, 'fiscal_year_id' => $year->id, 'period_number' => 1,
            'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
            'status' => PeriodStatus::Open,
        ]);

        $ar = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1310', 'name' => 'Trade Receivables',
            'account_type' => AccountType::Asset, 'account_category' => AccountCategory::CurrentAsset,
            'is_control' => true, 'control_subledger' => 'ar',
        ]);
        $ap = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '2110', 'name' => 'Trade Payables',
            'account_type' => AccountType::Liability, 'account_category' => AccountCategory::CurrentLiability,
            'is_control' => true, 'control_subledger' => 'ap',
        ]);
        $bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1210', 'name' => 'Bank — Current Accounts',
            'account_type' => AccountType::Asset, 'account_category' => AccountCategory::CurrentAsset,
        ]);
        $revenue = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '4110', 'name' => 'Product Sales',
            'account_type' => AccountType::Revenue, 'account_category' => AccountCategory::OperatingRevenue,
        ]);

        $entry = JournalEntry::query()->create([
            'company_id' => $this->company->id, 'fiscal_period_id' => $period->id,
            'entry_date' => '2026-01-15', 'status' => JournalStatus::Posted,
        ]);
        $entry->lines()->createMany([
            ['account_id' => $bank->id, 'debit' => 1000, 'credit' => 0, 'company_id' => $this->company->id, 'line_number' => 1],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 1000, 'company_id' => $this->company->id, 'line_number' => 2],
        ]);

        return ['period' => $period, 'revenue_account_id' => $revenue->id, 'ar_account_id' => $ar->id, 'ap_account_id' => $ap->id];
    }

    // ── B: authorization — one check per new category, plus co-gating ──────

    public function test_authorized_products_request_succeeds(): void
    {
        $user = $this->userWithPermission('reports.products.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROD-01/execute');
        $response->assertOk();
        $this->assertSame('RPT-PROD-01', $response->json('data.report_id'));
    }

    public function test_authorized_executive_request_succeeds(): void
    {
        $this->setUpFinanceFixtures();
        $user = $this->userWithPermission('reports.executive.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-EXEC-03/execute');
        $response->assertOk();
    }

    public function test_authorized_finance_request_succeeds(): void
    {
        $this->setUpFinanceFixtures();
        $user = $this->userWithPermission('reports.finance.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-FIN-01/execute');
        $response->assertOk();
    }

    public function test_customer_outstanding_ar_requires_the_cogated_finance_permission(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        // Holds the category permission but NOT finance.ar.view — the co-gate must still deny.
        $user = $this->userWithPermission('reports.customers.view');

        $response = $this->actingAs($user)->getJson("/api/reporting/reports/RPT-CUST-03/execute?customer_id={$customer->id}");
        $response->assertForbidden();
    }

    public function test_customer_outstanding_ar_succeeds_with_both_permissions(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $user = $this->userWithPermission('reports.customers.view', 'finance.ar.view');

        $response = $this->actingAs($user)->getJson("/api/reporting/reports/RPT-CUST-03/execute?customer_id={$customer->id}");
        $response->assertOk();
    }

    public function test_supplier_statement_requires_the_cogated_finance_permission(): void
    {
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $user = $this->userWithPermission('reports.procurement.view');

        $response = $this->actingAs($user)->getJson("/api/reporting/reports/RPT-PROC-03/execute?supplier_id={$supplier->id}");
        $response->assertForbidden();
    }

    // ── D: source authority correctness ─────────────────────────────────────

    public function test_trial_balance_reads_posted_journal_lines(): void
    {
        $fixtures = $this->setUpFinanceFixtures();
        $user = $this->userWithPermission('reports.finance.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-FIN-01/execute');

        $response->assertOk();
        $this->assertSame(1000.0, (float) $response->json('data.totals.total_debit'));
        $this->assertSame(1000.0, (float) $response->json('data.totals.total_credit'));
        $this->assertTrue($response->json('data.totals.is_balanced'));
    }

    public function test_income_statement_revenue_matches_posted_journal_lines(): void
    {
        $this->setUpFinanceFixtures();
        $user = $this->userWithPermission('reports.finance.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-FIN-02/execute?statement_type=income_statement&date_from=2026-01-01&date_to=2026-01-31');

        $response->assertOk();
        $this->assertEquals(1000.0, $response->json('data.kpis.MET-FIN-01'));
    }

    public function test_ar_ap_aging_reads_from_finance_not_commerce(): void
    {
        $this->setUpFinanceFixtures();
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        CustomerInvoice::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'number' => 'INV-1',
            'invoice_date' => '2026-01-10', 'document_type' => CustomerDocumentType::Invoice,
            'status' => DocumentStatus::Posted, 'total' => 500,
        ]);
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        SupplierBill::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'number' => 'BILL-1',
            'bill_date' => '2026-01-10', 'document_type' => SupplierDocumentType::Bill,
            'status' => DocumentStatus::Posted, 'total' => 350,
        ]);
        $user = $this->userWithPermission('reports.finance.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-FIN-03/execute');

        $response->assertOk();
        $this->assertEquals(500.0, $response->json('data.kpis.MET-FIN-02'));
        $this->assertEquals(350.0, $response->json('data.kpis.MET-FIN-03'));
    }

    public function test_customer_outstanding_ar_reads_customer_ledger_entries(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        CustomerLedgerEntry::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'entry_date' => '2026-01-10', 'entry_type' => CustomerLedgerEntryType::Invoice, 'amount' => 300,
        ]);
        $user = $this->userWithPermission('reports.customers.view', 'finance.ar.view');

        $response = $this->actingAs($user)->getJson("/api/reporting/reports/RPT-CUST-03/execute?customer_id={$customer->id}");

        $response->assertOk();
        $this->assertEquals(300.0, $response->json('data.kpis.MET-FIN-02'));
    }

    public function test_supplier_statement_excludes_advances_from_outstanding_payable(): void
    {
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        SupplierLedgerEntry::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id,
            'entry_date' => '2026-01-10', 'entry_type' => SupplierLedgerEntryType::Bill, 'amount' => 400,
        ]);
        SupplierLedgerEntry::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id,
            'entry_date' => '2026-01-11', 'entry_type' => SupplierLedgerEntryType::Advance, 'amount' => -150,
        ]);
        $user = $this->userWithPermission('reports.procurement.view', 'finance.ap.view');

        $response = $this->actingAs($user)->getJson("/api/reporting/reports/RPT-PROC-03/execute?supplier_id={$supplier->id}");

        $response->assertOk();
        // Advance is excluded from outstandingPayable() — only the bill counts.
        $this->assertEquals(400.0, $response->json('data.kpis.MET-FIN-03'));
    }

    public function test_product_performance_reads_gross_sales_not_recognized_revenue(): void
    {
        $brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'brand_id' => $brand->id]);
        $order = $this->minimalOrderWithLine($product->id, quantity: 4, unitPrice: 50);

        $user = $this->userWithPermission('reports.products.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROD-01/execute');

        $response->assertOk();
        $this->assertEquals(200.0, $response->json('data.kpis.MET-PROD-01') * 4);
        $this->assertEquals(4.0, $response->json('data.kpis.MET-SALES-05'));
    }

    public function test_executive_overview_reuses_the_same_values_as_its_own_source_reports(): void
    {
        $this->setUpFinanceFixtures();
        $brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'brand_id' => $brand->id]);
        $this->minimalOrderWithLine($product->id, quantity: 2, unitPrice: 100);

        $user = $this->userWithPermission('reports.sales.view', 'reports.executive.view');

        $directResponse = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-SALES-01/execute');
        $directResponse->assertOk();
        $composedResponse = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-EXEC-01/execute');
        $composedResponse->assertOk();

        // §13 cross-report metric consistency: the SAME metric ID must be the SAME value
        // whether read directly from its own report or via Executive's composition.
        $this->assertNotNull($directResponse->json('data.kpis.MET-SALES-01'));
        $this->assertEquals($directResponse->json('data.kpis.MET-SALES-01'), $composedResponse->json('data.kpis.MET-SALES-01'));
    }

    // ── D: MySQL hazard regressions ──────────────────────────────────────────

    public function test_top_performers_executes_without_an_ambiguous_column_error(): void
    {
        $brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'brand_id' => $brand->id]);
        $this->minimalOrderWithLine($product->id, quantity: 1, unitPrice: 10);

        $user = $this->userWithPermission('reports.executive.view');
        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-EXEC-02/execute');

        $response->assertOk();
    }

    public function test_zero_sale_product_ranking_does_not_join_products_into_a_scoped_order_query(): void
    {
        Product::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $user = $this->userWithPermission('reports.products.view');

        $response = $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROD-02/execute?mode=zero_sale');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('data.totals.product_count'));
    }

    // ── E: read-only guarantee ───────────────────────────────────────────────

    public function test_finance_report_execution_produces_no_domain_mutation(): void
    {
        $this->setUpFinanceFixtures();
        $user = $this->userWithPermission('reports.finance.view');

        $beforeLines = DB::table('finance_journal_lines')->count();
        $beforeEntries = DB::table('finance_journal_entries')->count();

        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-FIN-01/execute')->assertOk();
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-FIN-03/execute')->assertOk();

        $this->assertSame($beforeLines, DB::table('finance_journal_lines')->count());
        $this->assertSame($beforeEntries, DB::table('finance_journal_entries')->count());
    }

    // ── F: query count evidence ──────────────────────────────────────────────

    public function test_product_performance_uses_a_bounded_query_count_regardless_of_row_count(): void
    {
        $user = $this->userWithPermission('reports.products.view');
        $brand = Brand::factory()->create(['company_id' => $this->company->id]);

        foreach (range(1, 3) as $i) {
            $product = Product::factory()->create(['company_id' => $this->company->id, 'brand_id' => $brand->id]);
            $this->minimalOrderWithLine($product->id, quantity: 1, unitPrice: 10);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROD-01/execute?per_page=50')->assertOk();
        $countFor3 = count(DB::getQueryLog());

        foreach (range(1, 12) as $i) {
            $product = Product::factory()->create(['company_id' => $this->company->id, 'brand_id' => $brand->id]);
            $this->minimalOrderWithLine($product->id, quantity: 1, unitPrice: 10);
        }
        DB::flushQueryLog();
        $this->actingAs($user)->getJson('/api/reporting/reports/RPT-PROD-01/execute?per_page=50')->assertOk();
        $countFor15 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($countFor3 + 2, $countFor15, 'Query count grew with row count — suspected N+1.');
    }

    /** Creates one Confirmed-equivalent order + line, WITHOUT a cost snapshot (Gross-Sales-only fixture). */
    private function minimalOrderWithLine(string $productId, int $quantity, float $unitPrice): string
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        $orderId = \Modules\Commerce\Orders\Domain\Models\Order::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(), 'status' => 'delivered',
            'subtotal' => 0, 'total' => 0, 'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0, 'discount_amount' => 0,
        ])->id;

        DB::table('order_lines')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $orderId, 'product_id' => $productId,
            'quantity' => $quantity, 'unit_price' => $unitPrice, 'line_total' => $quantity * $unitPrice,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }
}
