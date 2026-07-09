<?php

declare(strict_types=1);

namespace Modules\CostManagement\Application\Services;

use Modules\CostManagement\Application\DTO\RecipeCostSummaryDTO;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\BillOfMaterial;

/**
 * Enterprise Cost Calculation Engine — TASK-COST-ARCH-002 Part 1.
 *
 * This is the ONLY permitted location for cost formula business logic.
 * No other module may implement cost formulas. Frontend is forbidden from
 * performing cost calculations (calcRecipeCost / calcRecipeCostFromFormLines
 * are presentation helpers only and must consume this engine's output).
 *
 * Responsibilities:
 *  - Calculate per-line effective quantity (qty × (1 + waste%))
 *  - Separate raw material cost from packaging cost
 *  - Sum all cost components into recipe_cost and finished_product_cost
 *  - Compute suggested selling price from target margin (loaded via product.brand)
 *  - Compute margin amount and margin percent vs current selling price
 *  - Optionally persist results to the BOM record
 */
final class CostCalculationEngine
{
    /**
     * Calculate the full cost breakdown for a BOM without persisting anything.
     * Safe to call from read-only contexts (API previews, reporting).
     */
    public function calculate(BillOfMaterial $bom): RecipeCostSummaryDTO
    {
        $bom->loadMissing(['lines.rawMaterial', 'product.brand']);

        $rawMaterialCost = 0.0;
        $packagingCost   = 0.0;

        foreach ($bom->lines as $line) {
            $material = $line->rawMaterial;
            if ($material === null) {
                continue;
            }

            $unitCost     = (float) ($material->material_cost ?? 0.0);
            $qty          = (float) $line->quantity;
            $waste        = (float) ($line->waste_percentage ?? 0.0);
            $effectiveQty = $qty * (1.0 + $waste / 100.0);
            $lineTotal    = $effectiveQty * $unitCost;

            if ($material->product_type === 'packaging_material') {
                $packagingCost += $lineTotal;
            } else {
                $rawMaterialCost += $lineTotal;
            }
        }

        $manufacturingCost   = (float) ($bom->manufacturing_cost ?? 0.0);
        $otherCost           = (float) ($bom->other_costs ?? 0.0);
        $recipeCost          = $rawMaterialCost + $packagingCost + $manufacturingCost + $otherCost;
        $finishedProductCost = $recipeCost; // extensible for yield / overhead

        $product             = $bom->product;
        $currentSellingPrice = null;
        $suggestedPrice      = null;
        $marginAmount        = null;
        $marginPercent       = null;

        if ($product !== null) {
            $currentSellingPrice = (float) ($product->regular_price ?? 0.0) ?: null;
            $targetMargin        = $product->effectiveTargetMargin();

            if ($targetMargin < 100.0 && $finishedProductCost > 0.0) {
                $suggestedPrice = round($finishedProductCost / (1.0 - $targetMargin / 100.0), 4);
            }

            if ($currentSellingPrice !== null && $currentSellingPrice > 0.0 && $finishedProductCost > 0.0) {
                $marginAmount  = $currentSellingPrice - $finishedProductCost;
                $marginPercent = ($marginAmount / $currentSellingPrice) * 100.0;
            }
        }

        return new RecipeCostSummaryDTO(
            rawMaterialCost:     round($rawMaterialCost, 4),
            packagingCost:       round($packagingCost, 4),
            manufacturingCost:   round($manufacturingCost, 4),
            otherCost:           round($otherCost, 4),
            recipeCost:          round($recipeCost, 4),
            finishedProductCost: round($finishedProductCost, 4),
            suggestedSellingPrice: $suggestedPrice,
            currentSellingPrice:   $currentSellingPrice,
            marginAmount:  $marginAmount  !== null ? round($marginAmount, 4) : null,
            marginPercent: $marginPercent !== null ? round($marginPercent, 4) : null,
            lastCalculatedAt: now()->toIso8601String(),
        );
    }

    /**
     * Calculate and persist cost_summary, packaging_cost, and recipe_cost on the BOM.
     * Does NOT cascade to product_cost — callers are responsible for that.
     */
    public function calculateAndPersist(BillOfMaterial $bom): RecipeCostSummaryDTO
    {
        $summary = $this->calculate($bom);

        $bom->recipe_cost    = $summary->recipeCost;
        $bom->packaging_cost = $summary->packagingCost;
        $bom->cost_summary   = $summary->toArray();
        $bom->saveQuietly();

        return $summary;
    }
}
