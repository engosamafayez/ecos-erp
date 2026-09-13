<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Domain\Services;

use Modules\Inventory\InventoryItems\Domain\Services\InventorySummaryService;
use Modules\Inventory\Products\Domain\Enums\ProductAvailability;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ManufacturingAvailabilityService;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R2 (CTO business-rule correction, superseding the
 * R2-R1 precedence) — THE canonical "is this Product commercially available" authority, living
 * in Product's own domain.
 *
 * FINAL BUSINESS RULE: warehouse physical stock = raw-material stock. A manufactured finished
 * Product's OWN physical on_hand quantity is never trusted as an independent sellability
 * signal — it can be stale/legacy and must never override an unavailable Recipe. For a
 * manufactured Product, Recipe/Raw-Material executability (ManufacturingAvailabilityService,
 * already the canonical authority for that question) is the SOLE answer:
 *
 *   Recipe exists, executable        -> AVAILABLE, regardless of the finished good's own
 *                                        on_hand quantity (even if it happens to be positive
 *                                        but stale, or exactly zero).
 *   Recipe exists, NOT executable    -> NOT AVAILABLE, regardless of the finished good's own
 *                                        on_hand quantity (even if some legacy/stale physical
 *                                        quantity is still sitting in InventoryItem).
 *   No active Recipe for this        -> Recipe-based reasoning does not apply to this specific
 *   finished good ("recipe_missing")    Product at all; the only remaining canonical signal is
 *                                        physical stock (InventorySummaryService +
 *                                        ProductAvailability, exactly as for a non-manufactured
 *                                        Product). This is a fallback for an inapplicable
 *                                        Recipe check, not a "has stock" classification guess —
 *                                        the branch is decided by product_type, an explicit,
 *                                        pre-existing Product classification, before the Recipe
 *                                        check ever runs.
 *
 * Raw materials and packaging materials (product_type !== TYPE_FINISHED_GOOD) always use the
 * physical-stock authority — ManufacturingAvailabilityService's own scope never applies to
 * them (it returns 'recipe_missing' for exactly this reason), and there is no Recipe concept to
 * consult.
 *
 * `can_manufacture` (a Product column) is deliberately NOT used as the classification signal
 * here: ADR-027 §16 v1.5 (see ReserveOrderInventoryAction's own docblock) already, deliberately,
 * removed that flag from gating whether Recipe-based fulfillment logic applies — reintroducing
 * it here would reopen an already-ratified architecture decision this ticket does not ask to
 * revisit, not merely reuse an existing signal.
 *
 * allow_negative_stock interaction: for the Recipe-authority branch, only each RAW MATERIAL
 * component's own allow_negative_stock is consulted — inside ManufacturingAvailabilityService
 * itself, which already implements this. The finished good's OWN allow_negative_stock flag is
 * not an independent override for a manufactured Product (that would let exactly the kind of
 * stale/legacy finished-product signal this rule forbids back in). It still applies normally
 * for the physical-stock branch (non-manufactured products, and a finished good with no active
 * Recipe at all) via ProductAvailability::project(), unchanged.
 *
 * OPEN ITEM (reported, not guessed): the current Product domain has no explicit classification
 * separating "manufactured finished good with no Recipe configured yet" from "a finished good
 * that is, by design, always direct-stock/resale and never intended to have a Recipe" —
 * product_type alone cannot distinguish them (both are TYPE_FINISHED_GOOD), and the ticket that
 * introduced this correction explicitly forbids inferring that distinction from Recipe-row
 * presence/absence. For that one sub-case (TYPE_FINISHED_GOOD, no active Recipe), this falls
 * back to the physical-stock authority as the only remaining canonical signal — a conservative
 * default, not an invented formula, but named here for explicit confirmation.
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
            $evaluation = $this->manufacturing->evaluate($product);

            if ($evaluation['status'] !== 'recipe_missing') {
                // A Recipe exists for this Product — it is the SOLE authority from here.
                // Physical finished-product on_hand is never consulted and can never override
                // this answer in either direction.
                return $evaluation['status'] === 'instock';
            }

            // No active Recipe at all — Recipe-based reasoning is inapplicable to this
            // specific Product; fall through to the physical-stock authority below.
        }

        $summary = $this->inventorySummary->summarize($product->id, $product->company_id);
        $physical = ProductAvailability::project($summary->available, (bool) $product->allow_negative_stock);

        return $physical !== ProductAvailability::OutOfStock;
    }
}
