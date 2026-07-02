<?php

declare(strict_types=1);

namespace Modules\CostManagement\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\CostManagement\Domain\Enums\CostUpdateSource;
use Modules\CostManagement\Domain\Models\MaterialCostHistory;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * Updates a material's official Material Cost and triggers the cascade.
 *
 * Part 2: Material Cost can be updated by Manual Edit OR Approved Purchase Invoice.
 * Whichever happens last becomes the current Material Cost.
 *
 * Side effects (in sequence):
 *  1. Update products.material_cost
 *  2. Create material_cost_history record
 *  3. Run cascade: recipe_cost → product_cost → unit_cost for all affected downstream products
 *  4. Return affected recipe/product IDs (caller uses these to create pricing reviews)
 */
final class MaterialCostService
{
    public function __construct(
        private readonly CostCascadeService $cascade,
    ) {}

    /**
     * Update material cost and cascade.
     *
     * @param  array{
     *   goods_receipt_id?: string|null,
     *   updated_by?: string|null,
     * } $meta
     * @return MaterialCostHistory
     */
    public function update(
        Product $material,
        float $newCost,
        CostUpdateSource $source,
        array $meta = [],
    ): MaterialCostHistory {
        $previousCost = (float) ($material->material_cost ?? $material->last_purchase_cost ?? 0.0);
        $difference   = round($newCost - $previousCost, 4);
        $changePct    = $previousCost > 0
            ? round(($difference / $previousCost) * 100, 4)
            : null;

        // Step 1: Update the material's cost
        $material->update(['material_cost' => round($newCost, 4)]);

        // Step 2: Run cascade — updates recipe_cost, product_cost, unit_cost downstream
        $cascadeResult = $this->cascade->cascadeFromMaterial($material);

        // Step 3: Create audit record
        $history = MaterialCostHistory::query()->create([
            'product_id'          => $material->id,
            'previous_cost'       => $previousCost > 0 ? $previousCost : null,
            'new_cost'            => round($newCost, 4),
            'difference'          => $difference,
            'change_pct'          => $changePct,
            'source'              => $source->value,
            'goods_receipt_id'    => $meta['goods_receipt_id'] ?? null,
            'updated_by'          => $meta['updated_by'] ?? null,
            'affected_recipe_ids' => $cascadeResult['affected_recipe_ids'],
            'affected_product_ids'=> $cascadeResult['affected_product_ids'],
            'occurred_at'         => now(),
            'created_at'          => now(),
        ]);

        return $history;
    }
}
