<?php

declare(strict_types=1);

namespace Modules\Commerce\StockSync\Application\Services;

use Illuminate\Support\Facades\Http;
use Modules\Inventory\Products\Domain\Enums\ProductStockStatus;
use Throwable;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R1 (CTO business-rule correction) — Woo receives an
 * absolute availability STATE, never a finished-product quantity. `manage_stock: false` tells
 * WooCommerce explicitly that IT does not own/maintain a numeric stock counter for this
 * product — ECOS is the sole authority, mirrored one-way, ECOS → Woo. Retrying the same
 * `stock_status` value is safe and idempotent (an absolute PUT, not an increment).
 */
final class WooCommerceStockSyncer
{
    public function updateAvailability(
        string $storeUrl,
        string $consumerKey,
        string $consumerSecret,
        string $externalProductId,
        ProductStockStatus $status,
    ): bool {
        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(15)
                ->put(
                    rtrim($storeUrl, '/').'/wp-json/wc/v3/products/'.$externalProductId,
                    [
                        'stock_status' => $status->value,
                        'manage_stock' => false,
                    ],
                );

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
