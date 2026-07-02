<?php

declare(strict_types=1);

namespace Modules\CostManagement\Domain\Services;

use Illuminate\Support\Collection;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\RecipeLine;

/**
 * Computes Recipe Cost from component material costs.
 *
 * Recipe Cost = Σ (component.material_cost × line.quantity)
 *
 * Uses material_cost as the authoritative material cost (Part 2).
 * Falls back to last_purchase_cost when material_cost is not yet populated.
 */
final class RecipeCostCalculator
{
    /**
     * Calculate and persist recipe_cost for the given recipe.
     * Returns the new recipe_cost value.
     */
    public function recalculate(Recipe $recipe): float
    {
        $recipe->loadMissing('components.component');

        $recipeCost = $recipe->components->sum(function (RecipeLine $line): float {
            $component = $line->component;
            if ($component === null) {
                return 0.0;
            }

            $unitCost = (float) ($component->material_cost
                ?? $component->last_purchase_cost
                ?? 0.0);

            return $unitCost * (float) $line->quantity;
        });

        $recipeCost = round($recipeCost, 4);

        $recipe->update([
            'recipe_cost'            => $recipeCost,
            'recipe_cost_updated_at' => now(),
        ]);

        return $recipeCost;
    }

    /**
     * Calculate recipe cost without persisting (for dry-run / preview).
     */
    public function preview(Recipe $recipe): float
    {
        $recipe->loadMissing('components.component');

        return round(
            $recipe->components->sum(function (RecipeLine $line): float {
                $component = $line->component;
                if ($component === null) {
                    return 0.0;
                }
                $unitCost = (float) ($component->material_cost
                    ?? $component->last_purchase_cost
                    ?? 0.0);
                return $unitCost * (float) $line->quantity;
            }),
            4
        );
    }
}
