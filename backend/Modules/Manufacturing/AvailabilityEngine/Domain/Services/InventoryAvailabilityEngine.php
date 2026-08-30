<?php

declare(strict_types=1);

namespace Modules\Manufacturing\AvailabilityEngine\Domain\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Manufacturing\AvailabilityEngine\Domain\Contracts\InventoryReadInterface;
use Modules\Manufacturing\AvailabilityEngine\Domain\Enums\ManufacturingEligibility;
use Modules\Manufacturing\AvailabilityEngine\Domain\ValueObjects\AvailabilityResult;
use Modules\Manufacturing\AvailabilityEngine\Domain\ValueObjects\RawMaterialAvailability;
use Modules\Manufacturing\BillsOfMaterials\Domain\Contracts\RecipeResolverInterface;
use Modules\Manufacturing\BillsOfMaterials\Domain\Exceptions\RecipeResolverException;
use Modules\Manufacturing\BillsOfMaterials\Domain\ValueObjects\RecipeComponent;
use Modules\Manufacturing\BillsOfMaterials\Domain\ValueObjects\RecipeSnapshot;

/**
 * Analyses whether a product can be fulfilled from stock or must be manufactured.
 *
 * READ-ONLY GUARANTEE: this engine never writes to inventory, never creates
 * manufacturing transactions, and never reserves stock. Every call is side-effect-free.
 *
 * Decision flow:
 *   1. Check finished-goods availability at the warehouse.
 *   2. If stock is sufficient → Sufficient (no manufacturing needed).
 *   3. Resolve recipe; if none exists → NoRecipe.
 *   4. For each component, compute required vs. available (scaled by qty_to_manufacture).
 *   5. Classify eligibility:
 *        All satisfied              → CanManufacture
 *        Unsatisfied but all have allow_negative_stock → Partial  (RC-2)
 *        Any unsatisfied without allow_negative_stock  → CannotManufacture
 */
final class InventoryAvailabilityEngine
{
    public function __construct(
        private readonly InventoryReadInterface $inventory,
        private readonly RecipeResolverInterface $resolver,
    ) {}

