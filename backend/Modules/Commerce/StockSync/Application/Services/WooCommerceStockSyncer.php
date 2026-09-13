<?php

declare(strict_types=1);

namespace Modules\Commerce\StockSync\Application\Services;

use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher;
use Modules\Inventory\Products\Domain\Enums\ProductStockStatus;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R1/R2/R2-R1 (CTO business-rule correction) — Woo
 * receives an absolute availability STATE, never a finished-product quantity.
 * `manage_stock: false` tells WooCommerce explicitly that IT does not own/maintain a numeric
 * stock counter for this product — ECOS is the sole authority, mirrored one-way, ECOS → Woo.
 * Retrying the same `stock_status` value is safe and idempotent (an absolute PUT, not an
 * increment).
 *
 * Transport itself (direct Woo REST vs the paired Connector plugin) is decided by
 * WooOutboundCommandDispatcher, not here — this class only shapes the WooCommerce field
 * payload, exactly as before.
 */
final class WooCommerceStockSyncer
{
    public function __construct(
        private readonly WooOutboundCommandDispatcher $dispatcher,
    ) {}

    public function updateAvailability(Channel $channel, string $externalProductId, ProductStockStatus $status): bool
    {
        $result = $this->dispatcher->put($channel, 'products', $externalProductId, [
            'stock_status' => $status->value,
            'manage_stock' => false,
        ]);

        return $result->ok;
    }
}
