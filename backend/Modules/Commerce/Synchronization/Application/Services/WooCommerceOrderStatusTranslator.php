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
    /**
     * WooCommerce status → ECOS OrderStatus backing value.
     *
     * Right-hand values follow the canonical lifecycle of ADR-042:
     *   pending → in_progress, processing → in_progress, completed → delivered.
     *
     * THIS MAP HAS NOW BROKEN TWICE FOR THE SAME REASON. Before V3 it pointed at
     * ECOS statuses that the enum later dropped, so tryFrom() returned null and
     * every imported pending/processing WooCommerce order silently lost its
     * status. V3 repointed 'pending' at 'new'; ADR-042 removes 'new', which would
     * have reproduced the failure — worse this time, because the importer's
     * fallback then calls OrderStatus::from('pending') and throws.
     *
     * The right-hand side is a lifecycle status and must be revisited by every
     * task that changes OrderStatus. It is asserted by a test for that reason.
     */
    private const MAP = [
        'pending' => 'in_progress',
        'on-hold' => 'awaiting_payment',
        'processing' => 'in_progress',
        'completed' => 'delivered',
        'cancelled' => 'cancelled',
        'refunded' => 'returned',
        'failed' => 'cancelled',
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
