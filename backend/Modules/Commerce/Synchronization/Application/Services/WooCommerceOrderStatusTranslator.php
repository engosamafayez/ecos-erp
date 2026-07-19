<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;

/**
 * Single source of truth for WooCommerce → ECOS order status translation.
 *
 * Both WooCommerceOrderImporter and ProcessOrderWebhookJob must use this class.
 * No other code should define a WC status mapping table.
 */
final class WooCommerceOrderStatusTranslator
{
    private const MAP = [
        'pending'    => 'pending',
        'on-hold'    => 'awaiting_payment',
        'processing' => 'processing',
        'completed'  => 'delivered',
        'cancelled'  => 'cancelled',
        'refunded'   => 'returned',
        'failed'     => 'cancelled',
    ];

    /**
     * Translate a WooCommerce order status string to an ECOS OrderStatus enum.
     *
     * Returns null when the WC status has no meaningful ECOS equivalent
     * (e.g. 'trash', plugin-custom statuses).
     */
    public function translate(string $wcStatus): ?OrderStatus
    {
        $value = self::MAP[strtolower($wcStatus)] ?? null;

        return $value !== null ? OrderStatus::tryFrom($value) : null;
    }

    public function hasMapping(string $wcStatus): bool
    {
        return isset(self::MAP[strtolower($wcStatus)]);
    }
}
