<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ManufacturingAvailabilityService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-GOLIVE-RECIPE-CROSS-BRAND-REUSE-CERTIFICATION-001
 *
 * Proves that a Raw Material is a COMPANY-level resource, not a BRAND-level
 * one: a single Raw Material Product may be referenced by recipes belonging to
 * different Brands, provided all sit under the same Company.
 *
 * This is a prerequisite for F4. F4 will scope component availability by
 * `$product->brand?->company_id`, which resolves to a COMPANY. This test is the
 * regression that catches any accidental collapse to Brand-level scoping.
 *
 * Runs against production code exactly as it stands at 6149875b - nothing here
 * modifies business logic.
 */
final class RecipeCrossBrandReuseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Brand $brandA;

    private Brand $brandB;

    /** @var array<string, string> */
    public static array $evidence = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->brandA = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->brandB = Brand::factory()->create(['company_id' => $this->company->id]);
    }

    private function finishedGood(Brand $brand): Product
    {
        return Product::factory()->finishedGood()->manufacturable()->create(['brand_id' => $brand->id]);
    }

    private function recipeWith(Product $fg, Product $component, float $qty = 2.0): Recipe
    {
        $recipe = Recipe::create([
            'bom_number' => 'BOM-XB-'.uniqid(),
            'product_id' => $fg->id,
            'version' => '1.0',
            'bom_version_number' => 1,
            'is_active' => true,
        ]);

        $recipe->components()->create([
            'raw_material_id' => $component->id,
            'quantity' => $qty,
        ]);

        return $recipe;
    }

    private function recipeStatus(Product $fg): string
    {
        return (string) app(ManufacturingAvailabilityService::class)->evaluate($fg->fresh())['status'];
    }

    /**
     * Company A
     *   Brand A -> FG A -> Recipe A ->\
     *                                  >-- ONE Raw Material X (owned by Brand A)
     *   Brand B -> FG B -> Recipe B ->/
     *   Inventory: X = 100, Company A
     */
    public function test_one_raw_material_serves_recipes_of_two_brands_in_one_company(): void
    {
        $fgA = $this->finishedGood($this->brandA);
        $fgB = $this->finishedGood($this->brandB);

        // Exactly ONE raw material product. Deliberately owned by Brand A, so
        // Recipe B references a component from a DIFFERENT brand.
        $rawX = Product::factory()->rawMaterial()->create([
            'brand_id' => $this->brandA->id,
            'is_active' => true,
            'allow_negative_stock' => false,
        ]);

        $recipeA = $this->recipeWith($fgA, $rawX);
        $recipeB = $this->recipeWith($fgB, $rawX);

        InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $rawX->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => 100.0,
            'reserved_qty' => 0,
        ]);

        // -- Ownership assertions (Part 6) -----------------------------------
        $expected = $this->company->id;
        self::assertSame($expected, $this->brandA->company_id, 'Brand A company');
        self::assertSame($expected, $this->brandB->company_id, 'Brand B company');
        self::assertSame($expected, $fgA->brand?->company_id, 'FG A company');
        self::assertSame($expected, $fgB->brand?->company_id, 'FG B company');
        self::assertSame($expected, $rawX->brand?->company_id, 'Raw Material X company');
        self::assertSame($expected, $this->warehouse->company_id, 'Warehouse company');

        // Two brands really are distinct.
        self::assertNotSame($this->brandA->id, $this->brandB->id, 'Brands must differ');
        self::assertNotSame($fgB->brand_id, $rawX->brand_id, 'FG B and Raw X must be on different brands');

        // -- Shared component identity (Part 4) ------------------------------
        $componentA = $recipeA->components()->first()?->raw_material_id;
        $componentB = $recipeB->components()->first()?->raw_material_id;
        self::assertSame($componentA, $componentB, 'Both recipes must reference the SAME product id');
        self::assertSame($rawX->id, $componentA);

        // -- Runtime evaluation (Parts 7, 8) ---------------------------------
        $a = $this->recipeStatus($fgA);
        $b = $this->recipeStatus($fgB);

        self::assertSame('instock', $a, 'Recipe A must be executable.');
        self::assertSame(
            'instock',
            $b,
            'Recipe B must be executable even though Raw Material X belongs to another BRAND.',
        );

        // -- Reverse order (Part 9): no shared mutable state -----------------
        $bFirst = $this->recipeStatus($fgB);
        $aSecond = $this->recipeStatus($fgA);
        self::assertSame('instock', $bFirst);
        self::assertSame('instock', $aSecond);

        self::$evidence['CROSS_BRAND'] = sprintf(
            'company=%s | brandA=%s | brandB=%s | rawX_brand=%s | shared_component=%s | A=%s B=%s | reverse B=%s A=%s',
            $expected, $this->brandA->id, $this->brandB->id, $rawX->brand_id,
            $componentA === $componentB ? 'yes' : 'NO',
            $a, $b, $bFirst, $aSecond,
        );
    }

    /** Part 10 - a third brand, same single raw material. */
    public function test_third_brand_reuses_the_same_raw_material(): void
    {
        $brandC = Brand::factory()->create(['company_id' => $this->company->id]);

        $rawX = Product::factory()->rawMaterial()->create([
            'brand_id' => $this->brandA->id,
            'is_active' => true,
            'allow_negative_stock' => false,
        ]);

        $fgC = $this->finishedGood($brandC);
        $this->recipeWith($fgC, $rawX);

        InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $rawX->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => 100.0,
            'reserved_qty' => 0,
        ]);

        self::assertSame($this->company->id, $brandC->company_id);
        self::assertSame('instock', $this->recipeStatus($fgC), 'A third brand must reuse the same raw material.');
    }

    /** Part 11 - supplementary: allow_negative_stock does not break reuse. */
    public function test_cross_brand_reuse_with_allow_negative_stock(): void
    {
        $rawX = Product::factory()->rawMaterial()->create([
            'brand_id' => $this->brandA->id,
            'is_active' => true,
            'allow_negative_stock' => true,
        ]);

        $fgA = $this->finishedGood($this->brandA);
        $fgB = $this->finishedGood($this->brandB);
        $this->recipeWith($fgA, $rawX);
        $this->recipeWith($fgB, $rawX);

        // No inventory at all - allow_negative_stock alone must carry both.
        self::assertSame('instock', $this->recipeStatus($fgA));
        self::assertSame('instock', $this->recipeStatus($fgB));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$evidence !== []) {
            fwrite(STDERR, "\n===== CROSS-BRAND REUSE EVIDENCE =====\n");
            foreach (self::$evidence as $k => $v) {
                fwrite(STDERR, "  {$k}: {$v}\n");
            }
            fwrite(STDERR, "======================================\n");
        }
        parent::tearDownAfterClass();
    }
}
