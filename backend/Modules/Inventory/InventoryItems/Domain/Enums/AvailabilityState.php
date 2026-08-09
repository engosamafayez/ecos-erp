<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Domain\Enums;

/**
 * The ERP's own answer to "is this product available?".
 *
 * TASK-PHASE3-STEP1-AVAILABILITY-STATE-001 (EPIC-ENTERPRISE-GOLIVE-001, RC-9).
 *
 * This is a PROJECTION of the canonical `available` figure produced by
 * InventorySummaryService — not a second calculation. It exists so a screen can
 * render an availability *state* without inventing its own threshold, and so the
 * state can never disagree with the quantity printed beside it.
 *
 * DISTINCT FROM `products.stock_status`. That column is a WooCommerce channel
 * attribute (instock / outofstock / onbackorder), written by the inbound importer
 * and never published back out (E-3). It describes what the storefront
 * advertises; this enum describes what the ERP actually holds. RC-9 exists
 * because those two facts were displayed as if they were one.
 *
 * The rule is taken from the platform's existing demand-independent branches in
 * Operations\DemandAnalysis\Application\Services\DemandAnalysisService::buildDemandLine():
 * no inventory record → Unknown, quantity <= 0 → OutOfStock, otherwise stocked.
 * DemandAnalysis' Shortage case is deliberately NOT mirrored — it compares stock
 * against an ordered quantity, which has no meaning for a product-level state.
 */
enum AvailabilityState: string
{
    /** No inventory record exists for this product — not yet tracked in any warehouse. */
    case Untracked = 'untracked';

    /** Tracked, but nothing is available to commit. */
    case OutOfStock = 'out_of_stock';

    /** Available quantity is greater than zero. */
    case InStock = 'in_stock';

    /**
     * The single projection of a canonical available quantity onto a state.
     *
     * Every consumer calls this — InventorySummaryService for the per-product
     * summary, ProductResource for list/detail rows. There is deliberately no
     * second place where the rule is written, so a grid can never disagree with
     * a detail panel.
     *
     * @param  float|null  $available  Canonical clamp-per-warehouse-then-sum quantity.
     *                                 Null means no inventory record exists at all,
     *                                 which is distinct from a tracked zero.
     */
    public static function fromAvailable(?float $available): self
    {
        return match (true) {
            $available === null => self::Untracked,
            $available <= 0.0 => self::OutOfStock,
            default => self::InStock,
        };
    }
}
