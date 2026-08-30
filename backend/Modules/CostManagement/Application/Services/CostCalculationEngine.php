<?php

declare(strict_types=1);

namespace Modules\CostManagement\Application\Services;

use Modules\CostManagement\Application\DTO\RecipeCostSummaryDTO;
use Modules\CostManagement\Domain\Models\RecipeCostHistory;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\BillOfMaterial;

/**
 * Enterprise Cost Calculation Engine — single source of truth for recipe costing.
 *
 * Semantic contract (TASK-RECIPE-COST-CONSISTENCY-001):
 *   bills_of_materials.recipe_cost    = rawMaterialCost + packagingCost  (MATERIALS ONLY)
 *   bills_of_materials.packaging_cost = packagingCost
 *   bills_of_materials.cost_summary   = full RecipeCostSummaryDTO as JSON (includes total)
 *   RecipeCostSummaryDTO.recipeCost   = rawMaterialCost + packagingCost + mfg + other (TOTAL)
 *
 * ProductCostCalculator reads recipe.recipe_cost (materials only) and adds mfg+other → correct.
 * No other module may implement cost formulas.
 */
final class CostCalculationEngine
{
    /**
     * Per-component cost breakdown — the single place the line formula lives.
     *
     *     effectiveQty  = quantity × (1 + waste% / 100)
     *     extendedCost  = effectiveQty × material_cost
     *
     * {@see calculate()} sums these rows rather than repeating the arithmetic, and
     * the approved-recipe cost snapshot freezes them. That shared origin is the
     * point: a snapshot whose lines add up to something other than the recipe cost
     * shown beside it would be indefensible, and here it is impossible.
     *
     * A component with no `material_cost` is returned with `has_cost = false` and a
     * zero cost rather than being dropped. The caller must decide what an unpriced
     * component means — the aggregate counts it as a missing cost, and the snapshot
     * refuses to freeze at all.
     *
     * @return list<array{
     *   line_id: string,
     *   material_id: string,
     *   sku: string|null,
     *   name: string|null,
     *   unit_symbol: string|null,
     *   quantity: float,
     *   waste_percentage: float,
     *   effective_quantity: float,
     *   unit_cost: float,
     *   extended_cost: float,
     *   is_packaging: bool,
     *   has_cost: bool,
     * }>
     */
    public function calculateLines(BillOfMaterial $bom): array
    {
        // `unit` is eager-loaded because the snapshot denormalises the symbol, and
        // resolving it lazily inside the loop would issue one query per component.
        $bom->loadMissing(['lines.rawMaterial.unit']);

        $rows = [];

        foreach ($bom->lines as $line) {
            $material = $line->rawMaterial;

            // A line pointing at a material that no longer exists is not a zero-cost
            // component — it is a broken recipe, and skipping it here keeps the
            // aggregate honest. The snapshot detects the same gap by line count.
            if ($material === null) {
                continue;
            }

            $hasCost = $material->material_cost !== null;
            $unitCost = (float) ($material->material_cost ?? 0.0);
            $qty = (float) $line->quantity;
            $waste = (float) ($line->waste_percentage ?? 0.0);
            $effectiveQty = $qty * (1.0 + $waste / 100.0);

            $rows[] = [
                'line_id' => (string) $line->id,
                'material_id' => (string) $material->id,
                'sku' => $material->sku,
                'name' => $material->name,
                'unit_symbol' => $material->unit?->symbol,
                'quantity' => $qty,
                'waste_percentage' => $waste,
                'effective_quantity' => round($effectiveQty, 4),
                'unit_cost' => round($unitCost, 4),
                'extended_cost' => $hasCost ? round($effectiveQty * $unitCost, 4) : 0.0,
                'is_packaging' => $material->product_type === 'packaging_material',
                'has_cost' => $hasCost,
            ];
        }

        return $rows;
    }

