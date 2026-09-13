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
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §1/§3 — the CTO's critical invariant: a
 * made-to-order finished good with zero physical on-hand quantity but an executable recipe
 * must resolve AVAILABLE, not OutOfStock. ManufacturingAvailabilityService itself is mocked
 * here (it has its own coverage elsewhere) so these tests verify ONLY the precedence this new
 * orchestration service adds — physical stock first, executable-recipe fallback, matching
 * ReserveOrderInventoryAction's own established precedence, generalized.
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

    public function test_available_when_physical_stock_is_sufficient_regardless_of_recipe(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'allow_negative_stock' => false,
        ]);
        $this->receive($product, 10.0);

        // Recipe evaluation must never even be consulted when physical stock already covers
        // it — Case 1 is never gated by the recipe.
        $this->mock(ManufacturingAvailabilityService::class)->shouldNotReceive('evaluate');

        $this->assertTrue(app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()));
    }

    public function test_made_to_order_product_with_executable_recipe_is_available_despite_zero_stock(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'allow_negative_stock' => false,
        ]);
        // No receipt at all — zero physical on-hand.

        $this->mock(ManufacturingAvailabilityService::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(['status' => 'instock', 'blocking_materials' => [], 'components' => []]);

        $this->assertTrue(
            app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()),
            'A made-to-order product with an executable recipe must resolve AVAILABLE even with zero finished-product on-hand quantity.',
        );
    }

    public function test_not_available_when_recipe_raw_materials_are_insufficient(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'allow_negative_stock' => false,
        ]);

        $this->mock(ManufacturingAvailabilityService::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(['status' => 'outofstock', 'blocking_materials' => [['id' => 'x']], 'components' => []]);

        $this->assertFalse(app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()));
    }

    public function test_not_available_when_recipe_missing_and_no_physical_stock(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'allow_negative_stock' => false,
        ]);

        $this->mock(ManufacturingAvailabilityService::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(['status' => 'recipe_missing', 'blocking_materials' => [], 'components' => []]);

        $this->assertFalse(app(ProductCommerceAvailabilityService::class)->isAvailable($product->fresh()));
    }

    public function test_allow_negative_stock_makes_a_zero_stock_recipe_less_product_available(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_RAW_MATERIAL,
            'allow_negative_stock' => true,
        ]);

        // Not a finished good at all — recipe evaluation must never be consulted.
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
}
