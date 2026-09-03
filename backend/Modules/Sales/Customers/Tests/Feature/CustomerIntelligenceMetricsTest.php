<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Infrastructure\Repositories\EloquentCustomerRepository;
use Tests\TestCase;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-CUSTOMER-INTELLIGENCE-008.
 *
 * Proves the new Customer Intelligence metrics — Highest Spend, Repeat Customer,
 * Purchase Frequency/Recency, Product Affinity, and the product-specific repeat-buyer
 * filter — all additive extensions of the existing, approved CustomerOrderMetricsService
 * (see its class docblock, "APPROVED SEMANTICS" / "CUSTOMER INTELLIGENCE" sections).
 *
 * QUALIFYING ORDER SCOPE (deliberately reused, not invented — see report §5): every order
 * belonging to the customer within the tenant counts, exactly like the pre-existing
 * total_order_value/orders_count definition. Cancelled and Returned are NOT excluded —
 * test_total_order_value_includes_cancelled_and_returned_orders_matching_approved_scope
 * proves this is by design, not an oversight.
 */
final class CustomerIntelligenceMetricsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * This suite's minimal schema (see migrateFreshUsing() below) deliberately excludes
     * IAM's roles/permissions tables — not needed by anything this suite tests, all of
     * which calls services/repositories directly rather than through HTTP+middleware.
     * Skips TestCase::actingAs()'s Role::firstOrCreate() query, which would otherwise
     * fail against a schema with no `roles` table.
     */
    protected bool $grantsBaselineAuthorization = false;

    private CustomerOrderMetricsService $metrics;

    private EloquentCustomerRepository $repository;

    private string $companyId;

    /**
     * Test-harness-only: restricts RefreshDatabase's migrate:fresh to exactly the tables
     * this suite needs, instead of the whole application's 700+ migrations — mirrors
     * CustomerCodeSequenceTest's identical override (see TASK-...-VERIFICATION-007-R2's
     * report for why: the unrestricted RefreshDatabase path is infeasible to run to
     * completion in this environment). Changes nothing about what is tested.
     */
    protected function migrateFreshUsing()
    {
        return array_merge([
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ], [
            // Deliberately individual FILES, not whole directories, for Orders/Products/
            // Customers — those directories are 15-37 files each, mostly slow ALTER TABLEs
            // (cost intelligence, manufacturing, reservation, billing/financials) this
            // suite never touches. Cherry-picked to the exact tables/columns these tests
            // actually read or write. See the -CUSTOMER-INTELLIGENCE-008 report §"Test
            // Database Setup" for why the whole-directory approach was abandoned (it did
            // not finish within a 10-minute bound even on a clean, single-process run).
            '--path' => [
                'database/migrations/0001_01_01_000000_create_users_table.php',
                'database/migrations/2026_07_07_000002_add_company_id_to_users_table.php',
                'Modules/Organization/Companies/Infrastructure/Database/Migrations',
                'Modules/MasterData/Units/Infrastructure/Database/Migrations',
                'Modules/MasterData/Categories/Infrastructure/Database/Migrations/2026_06_23_100100_create_categories_table.php',
                // CategoryFactory writes category_scope (not the original `type` column) —
                // needs both the column-add and the rename-to-category_scope migration.
                'database/migrations/2026_07_02_300000_add_type_to_categories_table.php',
                'Modules/MasterData/Categories/Infrastructure/Database/Migrations/2026_07_04_100000_refactor_categories_type_to_scope.php',
                'Modules/Organization/Brands/Infrastructure/Database/Migrations/2026_07_05_140000_create_brands_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_23_110000_create_products_table.php',
                // Chained ->after() dependency for cost_source/can_manufacture/
                // can_disassemble/allow_negative_stock, all written by ProductFactory.
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_23_111000_add_enrichment_fields_to_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_25_230002_add_cost_intelligence_to_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_25_250002_add_current_fifo_cost_to_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_29_000001_add_manufacturing_fields_to_products_table.php',
                // ProductFactory writes company_id and brand_id; both real migrations that
                // add them backfill from product_channel_mappings (out of this suite's
                // minimal schema) — these test-only substitutes are schema-only equivalents.
                'Modules/Sales/Customers/Tests/Support/Migrations/2026_07_06_000001_add_company_id_to_products_table_test_only.php',
                'Modules/Sales/Customers/Tests/Support/Migrations/2026_07_06_100001_migrate_products_to_brand_ownership_test_only.php',
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_170000_create_channels_table.php',
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_600000_add_sync_customers_and_webhook_ids_to_channels.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_200000_create_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_200001_create_order_lines_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_100003_add_manual_order_fields_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_600000_add_delivery_fields_to_orders_table.php',
                // Chained ->after() dependency for google_maps_url, read by
                // CustomerOrderMetricsService::locationUrlForCustomers().
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_200000_add_location_to_orders_table.php',
                'database/migrations/2026_07_14_000001_add_enterprise_address_fields_to_orders.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_06_23_160000_create_customers_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_06_100001_create_customer_addresses_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_08_910001_add_company_id_to_customers_table.php',
                // EloquentCustomerRepository::paginate() eager-loads customerBrands.brand +
                // addresses unconditionally — both tables must exist even though this
                // suite's fixtures never populate them.
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_22_200000_create_customer_brands_table.php',
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->metrics = app(CustomerOrderMetricsService::class);
        $this->repository = new EloquentCustomerRepository;

        $company = Company::factory()->create();
        $this->companyId = (string) $company->id;

        $this->actingAs(User::factory()->create(['company_id' => $this->companyId]));
    }

    // ── HIGHEST SPEND ────────────────────────────────────────────────────────────

    public function test_total_order_value_sums_all_qualifying_orders(): void
    {
        $customer = $this->customer($this->companyId);
        $this->order($customer, $this->companyId, 100.00);
        $this->order($customer, $this->companyId, 200.00);
        $this->order($customer, $this->companyId, 50.00);

        $result = $this->metrics->forCustomers([$customer], $this->companyId);

        $this->assertSame(350.0, $result[$customer]['total_order_value']);
        $this->assertSame(3, $result[$customer]['orders_count']);
    }

    public function test_total_order_value_includes_cancelled_and_returned_orders_matching_approved_scope(): void
    {
        $customer = $this->customer($this->companyId);
        $this->order($customer, $this->companyId, 100.00, status: 'confirmed');
        $this->order($customer, $this->companyId, 50.00, status: 'cancelled');
        $this->order($customer, $this->companyId, 75.00, status: 'returned');

        $result = $this->metrics->forCustomers([$customer], $this->companyId);

        // Deliberate: this is the SAME pre-existing "Total Value = SUM(total) over ALL
        // orders" definition Task 2/CRM already use — not a new exclusion rule.
        $this->assertSame(225.0, $result[$customer]['total_order_value']);
        $this->assertSame(3, $result[$customer]['orders_count']);
    }

    public function test_total_order_value_is_tenant_isolated(): void
    {
        $otherCompany = (string) Company::factory()->create()->id;
        $customer = $this->customer($this->companyId);

        $this->order($customer, $this->companyId, 100.00);
        $this->order($customer, $otherCompany, 9000.00);

        $result = $this->metrics->forCustomers([$customer], $this->companyId);

        $this->assertSame(100.0, $result[$customer]['total_order_value']);
        $this->assertSame(1, $result[$customer]['orders_count']);
    }

    // ── REPEAT CUSTOMER ──────────────────────────────────────────────────────────

    public function test_customer_with_zero_orders_is_not_repeat(): void
    {
        $customer = $this->customer($this->companyId);

        $result = $this->metrics->forCustomer($customer, $this->companyId);

        $this->assertFalse($result['is_repeat_customer']);
    }

    public function test_customer_with_one_order_is_not_repeat(): void
    {
        $customer = $this->customer($this->companyId);
        $this->order($customer, $this->companyId, 100.00);

        $result = $this->metrics->forCustomer($customer, $this->companyId);

        $this->assertFalse($result['is_repeat_customer']);
    }

    public function test_customer_with_two_or_more_orders_is_repeat(): void
    {
        $customer = $this->customer($this->companyId);
        $this->order($customer, $this->companyId, 100.00);
        $this->order($customer, $this->companyId, 100.00);

        $result = $this->metrics->forCustomer($customer, $this->companyId);

        $this->assertTrue($result['is_repeat_customer']);
        $this->assertSame(CustomerOrderMetricsService::REPEAT_ORDER_THRESHOLD, 2);
    }

    // ── RECENCY / FREQUENCY ──────────────────────────────────────────────────────

    public function test_first_and_last_order_dates_are_correct(): void
    {
        $customer = $this->customer($this->companyId);
        $this->order($customer, $this->companyId, 100.00, orderDate: '2026-01-01');
        $this->order($customer, $this->companyId, 100.00, orderDate: '2026-01-15');
        $this->order($customer, $this->companyId, 100.00, orderDate: '2026-01-08');

        $result = $this->metrics->forCustomer($customer, $this->companyId);

        $this->assertSame('2026-01-01', $result['first_order_at']);
        $this->assertSame('2026-01-15', $result['last_order_at']);
    }

    /**
     * Concrete worked example: 3 qualifying orders spanning day 0 -> day 20 (20 days
     * total) means 2 intervals between them, so the average is 20 / (3-1) = 10.0 days —
     * regardless of exactly where the middle order falls.
     */
    public function test_avg_days_between_orders_formula_is_correct(): void
    {
        $customer = $this->customer($this->companyId);
        $this->order($customer, $this->companyId, 100.00, orderDate: '2026-01-01');
        $this->order($customer, $this->companyId, 100.00, orderDate: '2026-01-11');
        $this->order($customer, $this->companyId, 100.00, orderDate: '2026-01-21');

        $result = $this->metrics->forCustomer($customer, $this->companyId);

        $this->assertSame(10.0, $result['avg_days_between_orders']);
    }

    public function test_avg_days_between_orders_is_null_for_zero_orders(): void
    {
        $customer = $this->customer($this->companyId);

        $result = $this->metrics->forCustomer($customer, $this->companyId);

        $this->assertNull($result['avg_days_between_orders']);
    }

    /**
     * The exact "no divide-by-zero / single-order bug" case §29 requires: exactly one
     * qualifying order means (orders - 1) would be 0 if the guard were missing.
     */
    public function test_avg_days_between_orders_is_null_for_a_single_order(): void
    {
        $customer = $this->customer($this->companyId);
        $this->order($customer, $this->companyId, 100.00, orderDate: '2026-01-01');

        $result = $this->metrics->forCustomer($customer, $this->companyId);

        $this->assertNull($result['avg_days_between_orders']);
    }

    // ── PRODUCT AFFINITY ─────────────────────────────────────────────────────────

    /**
     * The core correctness property: "repeatedly purchase" means bought across separate
     * orders, not bought in bulk once. Product A: 1 order, 50 units. Product B: 3 separate
     * orders, 5 units each (15 total). B must rank ABOVE A despite far fewer total units.
     */
    public function test_product_repeated_across_orders_ranks_above_single_bulk_order(): void
    {
        $customer = $this->customer($this->companyId);
        $productA = (string) Product::factory()->create()->id;
        $productB = (string) Product::factory()->create()->id;

        $orderBulk = $this->order($customer, $this->companyId, 500.00);
        $this->orderLine($orderBulk, $productA, 50);

        foreach (range(1, 3) as $_) {
            $order = $this->order($customer, $this->companyId, 50.00);
            $this->orderLine($order, $productB, 5);
        }

        $result = $this->metrics->topProductsForCustomers([$customer], $this->companyId);
        $top = $result[$customer]['top'];

        $this->assertSame($productB, $top[0]['product_id']);
        $this->assertSame(3, $top[0]['orders_count']);
        $this->assertSame($productA, $top[1]['product_id']);
        $this->assertSame(1, $top[1]['orders_count']);

        // purchasedProducts() (the detail-view sibling) must agree.
        $detail = $this->metrics->purchasedProducts($customer, $this->companyId);
        $this->assertSame($productB, $detail[0]['product_id']);
        $this->assertSame($productA, $detail[1]['product_id']);
    }

    public function test_multiple_products_ranked_by_orders_count_desc_then_quantity_desc(): void
    {
        $customer = $this->customer($this->companyId);
        $productHighAffinity = (string) Product::factory()->create()->id;
        $productLowAffinity = (string) Product::factory()->create()->id;

        foreach (range(1, 4) as $_) {
            $order = $this->order($customer, $this->companyId, 10.00);
            $this->orderLine($order, $productHighAffinity, 1);
        }
        foreach (range(1, 2) as $_) {
            $order = $this->order($customer, $this->companyId, 10.00);
            $this->orderLine($order, $productLowAffinity, 1);
        }

        $result = $this->metrics->topProductsForCustomers([$customer], $this->companyId, limit: 5);
        $top = $result[$customer]['top'];

        $this->assertSame($productHighAffinity, $top[0]['product_id']);
        $this->assertSame(4, $top[0]['orders_count']);
        $this->assertSame($productLowAffinity, $top[1]['product_id']);
        $this->assertSame(2, $top[1]['orders_count']);
        $this->assertSame(2, $result[$customer]['distinct_count']);
    }

    public function test_unrelated_customer_orders_excluded_from_affinity(): void
    {
        $customerA = $this->customer($this->companyId);
        $customerB = $this->customer($this->companyId);
        $product = (string) Product::factory()->create()->id;

        $orderA = $this->order($customerA, $this->companyId, 10.00);
        $this->orderLine($orderA, $product, 1);

        foreach (range(1, 5) as $_) {
            $orderB = $this->order($customerB, $this->companyId, 10.00);
            $this->orderLine($orderB, $product, 1);
        }

        $result = $this->metrics->topProductsForCustomers([$customerA], $this->companyId);

        $this->assertSame(1, $result[$customerA]['top'][0]['orders_count']);
    }

    public function test_cross_company_data_excluded_from_affinity(): void
    {
        $otherCompany = (string) Company::factory()->create()->id;
        $customer = $this->customer($this->companyId);
        $product = (string) Product::factory()->create()->id;

        $order = $this->order($customer, $this->companyId, 10.00);
        $this->orderLine($order, $product, 1);

        // Same customer_id value coincidentally used by another company's order — must
        // never leak in (mirrors the tenant-isolation pattern in the sibling metrics tests).
        $foreignOrder = $this->order($customer, $otherCompany, 10.00);
        $this->orderLine($foreignOrder, $product, 99);

        $result = $this->metrics->topProductsForCustomers([$customer], $this->companyId);

        $this->assertSame(1, $result[$customer]['top'][0]['orders_count']);
        $this->assertSame(1.0, $result[$customer]['top'][0]['total_quantity']);
    }

    // ── PRODUCT-SPECIFIC CUSTOMER FILTER ─────────────────────────────────────────

    public function test_product_repeat_filter_returns_customers_meeting_threshold(): void
    {
        $product = (string) Product::factory()->create()->id;

        $repeatBuyer = $this->customer($this->companyId, 'Repeat Buyer');
        foreach (range(1, 2) as $_) {
            $order = $this->order($repeatBuyer, $this->companyId, 10.00);
            $this->orderLine($order, $product, 1);
        }

        $oneTimeBuyer = $this->customer($this->companyId, 'One Time Buyer');
        $order = $this->order($oneTimeBuyer, $this->companyId, 10.00);
        $this->orderLine($order, $product, 1);

        $neverBought = $this->customer($this->companyId, 'Never Bought');

        $page = $this->repository->paginate([
            'company_id' => $this->companyId,
            'product_id' => $product,
            'min_purchase_count' => 2,
        ]);

        $ids = collect($page->items())->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->assertContains($repeatBuyer, $ids);
        $this->assertNotContains($oneTimeBuyer, $ids);
        $this->assertNotContains($neverBought, $ids);
    }

    public function test_product_filter_excludes_one_time_buyer_when_threshold_is_two(): void
    {
        $product = (string) Product::factory()->create()->id;
        $oneTimeBuyer = $this->customer($this->companyId);
        $order = $this->order($oneTimeBuyer, $this->companyId, 10.00);
        $this->orderLine($order, $product, 1);

        // Default threshold (no min_purchase_count given) must fall back to
        // REPEAT_ORDER_THRESHOLD (2) — the same "repeat" definition everywhere.
        $page = $this->repository->paginate([
            'company_id' => $this->companyId,
            'product_id' => $product,
        ]);

        $ids = collect($page->items())->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->assertNotContains($oneTimeBuyer, $ids);
    }

    public function test_repeat_only_filter_returns_only_repeat_customers(): void
    {
        $repeat = $this->customer($this->companyId, 'Repeat');
        $this->order($repeat, $this->companyId, 10.00);
        $this->order($repeat, $this->companyId, 10.00);

        $single = $this->customer($this->companyId, 'Single Order');
        $this->order($single, $this->companyId, 10.00);

        $page = $this->repository->paginate([
            'company_id' => $this->companyId,
            'repeat_only' => true,
        ]);

        $ids = collect($page->items())->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->assertContains($repeat, $ids);
        $this->assertNotContains($single, $ids);
    }

    // ── PERFORMANCE / N+1 EVIDENCE ───────────────────────────────────────────────

    /**
     * The page's total query count must be bounded by distinct companies present, never
     * by row count (customers, orders, or order_lines). Six customers here, several with
     * multiple orders and products each, still cost the same handful of queries the
     * class docblock documents (paginate + 2 eager loads + 5 metrics calls, all scoped
     * to ONE company group) — never one query per customer.
     */
    /**
     * Replicates CustomerController::index()'s own query sequence directly (repository
     * paginate() + the five batched CustomerOrderMetricsService calls for one company
     * group) rather than going through HTTP — this suite's minimal schema intentionally
     * has no IAM roles/permissions tables, so a real routed+authenticated request isn't
     * available here. The query-count property under test (bounded by company groups,
     * never by row count) lives entirely in that sequence, not in the HTTP/JSON layer.
     */
    public function test_index_query_sequence_is_bounded_not_per_customer(): void
    {
        $product = (string) Product::factory()->create()->id;
        $ids = [];

        foreach (range(1, 6) as $i) {
            $customer = $this->customer($this->companyId, "Customer {$i}");
            $ids[] = $customer;
            foreach (range(1, 3) as $_) {
                $order = $this->order($customer, $this->companyId, 25.00);
                $this->orderLine($order, $product, 2);
            }
        }

        DB::enableQueryLog();

        $page = $this->repository->paginate(['company_id' => $this->companyId, 'per_page' => 20]);
        $this->metrics->forCustomers($ids, $this->companyId);
        $this->metrics->topProductsForCustomers($ids, $this->companyId);
        $this->metrics->locationUrlForCustomers($ids, $this->companyId);
        $this->metrics->preferredGovernorateForCustomers($ids, $this->companyId);
        $this->metrics->channelsForCustomers($ids, $this->companyId);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(6, $page->items());
        // Generous fixed ceiling (not proportional to the 6 customers / 18 orders / 18
        // order_lines rows just created) — proves the query count is bounded, not per-row:
        // 1 paginate (with its subquery-based count) + 2 eager loads + 5 metrics calls.
        $this->assertLessThanOrEqual(10, $queryCount, "expected a bounded query count, got {$queryCount}");
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    private function customer(string $companyId, string $name = 'Test Customer'): string
    {
        return (string) Customer::query()->create([
            'company_id' => $companyId,
            'code' => 'CUS-'.Str::upper(Str::random(8)),
            'name' => $name,
            'is_active' => true,
        ])->id;
    }

    private function order(
        string $customerId,
        string $companyId,
        float $total,
        string $status = 'confirmed',
        ?string $orderDate = null,
    ): string {
        $id = (string) Str::uuid7();

        DB::table('orders')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'order_number' => 'ORD-'.Str::upper(Str::random(10)),
            'status' => $status,
            'total' => $total,
            'order_date' => $orderDate ?? now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function orderLine(string $orderId, string $productId, int $quantity): void
    {
        DB::table('order_lines')->insert([
            'id' => (string) Str::uuid7(),
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_price' => 10.00,
            'line_total' => $quantity * 10.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
