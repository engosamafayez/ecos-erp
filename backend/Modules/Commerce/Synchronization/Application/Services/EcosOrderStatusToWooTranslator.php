<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;

/**
 * Single source of truth for ECOS → WooCommerce order status translation — the outbound sibling
 * of {@see WooCommerceOrderStatusTranslator} (inbound). Only OrderStatusSyncJob may call this; no
 * other code should define an ECOS→Woo status mapping table.
 *
 * TASK-...-024 found the previous inline map in OrderStatusSyncJob keyed on pre-ADR-042 status
 * strings ('pending'/'processing'/'completed') that no longer exist in OrderStatus — every
 * transition except Cancelled silently failed outbound. This rebuild is keyed on the CURRENT
 * OrderStatus backing values only.
 *
 * Deliberately NOT mapped (TASK-...-025 P0 — "do not invent mappings for ECOS states that should
 * not propagate to Woo"): ready_for_dispatch, out_for_delivery, awaiting_stock, scheduled. None
 * of Woo's 7 core statuses correspond to ECOS's own dispatch/delivery/stock/scheduling
 * granularity, and Task 024 found no confirmed custom Woo status to target instead — inventing
 * one would be a guess. An unmapped status is reported (SyncLog markFailed), never silently
 * treated as successful.
 */
final class EcosOrderStatusToWooTranslator
{
    /**
     * ECOS OrderStatus backing value → WooCommerce status slug.
     *
     * delivered → completed and cancelled → cancelled mirror the inbound map's
     * completed→delivered / cancelled→cancelled exactly. awaiting_payment and on_hold both
     * collapse onto Woo's single 'on-hold' bucket — a many-to-one collapse, the same shape the
     * inbound map already uses the other way (pending & processing → in_progress).
     */
    private const MAP = [
        'in_progress' => 'processing',
        'confirmed' => 'processing',
        'awaiting_payment' => 'on-hold',
        'on_hold' => 'on-hold',
        'delivered' => 'completed',
        'cancelled' => 'cancelled',
        'returned' => 'refunded',
    ];

    public function translate(OrderStatus $status): ?string
    {
        return self::MAP[$status->value] ?? null;
    }

    public function hasMapping(OrderStatus $status): bool
    {
        return isset(self::MAP[$status->value]);
    }
}
