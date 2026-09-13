<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\InventoryItems\Application\Actions\ReceiveStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\Products\Domain\Services\ProductCommerceAvailabilityService;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ManufacturingAvailabilityService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R2 (CTO business-rule correction, superseding R2-R1's
 * precedence) — warehouse physical stock is raw-material stock; a manufactured finished good's
 * OWN physical on_hand quantity is never an independent sellability signal and can never
 * override an unavailable Recipe. ManufacturingAvailabilityService itself is mocked here (it
 * has its own coverage elsewhere) so these tests verify ONLY the precedence this orchestration
 * service adds.
 *
 * Per the CTO's Track 2 execution model, these are written as source-level regression coverage
 * but their EXECUTION is deferred to the consolidated test pass.
 */
final class ProductCommerceAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    private function receive(Product $product, float $quantity): void
    {
        app(ReceiveStockAction::class)->execute(StockOperationDTO::fromArray([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'quantity' => $quantity,
            'reference_type' => 'test',
            'reference_id' => 'availability-test',
        ]));
    }

    private function manufacturedProduct(bool $allowNegativeStock = false): Product
    {
        return Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'allow_negative_stock' => $allowNegativeStock,
        ]);
    }

    // ═══ Manufactured (Recipe exists) — Recipe is the SOLE authority ══════════

    public function test_manufactured_product_with_executable_recipe_is_available_despite_zero_stock(): void
    {
        $product = $this->manufacturedProduct();
        // No receipt at all — zero physical on-hand.

        $this->mock(ManufacturingAvailabilityService::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(['status' => 'instock', 'blocking_materials' => [], 'components' => []]);

        $this->assertTrue(
            app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()),
            'A manufactured product with an executable recipe must resolve AVAILABLE even with zero finished-product on-hand quantity.',
        );
    }

    public function test_manufactured_product_with_unavailable_recipe_is_not_available_even_with_stale_physical_stock(): void
    {
        $product = $this->manufacturedProduct();
        // A legacy/stale physical on-hand quantity exists for this finished good...
        $this->receive($product, 50.0);

        // ...but the recipe is not executable (insufficient raw materials). This is the CTO's
        // required proof: stale finished-product stock must NEVER override an unavailable
        // Recipe.
        $this->mock(ManufacturingAvailabilityService::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(['status' => 'outofstock', 'blocking_materials' => [['id' => 'raw-1']], 'components' => []]);

        $this->assertFalse(
            app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()),
            'A manufactured product whose recipe is not executable must be NOT AVAILABLE regardless of any stale physical finished-product quantity.',
        );
    }

    public function test_manufactured_product_availability_never_consults_physical_stock_when_a_recipe_exists(): void
    {
        $product = $this->manufacturedProduct();
        $this->receive($product, 999.0);

        // Even with abundant physical stock, an unavailable recipe must still win.
        $this->mock(ManufacturingAvailabilityService::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(['status' => 'outofstock', 'blocking_materials' => [['id' => 'raw-1']], 'components' => []]);

        $this->assertFalse(app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()));
    }

    // ═══ Manufactured, but no active Recipe configured — falls back to physical stock ══

    public function test_finished_good_with_no_active_recipe_falls_back_to_physical_stock(): void
    {
        $product = $this->manufacturedProduct();
        $this->receive($product, 5.0);

        $this->mock(ManufacturingAvailabilityService::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(['status' => 'recipe_missing', 'blocking_materials' => [], 'components' => []]);

        $this->assertTrue(app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()));
    }

    public function test_finished_good_with_no_active_recipe_and_no_stock_is_not_available(): void
    {
        $product = $this->manufacturedProduct();

        $this->mock(ManufacturingAvailabilityService::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(['status' => 'recipe_missing', 'blocking_materials' => [], 'components' => []]);

        $this->assertFalse(app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()));
    }

    // ═══ Non-manufactured (raw material / packaging) — physical stock authority, ══════
    // ═══ Recipe never consulted ═══════════════════════════════════════════════

    public function test_raw_material_availability_never_consults_manufacturing_service(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_RAW_MATERIAL,
            'allow_negative_stock' => false,
        ]);
        $this->receive($product, 10.0);

        $this->mock(ManufacturingAvailabilityService::class)->shouldNotReceive('evaluate');

        $this->assertTrue(app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()));
    }

    public function test_raw_material_with_no_stock_and_no_negative_policy_is_not_available(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_RAW_MATERIAL,
            'allow_negative_stock' => false,
        ]);

        $this->mock(ManufacturingAvailabilityService::class)->shouldNotReceive('evaluate');

        $this->assertFalse(app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()));
    }

    public function test_raw_material_with_no_stock_but_allow_negative_stock_is_available(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_PACKAGING_MATERIAL,
            'allow_negative_stock' => true,
        ]);

        $this->mock(ManufacturingAvailabilityService::class)->shouldNotReceive('evaluate');

        $this->assertTrue(app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()));
    }
}
