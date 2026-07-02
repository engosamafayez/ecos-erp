<?php

declare(strict_types=1);

namespace Modules\CostManagement\Domain\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\RecipeLine;

/**
 * Orchestrates the full cost cascade chain (Part 3):
 *
 *   Material Cost → Recipe Cost → Unit Cost → Product Cost
 *
 * Called whenever any material's material_cost changes.
 * Returns arrays of affected recipe IDs and finished-product IDs
 * so callers can record them in material_cost_history and create pricing reviews.
 */
final class CostCascadeService
{
    public function __construct(
        private readonly RecipeCostCalculator $recipeCostCalculator,
        private readonly ProductCostCalculator $productCostCalculator,
    ) {}

    /**
     * Run the cascade for one material product.
     *
     * @return array{affected_recipe_ids: list<string>, affected_product_ids: list<string>}
     */
    public function cascadeFromMaterial(Product $material): array
    {
        $affectedRecipeIds  = [];
        $affectedProductIds = [];

        DB::transaction(function () use ($material, &$affectedRecipeIds, &$affectedProductIds): void {
            // Step 1: Find all ACTIVE recipes that use this material as a component
            $recipeIds = RecipeLine::query()
                ->where('raw_material_id', $material->id)
                ->pluck('bom_id')
                ->unique()
                ->values()
                ->all();

            if (empty($recipeIds)) {
                return;
            }

            $recipes = Recipe::query()
                ->whereIn('id', $recipeIds)
                ->where('is_active', true)
                ->with(['components.component', 'product.activeRecipe'])
                ->get();

            foreach ($recipes as $recipe) {
                /** @var Recipe $recipe */
                try {
                    // Step 2: Recalculate Recipe Cost
                    $this->recipeCostCalculator->recalculate($recipe);
                    $affectedRecipeIds[] = $recipe->id;

                    // Step 3: Recalculate Product Cost + Unit Cost for the finished good
                    $product = $recipe->product;
                    if ($product !== null) {
                        $this->productCostCalculator->recalculate($product);
                        $affectedProductIds[] = $product->id;
                    }
                } catch (\Throwable $e) {
                    Log::channel('daily')->error(
                        'CostCascadeService: failed to cascade recipe',
                        [
                            'recipe_id'   => $recipe->id,
                            'material_id' => $material->id,
                            'error'       => $e->getMessage(),
                        ]
                    );
                }
            }
        });

        return [
            'affected_recipe_ids'  => array_values(array_unique($affectedRecipeIds)),
            'affected_product_ids' => array_values(array_unique($affectedProductIds)),
        ];
    }
}
