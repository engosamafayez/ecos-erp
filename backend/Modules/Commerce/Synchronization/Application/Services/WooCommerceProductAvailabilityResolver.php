<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Modules\Inventory\InventoryItems\Domain\Services\InventorySummaryService;
use Modules\Inventory\Products\Domain\Enums\ProductAvailability;
use Modules\Inventory\Products\Domain\Enums\ProductStockStatus;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R1 (CTO business-rule correction) — the ONE
 * ECOS→Woo product-availability authority.
 *
 * CTO ruling: ECOS warehouse stock is raw-material-level inventory; WooCommerce must never
 * receive or own a finished-product quantity. A sellable product's availability is a
 * boolean/state fact — AVAILABLE or NOT AVAILABLE — derived entirely from ECOS's own
 * already-canonical authority, never recomputed here:
 *
 *   InventorySummaryService::summarize() — "the single source of truth for inventory
 *   quantities... replaces the ~10 ad-hoc availability calculations scattered across the
 *   platform" — produces the canonical signed `available` figure (Σ per-warehouse
 *   max(on_hand − reserved, 0)).
 *
 *   ProductAvailability::project($available, $allowNegative) — "the BUSINESS availability
 *   of a product... what any product-facing surface — API, table, drawer, filter — must
 *   speak," per its own docblock. This is that authoritative, already-declared-canonical
 *   answer; this class does not invent a second one.
 *
 * ProductAvailability::NegativeAllowed maps to Woo `instock`: the business has explicitly
 * decided this product remains sellable despite zero/negative physical availability, so Woo
 * must keep advertising it — only a genuine OutOfStock (no stock, no negative-stock policy)
 * maps to Woo `outofstock`.
 *
 * KNOWN GAP (reported, not solved here — see remediation report): for a made-to-order
 * finished good with an executable preparation recipe but zero direct on-hand stock,
 * ReserveOrderInventoryAction's own order-line reservation logic additionally treats it as
 * committable via ManufacturingAvailabilityService::evaluate() — a narrower service, scoped
 * to "can this recipe be executed right now", consumed only inside that one reservation
 * decision, never declared or used elsewhere as a general per-product availability answer.
 * No existing standalone authority composes "physical stock OR executable recipe OR
 * allow-negative" into one reusable, non-order-scoped fact, so that composition is
 * deliberately NOT invented here (doing so inside Commerce would be exactly the
 * Commerce-local availability formula the CTO ruling forbids). A recipe-backed product with
 * zero physical stock will therefore report NOT AVAILABLE to Woo even when ECOS's own order
 * flow would still accept and fulfil an order for it.
 */
final class WooCommerceProductAvailabilityResolver
{
    public function __construct(
        private readonly InventorySummaryService $summary,
    ) {}

    public function resolve(Product $product): ProductStockStatus
    {
        $summary = $this->summary->summarize($product->id, $product->company_id);

        $business = ProductAvailability::project($summary->available, (bool) $product->allow_negative_stock);

        return $business === ProductAvailability::OutOfStock
            ? ProductStockStatus::OutOfStock
            : ProductStockStatus::InStock;
    }
}