    public function analyse(
        string $productId,
        string $warehouseId,
        float $requiredQty,
        string $companyId,
    ): AvailabilityResult {
        $evaluatedAt = (new DateTimeImmutable)->format(DateTimeInterface::ATOM);

        // Signed availability (on_hand − reserved), exactly as InventoryItem exposes it.
        // Reported as-is on the result for telemetry; it is ROUTINELY NEGATIVE in the
        // made-to-order flow, because the order's own finished good is reserved BEFORE
        // manufacturing runs (on_hand 0, reserved ≥ required).
        $availableFg = $this->inventory->availableQty($warehouseId, $productId, $companyId);

        // The manufacturing shortage must be measured against FREE PHYSICAL stock only,
        // so the free position is clamped at zero. A reservation is a commitment, never
        // additional demand: feeding a negative availability straight into the shortage
        // would re-add the entire warehouse reservation pool and over-produce by
        // Σreserved (TASK-MTO-PRODUCTION-QUANTITY-ACCURACY-FIX-001). Clamping
        // on_hand − reserved — rather than using bare on_hand — is also what keeps this
        // correct on the shared-stock edge case: physical stock already reserved for
        // OTHER orders stays committed to them and is never consumed as if it were free,
        // so the engine neither over-produces nor under-produces.
        $freeFinishedGoods = max(0.0, $availableFg);

        // RC-1: partial manufacturing — only manufacture the shortage beyond free stock.
        $qtyToManufacture = max(0.0, $requiredQty - $freeFinishedGoods);

        if ($qtyToManufacture <= 0.0) {
            return $this->sufficientResult(
                productId: $productId,
                warehouseId: $warehouseId,
                requiredQty: $requiredQty,
                availableFg: $availableFg,
                evaluatedAt: $evaluatedAt,
            );
        }

        // Attempt recipe resolution — a missing recipe is a valid state, not an error
        try {
            $snapshot = $this->resolver->resolve($productId);
        } catch (RecipeResolverException) {
            return $this->noRecipeResult(
                productId: $productId,
                warehouseId: $warehouseId,
                requiredQty: $requiredQty,
                availableFg: $availableFg,
                qtyToManufacture: $qtyToManufacture,
                evaluatedAt: $evaluatedAt,
            );
        }

        $rawMaterials = $this->analyseComponents($snapshot, $warehouseId, $companyId, $qtyToManufacture);
        $eligibility = $this->classifyEligibility($rawMaterials);

        return new AvailabilityResult(
            product_id: $productId,
            warehouse_id: $warehouseId,
            required_qty: $requiredQty,
            available_finished_goods: $availableFg,
            qty_to_manufacture: $qtyToManufacture,
            needs_manufacturing: true,
            recipe_snapshot: $snapshot,
            raw_materials: $rawMaterials,
            can_manufacture: $eligibility->allowsManufacturing(),
            eligibility: $eligibility,
            evaluated_at: $evaluatedAt,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Builds per-component availability, scaling recipe quantities by qty_to_manufacture.
     *
     * @return list<RawMaterialAvailability>
     */
    private function analyseComponents(
        RecipeSnapshot $snapshot,
        string $warehouseId,
        string $companyId,
        float $qtyToManufacture,
    ): array {
        $results = [];

        foreach ($snapshot->components as $component) {
            $results[] = $this->analyseComponent($component, $warehouseId, $companyId, $qtyToManufacture);
        }

        return $results;
    }

    private function analyseComponent(
        RecipeComponent $component,
        string $warehouseId,
        string $companyId,
        float $qtyToManufacture,
    ): RawMaterialAvailability {
        // Scale: absolute quantity needed for the full manufacturing run
        $requiredQty = $component->quantity * $qtyToManufacture;
        $availableQty = $this->inventory->availableQty($warehouseId, $component->component_id, $companyId);
        $missingQty = max(0.0, $requiredQty - $availableQty);

        // RC-2: satisfied when stock covers the need OR negative stock is permitted
        $isSatisfied = $missingQty === 0.0 || $component->allow_negative_stock;

        return new RawMaterialAvailability(
            component_id: $component->component_id,
            sku: $component->sku,
            name: $component->name,
            unit_symbol: $component->unit_symbol,
            required_qty: $requiredQty,
            available_qty: $availableQty,
            missing_qty: $missingQty,
            allow_negative_stock: $component->allow_negative_stock,
            is_satisfied: $isSatisfied,
        );
    }

    /**
     * Derives ManufacturingEligibility from the per-component analysis.
     *
     * @param  list<RawMaterialAvailability>  $materials
     */
    private function classifyEligibility(array $materials): ManufacturingEligibility
    {
        if ($materials === []) {
            return ManufacturingEligibility::CanManufacture;
        }

        $hasHardBlocker = false;
        $hasSoftShortage = false;

        foreach ($materials as $material) {
            if ($material->missing_qty > 0.0) {
                if ($material->allow_negative_stock) {
                    $hasSoftShortage = true;
                } else {
                    $hasHardBlocker = true;
                }
            }
        }

        if ($hasHardBlocker) {
            return ManufacturingEligibility::CannotManufacture;
        }

        if ($hasSoftShortage) {
            return ManufacturingEligibility::Partial;
        }

        return ManufacturingEligibility::CanManufacture;
    }

    private function sufficientResult(
        string $productId,
        string $warehouseId,
        float $requiredQty,
        float $availableFg,
        string $evaluatedAt,
    ): AvailabilityResult {
        return new AvailabilityResult(
            product_id: $productId,
            warehouse_id: $warehouseId,
            required_qty: $requiredQty,
            available_finished_goods: $availableFg,
            qty_to_manufacture: 0.0,
            needs_manufacturing: false,
            recipe_snapshot: null,
            raw_materials: [],
            can_manufacture: true,
            eligibility: ManufacturingEligibility::Sufficient,
            evaluated_at: $evaluatedAt,
        );
    }

    private function noRecipeResult(
        string $productId,
        string $warehouseId,
        float $requiredQty,
        float $availableFg,
        float $qtyToManufacture,
        string $evaluatedAt,
    ): AvailabilityResult {
        return new AvailabilityResult(
            product_id: $productId,
            warehouse_id: $warehouseId,
            required_qty: $requiredQty,
            available_finished_goods: $availableFg,
            qty_to_manufacture: $qtyToManufacture,
            needs_manufacturing: true,
            recipe_snapshot: null,
            raw_materials: [],
            can_manufacture: false,
            eligibility: ManufacturingEligibility::NoRecipe,
            evaluated_at: $evaluatedAt,
        );
    }
}