    /**
     * Calculate the full cost breakdown for a BOM without persisting anything.
     */
    public function calculate(BillOfMaterial $bom): RecipeCostSummaryDTO
    {
        $bom->loadMissing(['lines.rawMaterial', 'product.brand']);

        $rawMaterialCost = 0.0;
        $packagingCost = 0.0;
        $missingMaterialCount = 0;

        foreach ($this->calculateLines($bom) as $breakdown) {
            if ($breakdown['has_cost'] === false) {
                $missingMaterialCount++;

                continue;
            }

            if ($breakdown['is_packaging']) {
                $packagingCost += $breakdown['extended_cost'];
            } else {
                $rawMaterialCost += $breakdown['extended_cost'];
            }
        }

        $manufacturingCost = (float) ($bom->manufacturing_cost ?? 0.0);
        $otherCost = (float) ($bom->other_costs ?? 0.0);
        $materialsCost = $rawMaterialCost + $packagingCost;
        $recipeCost = $materialsCost + $manufacturingCost + $otherCost;
        $finishedProductCost = $recipeCost;

        $product = $bom->product;
        $currentSellingPrice = null;
        $suggestedPrice = null;
        $marginAmount = null;
        $marginPercent = null;

        if ($product !== null) {
            $currentSellingPrice = (float) ($product->regular_price ?? 0.0) ?: null;
            $targetMargin = $product->effectiveTargetMargin();

            if ($targetMargin < 100.0 && $finishedProductCost > 0.0) {
                $suggestedPrice = round($finishedProductCost / (1.0 - $targetMargin / 100.0), 4);
            }

            if ($currentSellingPrice !== null && $currentSellingPrice > 0.0 && $finishedProductCost > 0.0) {
                $marginAmount = $currentSellingPrice - $finishedProductCost;
                $marginPercent = ($marginAmount / $currentSellingPrice) * 100.0;
            }
        }

        return new RecipeCostSummaryDTO(
            rawMaterialCost: round($rawMaterialCost, 4),
            packagingCost: round($packagingCost, 4),
            manufacturingCost: round($manufacturingCost, 4),
            otherCost: round($otherCost, 4),
            recipeCost: round($recipeCost, 4),
            finishedProductCost: round($finishedProductCost, 4),
            suggestedSellingPrice: $suggestedPrice,
            currentSellingPrice: $currentSellingPrice,
            marginAmount: $marginAmount !== null ? round($marginAmount, 4) : null,
            marginPercent: $marginPercent !== null ? round($marginPercent, 4) : null,
            lastCalculatedAt: now()->toIso8601String(),
            hasMissingCosts: $missingMaterialCount > 0,
            missingMaterialCount: $missingMaterialCount,
        );
    }

    /**
     * Calculate and persist cost columns on the BOM, then record a cost history entry.
     *
     * Persists:
     *   recipe_cost            = rawMaterialCost + packagingCost  (MATERIALS ONLY)
     *   packaging_cost         = packagingCost
     *   cost_summary           = full DTO as JSON (includes total in recipe_cost key)
     *   cost_pending           = true when any component has no material_cost
     *   recipe_cost_updated_at = now()
     *
     * Does NOT cascade to product_cost — callers are responsible for that.
     */
    public function calculateAndPersist(
        BillOfMaterial $bom,
        string $triggerType = 'recipe_edit',
        ?string $triggerSource = null,
        ?string $triggeredBy = null,
    ): RecipeCostSummaryDTO {
        $previousMaterialsCost = (float) ($bom->recipe_cost ?? 0.0);

        $summary = $this->calculate($bom);
        $newMaterialsCost = round($summary->rawMaterialCost + $summary->packagingCost, 4);

        $bom->recipe_cost = $newMaterialsCost;
        $bom->packaging_cost = $summary->packagingCost;
        $bom->cost_summary = $summary->toArray();
        $bom->cost_pending = $summary->hasMissingCosts;
        $bom->recipe_cost_updated_at = now();
        $bom->saveQuietly();

        RecipeCostHistory::query()->create([
            'bom_id' => $bom->id,
            'previous_materials_cost' => $previousMaterialsCost > 0 ? $previousMaterialsCost : null,
            'new_materials_cost' => $newMaterialsCost,
            'difference' => round($newMaterialsCost - $previousMaterialsCost, 4),
            'trigger_type' => $triggerType,
            'trigger_source' => $triggerSource,
            'triggered_by' => $triggeredBy,
            'cost_snapshot' => $summary->toArray(),
            'occurred_at' => now(),
        ]);

        return $summary;
    }
}
