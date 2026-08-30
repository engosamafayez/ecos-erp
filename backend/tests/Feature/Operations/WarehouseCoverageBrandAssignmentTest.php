<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Admin\Configuration\Domain\Models\MasterGovernorate;
use Modules\Admin\Configuration\Domain\Models\MasterZone;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\MasterData\Warehouses\Domain\Models\WarehouseBrandCoverage;
use Modules\Operations\Preparation\Application\Services\BranchAssignmentEngine;
use Modules\Operations\Preparation\Domain\Enums\WarehouseAssignmentSource;
use Modules\Organization\Branches\Domain\Models\Branch;
use Modules\Organization\Branches\Domain\Models\BranchCoverageArea;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-WAREHOUSE-COVERAGE-BRAND-ASSIGNMENT-001 — runtime matrix.
 *
 * Every test drives the REAL pipeline:
 *   real coverage rows → real CoverageResolutionService → real BranchAssignmentEngine
 *
 * No test sets `orders.assigned_warehouse_id` directly, and none inserts an
 * assignment result or a reservation row. The assertion is always on what the
 * engine decided, never on a value the test planted.
 */
class WarehouseCoverageBrandAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private MasterGovernorate $cairo;

    private MasterZone $nasrCity;

    private MasterZone $maadi;

    private Customer $customer;

    private BranchAssignmentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->engine = app(BranchAssignmentEngine::class);

        $this->cairo = MasterGovernorate::create([
            'name' => 'Cairo',
            'name_ar' => 'القاهرة',
            'code' => 'C'.substr(uniqid(), -7),
            'is_active' => true,
        ]);

        $this->nasrCity = MasterZone::create([
            'master_governorate_id' => $this->cairo->id,
            'name' => 'Nasr City',
            'code' => 'NC'.substr(uniqid(), -8),
            'is_active' => true,
        ]);

        $this->maadi = MasterZone::create([
            'master_governorate_id' => $this->cairo->id,
            'name' => 'Maadi',
            'code' => 'MD'.substr(uniqid(), -8),
            'is_active' => true,
        ]);
    }

    // ── Fixture helpers (configuration only — never an assignment result) ─────

    private function makeWarehouse(?Company $company = null): Warehouse
    {
        return Warehouse::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
            'is_active' => true,
        ]);
    }

    private function makeBrand(?Company $company = null): Brand
    {
        return Brand::create([
            'company_id' => ($company ?? $this->company)->id,
            'code' => 'BR'.substr(uniqid(), -8),
            'name' => 'Brand '.uniqid(),
            'slug' => 'brand-'.uniqid(),
            'is_active' => true,
        ]);
    }

    /** Branch that owns $warehouse and covers ($governorate, $zone). */
    private function makeCoveredBranch(
        Warehouse $warehouse,
        ?MasterZone $zone,
        int $priority = 100,
        ?Company $company = null,
        ?float $lat = null,
        ?float $lng = null,
    ): Branch {
        $branch = Branch::create([
            'company_id' => ($company ?? $this->company)->id,
            'code' => 'BR-'.uniqid(),
            'name' => 'Branch '.uniqid(),
            'default_warehouse_id' => $warehouse->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'is_active' => true,
        ]);

        BranchCoverageArea::create([
            'branch_id' => $branch->id,
            'master_governorate_id' => $this->cairo->id,
            'master_zone_id' => $zone?->id,
            'priority' => $priority,
            'is_active' => true,
        ]);

        return $branch;
    }

    private function serveBrand(Warehouse $warehouse, Brand $brand, ?Company $company = null): void
    {
        WarehouseBrandCoverage::create([
            'company_id' => ($company ?? $this->company)->id,
            'warehouse_id' => $warehouse->id,
            'brand_id' => $brand->id,
            'is_active' => true,
        ]);
    }

    /** @param list<Brand> $brands */
    private function makeOrder(array $brands, ?string $area = 'Nasr City', ?Company $company = null, ?string $governorate = 'Cairo'): Order
    {
        $order = Order::query()->create([
            'company_id' => ($company ?? $this->company)->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-WBC-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => 'in_progress',
            'subtotal' => 0,
            'total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'governorate' => $governorate,
            'area' => $area,
        ]);

        foreach ($brands as $brand) {
            $product = Product::factory()->create(['brand_id' => $brand->id]);

            OrderLine::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 10,
                'line_total' => 10,
            ]);
        }

        return $order->refresh();
    }

    // ── TEST 1 — governorate + zone + brand all match → assigned ──────────────

    public function test_1_governorate_zone_and_brand_all_match_assigns_warehouse(): void
    {
        $warehouse = $this->makeWarehouse();
        $brand = $this->makeBrand();
        $this->makeCoveredBranch($warehouse, $this->nasrCity);
        $this->serveBrand($warehouse, $brand);

        $order = $this->makeOrder([$brand]);
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertSame($warehouse->id, $order->assigned_warehouse_id);
        $this->assertSame(WarehouseAssignmentSource::BranchCoverage->value, $order->warehouse_assignment_source);
    }

    // ── TEST 2 — wrong zone → NOT assigned ────────────────────────────────────

    public function test_2_wrong_zone_is_not_eligible_even_with_correct_brand(): void
    {
        $warehouse = $this->makeWarehouse();
        $brand = $this->makeBrand();
        // Branch covers Maadi only; the order is for Nasr City.
        $this->makeCoveredBranch($warehouse, $this->maadi);
        $this->serveBrand($warehouse, $brand);

        $order = $this->makeOrder([$brand], area: 'Nasr City');
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertNull($order->assigned_warehouse_id);
        $this->assertSame(WarehouseAssignmentSource::NoBranchCoverage->value, $order->warehouse_assignment_source);
    }

    // ── TEST 3 / PART 23 NEGATIVE REGRESSION — wrong brand → NOT assigned ─────

    public function test_3_correct_geography_but_wrong_brand_is_not_assigned(): void
    {
        $warehouse = $this->makeWarehouse();
        $servedBrand = $this->makeBrand();
        $orderedBrand = $this->makeBrand();

        $this->makeCoveredBranch($warehouse, $this->nasrCity);
        $this->serveBrand($warehouse, $servedBrand);   // serves Brand A only

        $order = $this->makeOrder([$orderedBrand]);    // order is Brand B
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        // This is the regression guard: geography alone must NEVER assign.
        $this->assertNull(
            $order->assigned_warehouse_id,
            'Geography+zone matched but the brand did not — assignment must be refused.',
        );
        $this->assertSame('No Warehouse Serves Order Brands', $order->warehouse_assignment_failure_reason);
    }

    // ── TEST 4 — multi-brand, warehouse serves ALL → assigned ─────────────────

    public function test_4_multi_brand_order_assigned_when_warehouse_serves_all_brands(): void
    {
        $warehouse = $this->makeWarehouse();
        $brandA = $this->makeBrand();
        $brandB = $this->makeBrand();

        $this->makeCoveredBranch($warehouse, $this->nasrCity);
        $this->serveBrand($warehouse, $brandA);
        $this->serveBrand($warehouse, $brandB);

        $order = $this->makeOrder([$brandA, $brandB]);
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertSame($warehouse->id, $order->assigned_warehouse_id);
    }

    // ── TEST 5 — multi-brand, warehouse serves a SUBSET → NOT eligible ────────

    public function test_5_multi_brand_order_refused_when_warehouse_serves_only_some_brands(): void
    {
        $warehouse = $this->makeWarehouse();
        $brandA = $this->makeBrand();
        $brandB = $this->makeBrand();

        $this->makeCoveredBranch($warehouse, $this->nasrCity);
        $this->serveBrand($warehouse, $brandA);   // B deliberately not served

        $order = $this->makeOrder([$brandA, $brandB]);
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertNull($order->assigned_warehouse_id);
        $this->assertSame('No Warehouse Serves Order Brands', $order->warehouse_assignment_failure_reason);
        // And no order splitting happened.
        $this->assertNull($order->assigned_branch_id);
    }

    // ── TEST 6 — two geo-eligible, only one brand-compatible → that one wins ──

    public function test_6_brand_compatible_warehouse_selected_over_geographically_nearer_one(): void
    {
        $wrongBrandWarehouse = $this->makeWarehouse();
        $rightBrandWarehouse = $this->makeWarehouse();

        $orderedBrand = $this->makeBrand();
        $otherBrand = $this->makeBrand();

        // The brand-incompatible branch is given the BETTER priority on purpose:
        // if brand filtering ran after ranking, this one would win and then fail.
        $this->makeCoveredBranch($wrongBrandWarehouse, $this->nasrCity, priority: 1);
        $this->makeCoveredBranch($rightBrandWarehouse, $this->nasrCity, priority: 500);

        $this->serveBrand($wrongBrandWarehouse, $otherBrand);
        $this->serveBrand($rightBrandWarehouse, $orderedBrand);

        $order = $this->makeOrder([$orderedBrand]);
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertSame($rightBrandWarehouse->id, $order->assigned_warehouse_id);
    }

    // ── TEST 7 — several fully eligible → EXISTING priority rule decides ──────

    public function test_7_existing_priority_rule_decides_between_eligible_warehouses(): void
    {
        $preferred = $this->makeWarehouse();
        $other = $this->makeWarehouse();
        $brand = $this->makeBrand();

        $this->makeCoveredBranch($preferred, $this->nasrCity, priority: 10);
        $this->makeCoveredBranch($other, $this->nasrCity, priority: 900);

        $this->serveBrand($preferred, $brand);
        $this->serveBrand($other, $brand);

        $order = $this->makeOrder([$brand]);
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        // No new ranking formula — priority ASC still governs.
        $this->assertSame($preferred->id, $order->assigned_warehouse_id);
    }

    // ── TEST 8 — nothing eligible → no invalid assignment ────────────────────

    public function test_8_no_eligible_warehouse_produces_no_assignment_and_no_status_change(): void
    {
        $warehouse = $this->makeWarehouse();
        $brand = $this->makeBrand();
        $this->makeCoveredBranch($warehouse, $this->nasrCity);
        // NO brand coverage row at all → serves NO brands.

        $order = $this->makeOrder([$brand]);
        $before = $order->status->value;

        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertNull($order->assigned_warehouse_id);
        $this->assertNull($order->assigned_branch_id);
        // The keystone rule from TASK-ORDER-PREPARATION-FLOW-REPAIR-001 must hold:
        // an unresolved warehouse is NOT awaiting stock.
        $this->assertSame($before, $order->status->value);
        $this->assertNotSame('awaiting_stock', $order->status->value);
    }

    // ── TEST 9 — cross-tenant → DENY ─────────────────────────────────────────

    public function test_9_company_a_order_never_selects_company_b_warehouse(): void
    {
        $otherCompany = Company::factory()->create();
        $foreignWarehouse = $this->makeWarehouse($otherCompany);
        $brand = $this->makeBrand();

        // Foreign branch covers the exact same area, and its warehouse is even
        // configured to serve the ordered brand.
        $this->makeCoveredBranch($foreignWarehouse, $this->nasrCity, company: $otherCompany);
        WarehouseBrandCoverage::create([
            'company_id' => $otherCompany->id,
            'warehouse_id' => $foreignWarehouse->id,
            'brand_id' => $brand->id,
            'is_active' => true,
        ]);

        $order = $this->makeOrder([$brand]);
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertNull($order->assigned_warehouse_id, 'Cross-tenant warehouse must never be assigned.');
    }

    // ── NO ROWS = SERVES NO BRANDS (contract §2) ─────────────────────────────

    public function test_warehouse_with_no_brand_rows_serves_no_brands(): void
    {
        $warehouse = $this->makeWarehouse();
        $brand = $this->makeBrand();
        $this->makeCoveredBranch($warehouse, $this->nasrCity);
        // Deliberately no WarehouseBrandCoverage rows.

        $order = $this->makeOrder([$brand]);
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertNull(
            $order->assigned_warehouse_id,
            'An unconfigured warehouse must serve NO brands, never all brands.',
        );
    }

    // ── Inactive coverage row is not coverage ────────────────────────────────

    public function test_inactive_brand_coverage_row_does_not_grant_eligibility(): void
    {
        $warehouse = $this->makeWarehouse();
        $brand = $this->makeBrand();
        $this->makeCoveredBranch($warehouse, $this->nasrCity);

        WarehouseBrandCoverage::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $warehouse->id,
            'brand_id' => $brand->id,
            'is_active' => false,
        ]);

        $order = $this->makeOrder([$brand]);
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertNull($order->assigned_warehouse_id);
    }

    // ── D1 — canonical geography resolves an ARABIC governorate ──────────────

    public function test_d1_arabic_governorate_resolves_via_canonical_name_ar(): void
    {
        $warehouse = $this->makeWarehouse();
        $brand = $this->makeBrand();
        $this->makeCoveredBranch($warehouse, $this->nasrCity);
        $this->serveBrand($warehouse, $brand);

        // The order is addressed in Arabic, as every real order in this ERP is.
        $order = $this->makeOrder([$brand], governorate: 'القاهرة');
        $this->engine->assign($order, $this->company->id);
        $order->refresh();

        $this->assertSame($warehouse->id, $order->assigned_warehouse_id);
    }

    // ── Duplicate coverage rejected by the database, not by app code ─────────

    public function test_duplicate_warehouse_brand_relationship_is_rejected(): void
    {
        $warehouse = $this->makeWarehouse();
        $brand = $this->makeBrand();
        $this->serveBrand($warehouse, $brand);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->serveBrand($warehouse, $brand);
    }
}
