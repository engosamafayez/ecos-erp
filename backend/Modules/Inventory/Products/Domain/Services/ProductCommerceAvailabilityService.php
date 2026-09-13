<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Domain\Services;

use Modules\Inventory\InventoryItems\Domain\Services\InventorySummaryService;
use Modules\Inventory\Products\Domain\Enums\ProductAvailability;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ManufacturingAvailabilityService;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 — THE canonical "is this Product commercially
 * available" authority, living in Product's own domain (not Commerce, not a channel-sync
 * concern). Reconciles two pre-existing, narrower authorities rather than inventing a third:
 *
 *   InventorySummaryService + ProductAvailability — physical on-hand/reserved stock + the
 *   allow_negative_stock policy. ProductAvailability's own docblock already declares itself
 *   canonical for "any product-facing surface — API, table, drawer, filter".
 *
 *   ManufacturingAvailabilityService — recipe/BOM executability against raw-material stock.
 *   Already the exact fallback ReserveOrderInventoryAction consults when a finished good's own
 *   physical stock is insufficient.
 *
 * This applies the SAME precedence ReserveOrderInventoryAction's order-line reservation loop
 * already established — physical stock first (never gated by the recipe), executable recipe
 * as fallback for a made-to-order finished good, allow_negative_stock as the final fallback —
 * exposed here as a standalone, reusable, non-order-scoped fact instead of logic embedded
 * inline in one specific order action. Not a fourth formula: the same reconciliation,
 * generalized to answer "is Product X available right now" outside any specific order.
 */
final class ProductCommerceAvailabilityService
{
    public function __construct(
        private readonly InventorySummaryService $inventorySummary,
        private readonly ManufacturingAvailabilityService $manufacturing,
    ) {}

    public function isAvailable(Product $product): bool
    {
        $summary = $this->inventorySummary->summarize($product->id, $product->company_id);
        $physical = ProductAvailability::project($summary->available, (bool) $product->allow_negative_stock);

        // Case 1 (ReserveOrderInventoryAction) — physical stock is available (or
        // allow_negative_stock already makes $physical NegativeAllowed, never OutOfStock)
        // and is NEVER gated by recipe executability.
        if ($physical !== ProductAvailability::OutOfStock) {
            return true;
        }

        // Case 2 — made-to-order: physical stock is short, but an executable recipe means
        // more can be produced on demand. Scoped exactly as ManufacturingAvailabilityService
        // itself is scoped: finished goods with an active recipe only. A non-finished-good
        // product, or a finished good with no active recipe ('recipe_missing'), has no
        // manufacturing path and falls through to the physical-stock answer above — which,
        // having already failed, means genuinely not available (Case 3's allow_negative_stock
        // was already accounted for in $physical).
        if ($product->product_type === Product::TYPE_FINISHED_GOOD) {
            $evaluation = $this->manufacturing->evaluate($product);

            if ($evaluation['status'] === 'instock') {
                return true;
            }
        }

        return false;
    }
}
