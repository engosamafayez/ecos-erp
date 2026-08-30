<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Admin\Configuration\Domain\Models\MasterGovernorate;
use Modules\Admin\Configuration\Domain\Models\MasterZone;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\MasterData\Warehouses\Domain\Models\WarehouseBrandCoverage;
use Modules\Operations\Preparation\Application\Services\BranchAssignmentEngine;
use Modules\Organization\Branches\Domain\Models\Branch;
use Modules\Organization\Branches\Domain\Models\BranchCoverageArea;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-WAREHOUSE-BRAND-PAYMENT-IMPLEMENTATION-001 §A4 — Brand → Warehouse coverage
 * write surface. Drives the REAL HTTP endpoints and, for §A4.9/§A4.10, the REAL
 * BranchAssignmentEngine, proving the new configuration surface feeds the existing
 * certified routing without changing its semantics.
 */
final class BrandWarehouseCoverageTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->brand = $this->makeBrand($this->company);
    }

    private function makeBrand(Company $company): Brand
    {
        return Brand::create([
            'company_id' => $company->id,
            'code' => 'BR'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Brand '.Str::random(5),
            'slug' => 'brand-'.Str::random(8),
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(?Company $company = null): Warehouse
    {
        return Warehouse::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
            'is_active' => true,
        ]);
    }

    private function user(?Company $company = null): User
    {
        return User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
    }

    /** @param list<string> $warehouseIds */
    private function putCoverage(User $user, string $brandId, array $warehouseIds): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->putJson("/api/brands/{$brandId}/warehouse-coverage", ['warehouse_ids' => $warehouseIds]);
    }

    private function servedIds(string $brandId): array
    {
        return WarehouseBrandCoverage::query()
            ->where('brand_id', $brandId)->where('is_active', true)
            ->pluck('warehouse_id')->sort()->values()->all();
    }

    // 1 — enable one warehouse
    public function test_brand_can_enable_a_warehouse(): void
    {
        $wh = $this->makeWarehouse();
        $this->putCoverage($this->user(), $this->brand->id, [$wh->id])->assertOk();
        self::assertSame([$wh->id], $this->servedIds($this->brand->id));
    }

    // 2 — enable multiple
    public function test_brand_can_enable_multiple_warehouses(): void
    {
        $a = $this->makeWarehouse();
        $b = $this->makeWarehouse();
        $this->putCoverage($this->user(), $this->brand->id, [$a->id, $b->id])->assertOk();
        self::assertEqualsCanonicalizing([$a->id, $b->id], $this->servedIds($this->brand->id));
    }

    // 3 — one warehouse serves multiple brands
    public function test_a_warehouse_can_serve_multiple_brands(): void
    {
        $wh = $this->makeWarehouse();
        $brandB = $this->makeBrand($this->company);
        $this->putCoverage($this->user(), $this->brand->id, [$wh->id])->assertOk();
        $this->putCoverage($this->user(), $brandB->id, [$wh->id])->assertOk();
        self::assertSame([$wh->id], $this->servedIds($this->brand->id));
        self::assertSame([$wh->id], $this->servedIds($brandB->id));
    }

    // 4 — removing coverage works
    public function test_removing_coverage_works(): void
    {
        $wh = $this->makeWarehouse();
        $this->putCoverage($this->user(), $this->brand->id, [$wh->id])->assertOk();
        $this->putCoverage($this->user(), $this->brand->id, [])->assertOk();
        self::assertSame([], $this->servedIds($this->brand->id));
    }

    // 5 — duplicate enable is idempotent
    public function test_duplicate_enable_is_idempotent(): void
    {
        $wh = $this->makeWarehouse();
        $this->putCoverage($this->user(), $this->brand->id, [$wh->id])->assertOk();
        $this->putCoverage($this->user(), $this->brand->id, [$wh->id, $wh->id])->assertOk();
        self::assertSame([$wh->id], $this->servedIds($this->brand->id));
        self::assertSame(1, WarehouseBrandCoverage::where('brand_id', $this->brand->id)->count());
    }

    // 6 — cross-company warehouse rejected
    public function test_cross_company_warehouse_is_rejected(): void
    {
        $otherCompany = Company::factory()->create();
        $foreignWh = $this->makeWarehouse($otherCompany);
        $this->putCoverage($this->user(), $this->brand->id, [$foreignWh->id])->assertStatus(422);
        self::assertSame([], $this->servedIds($this->brand->id));
    }

    // 7 — unauthorized user rejected
    public function test_unauthorized_user_is_rejected(): void
    {
        $wh = $this->makeWarehouse();
        $unprivileged = $this->user();
        $this->actingAsUnprivileged($unprivileged)
            ->putJson("/api/brands/{$this->brand->id}/warehouse-coverage", ['warehouse_ids' => [$wh->id]])
            ->assertStatus(403);
        self::assertSame([], $this->servedIds($this->brand->id));
    }

    // 8 — empty coverage means brand served by no warehouse
    public function test_empty_coverage_means_no_warehouse_serves_the_brand(): void
    {
        $this->makeWarehouse();
        $response = $this->actingAs($this->user())->getJson("/api/brands/{$this->brand->id}/warehouse-coverage");
        $response->assertOk();
        foreach ($response->json('data') as $row) {
            self::assertFalse($row['serves_brand'], 'No warehouse may serve a freshly-created brand.');
        }
        self::assertSame([], $this->servedIds($this->brand->id));
    }

    // 9 — engine respects coverage configured through this endpoint
    public function test_engine_assigns_warehouse_configured_through_the_endpoint(): void
    {
        [$engine, $warehouse, $order] = $this->engineScenario(configureCoverage: true);
        $engine->assign($order, $this->company->id);
        self::assertSame($warehouse->id, $order->refresh()->assigned_warehouse_id);
    }

    // 10 — geography + brand AND semantics preserved: geo match but NO coverage → refused
    public function test_geography_match_without_configured_coverage_is_refused(): void
    {
        [$engine, , $order] = $this->engineScenario(configureCoverage: false);
        $engine->assign($order, $this->company->id);
        self::assertNull($order->refresh()->assigned_warehouse_id);
    }

    /** @return array{0: BranchAssignmentEngine, 1: Warehouse, 2: Order} */
    private function engineScenario(bool $configureCoverage): array
    {
        $cairo = MasterGovernorate::create([
            'name' => 'Cairo', 'name_ar' => 'القاهرة',
            'code' => 'C'.substr((string) Str::uuid(), 0, 7), 'is_active' => true,
        ]);
        $zone = MasterZone::create([
            'master_governorate_id' => $cairo->id, 'name' => 'Nasr City',
            'code' => 'NC'.substr((string) Str::uuid(), 0, 8), 'is_active' => true,
        ]);
        $warehouse = $this->makeWarehouse();
        $branch = Branch::create([
            'company_id' => $this->company->id, 'code' => 'BR-'.Str::random(6),
            'name' => 'Branch '.Str::random(5), 'default_warehouse_id' => $warehouse->id, 'is_active' => true,
        ]);
        BranchCoverageArea::create([
            'branch_id' => $branch->id, 'master_governorate_id' => $cairo->id,
            'master_zone_id' => $zone->id, 'priority' => 100, 'is_active' => true,
        ]);

        if ($configureCoverage) {
            $this->putCoverage($this->user(), $this->brand->id, [$warehouse->id])->assertOk();
        }

        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $order = Order::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'order_number' => 'ORD-BWC-'.Str::random(6), 'order_date' => now()->toDateString(),
            'status' => 'in_progress', 'subtotal' => 0, 'total' => 0, 'shipping_total' => 0,
            'discount_total' => 0, 'tax_total' => 0, 'governorate' => 'Cairo', 'area' => 'Nasr City',
        ]);
        $product = Product::factory()->create(['brand_id' => $this->brand->id, 'company_id' => $this->company->id]);
        OrderLine::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_price' => 10, 'line_total' => 10,
        ]);

        return [app(BranchAssignmentEngine::class), $warehouse, $order->refresh()];
    }
}
