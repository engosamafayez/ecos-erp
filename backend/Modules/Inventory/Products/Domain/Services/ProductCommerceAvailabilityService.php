<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Domain\Services;

use Modules\Inventory\InventoryItems\Domain\Services\InventorySummaryService;
use Modules\Inventory\Products\Domain\Enums\ProductAvailability;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ManufacturingAvailabilityService;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R3 (CTO business-rule correction, superseding
 * R2-R2's no-recipe fallback) — THE canonical "is this Product commercially available"
 * authority, living in Product's own domain.
 *
 * FINAL BUSINESS RULE: warehouse physical stock is raw-material stock. A Finished Good
 * (product_type === TYPE_FINISHED_GOOD) NEVER uses its own physical InventoryItem on_hand
 * quantity as a sellability signal, in any circumstance — not when a Recipe is executable, not
 * when it is blocked, and not when no Recipe exists at all. Availability for every Finished
 * Good is manufacturing/recipe-derived, unconditionally:
 *
 *   active Recipe, executable       -> AVAILABLE
 *   active Recipe, NOT executable   -> NOT AVAILABLE
 *   no active Recipe ('recipe_missing') -> NOT AVAILABLE
 *
 * all regardless of the Finished Good's own on_hand quantity, which is never read in this
 * branch. This is deliberately NOT a fallback to physical stock for the no-recipe case (R2-R2's
 * behavior, now superseded): today's Product domain has exactly three types
 * (finished_good/raw_material/packaging_material) and no fourth "direct-stock/resale finished
 * good" classification — introducing one is explicitly out of scope for this correction (a
 * separate Product-taxonomy decision if the business ever needs it). Until such a
 * classification exists, TYPE_FINISHED_GOOD means manufacturing-derived availability, full
 * stop.
 *
 * Raw materials and packaging materials (product_type !== TYPE_FINISHED_GOOD) use the physical
 * on-hand/reserved authority (InventorySummaryService + ProductAvailability, including their
 * own allow_negative_stock policy) — unchanged, and ManufacturingAvailabilityService is never
 * consulted for them (it is scoped to finished goods only).
 *
 * `can_manufacture` remains deliberately unused as a classification signal: ADR-027 §16 v1.5
 * (see ReserveOrderInventoryAction's own docblock) already, deliberately, removed that flag
 * from gating whether Recipe-based reasoning applies to a Finished Good.
 *
 * allow_negative_stock interaction: for a Finished Good, only each RAW MATERIAL component's own
 * allow_negative_stock is consulted — inside ManufacturingAvailabilityService itself, which
 * already implements this; the Finished Good's own flag is never an independent override. It
 * still applies normally, via ProductAvailability::project(), for raw materials and packaging
 * materials.
 */
final class ProductCommerceAvailabilityService
{
    public function __construct(
        private readonly InventorySummaryService $inventorySummary,
        private readonly ManufacturingAvailabilityService $manufacturing,
    ) {}

    public function isAvailable(Product $product): bool
    {
        if ($product->product_type === Product::TYPE_FINISHED_GOOD) {
            // The SOLE authority for every Finished Good, unconditionally — including
            // 'recipe_missing', which resolves NOT AVAILABLE exactly like 'outofstock'.
            // Physical on_hand is never read in this branch.
            return $this->manufacturing->evaluate($product)['status'] === 'instock';
        }

        $summary = $this->inventorySummary->summarize($product->id, $product->company_id);
        $physical = ProductAvailability::project($summary->available, (bool) $product->allow_negative_stock);

        return $physical !== ProductAvailability::OutOfStock;
    }
}
