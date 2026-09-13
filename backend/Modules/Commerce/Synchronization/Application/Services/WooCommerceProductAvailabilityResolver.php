<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Modules\Inventory\Products\Domain\Enums\ProductStockStatus;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\Products\Domain\Services\ProductCommerceAvailabilityService;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R1/R2/R2-R1 (CTO business-rule correction) — the ONE
 * ECOS→Woo availability MAPPING. All availability DECISION logic lives in
 * ProductCommerceAvailabilityService (Product's own domain, reconciling physical stock and
 * recipe/raw-material executability) — this class does nothing but translate that one boolean
 * into Woo's own vocabulary. No Commerce-local formula.
 */
final class WooCommerceProductAvailabilityResolver
{
    public function __construct(
        private readonly ProductCommerceAvailabilityService $availability,
    ) {}

    public function resolve(Product $product): ProductStockStatus
    {
        return $this->availability->isAvailable($product)
            ? ProductStockStatus::InStock
            : ProductStockStatus::OutOfStock;
    }
}
