<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-OPS-01-CLOSURE-03 — characterization/regression coverage for
 * the business rule this closure's report relies on: ECOS warehouse stock is
 * RAW MATERIAL stock. Finished sellable Products do not have a canonical
 * warehouse on-hand/reserved/available quantity, and reported Inventory value
 * must come from cost (material_cost / FIFO), never a retail/selling price.
 *
 * `ProductController@stats` already implements all of this (default product-type
 * scope excludes finished_good; value expression uses material_cost, not
 * sale_price) — these tests lock that in rather than fix a defect.
 *
 * NOT YET EXECUTED per this task's static-sanity-only policy — written and
 * reviewed statically only.
 */
final class RawMaterialReportingBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_default_scope_excludes_finished_goods_and_includes_both_material_types(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);

        $raw = Product::factory()->rawMaterial()->create([
            'brand_id' => $brand->id,
            'company_id' => $company->id,
            'material_cost' => 10.0,
        ]);
        $packaging = Product::factory()->create([
            'brand_id' => $brand->id,
            'company_id' => $company->id,
            'product_type' => Product::TYPE_PACKAGING_MATERIAL,
            'material_cost' => 2.0,
        ]);
        Product::factory()->finishedGood()->create([
            'brand_id' => $brand->id,
            'company_id' => $company->id,
        ]);

        $this->stock($warehouse, $raw, onHand: 100.0, reserved: 20.0);
        $this->stock($warehouse, $packaging, onHand: 50.0, reserved: 0.0);
        // A finished good should not structurally carry real InventoryItem rows
        // under this business model — none created here, on purpose.

        $user = $this->companyUser($company);
        $this->actingAsUnprivileged($user);

        $response = $this->getJson('/api/products/stats')->assertOk();

        // 100 (raw) + 50 (packaging) — the finished good contributes nothing,
        // and no third row was created for it to accidentally aggregate.
        self::assertEquals(150.0, $response->json('data.total_on_hand'));
        self::assertEquals(20.0, $response->json('data.total_reserved'));
        self::assertEquals(130.0, $response->json('data.total_available'));
    }

    public function test_stats_filters_to_one_material_type_without_leaking_the_other(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);

        $raw = Product::factory()->rawMaterial()->create([
            'brand_id' => $brand->id,
            'company_id' => $company->id,
            'material_cost' => 10.0,
        ]);
        $packaging = Product::factory()->create([
            'brand_id' => $brand->id,
            'company_id' => $company->id,
            'product_type' => Product::TYPE_PACKAGING_MATERIAL,
            'material_cost' => 2.0,
        ]);

        $this->stock($warehouse, $raw, onHand: 100.0, reserved: 0.0);
        $this->stock($warehouse, $packaging, onHand: 50.0, reserved: 0.0);

        $user = $this->companyUser($company);
        $this->actingAsUnprivileged($user);

        $response = $this->getJson('/api/products/stats?product_type=packaging_material')->assertOk();

        self::assertEquals(50.0, $response->json('data.total_on_hand'), 'packaging-only view must not include the raw material row');
    }

    public function test_inventory_value_uses_cost_not_a_retail_selling_price(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);

        // A deliberately large gap between cost and any retail-style price, so a
        // report that accidentally used the wrong field is unmistakable.
        $raw = Product::factory()->rawMaterial()->create([
            'brand_id' => $brand->id,
            'company_id' => $company->id,
            'material_cost' => 5.0,
            'sale_price' => 500.0,
        ]);
        $this->stock($warehouse, $raw, onHand: 10.0, reserved: 0.0);

        $user = $this->companyUser($company);
        $this->actingAsUnprivileged($user);

        $response = $this->getJson('/api/products/stats')->assertOk();

        // 10 units * 5.0 cost = 50, never 10 * 500 = 5000.
        self::assertEquals(50.0, $response->json('data.total_inventory_value'));
    }

    private function stock(Warehouse $warehouse, Product $product, float $onHand, float $reserved): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'company_id' => $warehouse->company_id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    private function companyUser(Company $company): \App\Models\User
    {
        return \App\Models\User::factory()->create(['company_id' => $company->id]);
    }
}
