<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Enums;

enum OrderStatus: string
{
    // ── Main lifecycle ──────────────────────────────────────────────────
    case Pending         = 'pending';
    case Scheduled       = 'scheduled';
    case AwaitingPayment = 'awaiting_payment';
    case Processing      = 'processing';
    case AwaitingStock   = 'awaiting_stock';
    case Confirmed       = 'confirmed';
    case Preparing       = 'preparing';
    case OutForDelivery  = 'out_for_delivery';
    case Delivered       = 'delivered';
    case Completed       = 'completed';

    // ── Exceptional states ──────────────────────────────────────────────
    case Cancelled       = 'cancelled';
    case Review          = 'review';
    case Returned        = 'returned';
    case Rescheduled     = 'rescheduled';

    public function label(): string
    {
        return match ($this) {
            self::Pending         => 'Pending',
            self::Scheduled       => 'Scheduled',
            self::AwaitingPayment => 'Payment',         // V2: renamed from "Awaiting Payment"
            self::Processing      => 'Processing',
            self::AwaitingStock   => 'Awaiting Stock',
            self::Confirmed       => 'Confirmed',
            self::Preparing       => 'Preparing',
            self::OutForDelivery  => 'Out for Delivery',
            self::Delivered       => 'Delivered',
            self::Completed       => 'Completed',
            self::Cancelled       => 'Cancelled',
            self::Review          => 'Review',
            self::Returned        => 'Returned',
            self::Rescheduled     => 'Rescheduled',
        };
    }

    /**
     * V2: Only Completed is terminal. Cancelled is recoverable — orders may be reopened.
     * Completed = financial closure. True end-of-life.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Structural lock: product/price/shipping data becomes immutable from Processing onward.
     * The only way to unlock is to return the order to Pending or Payment (AwaitingPayment).
     */
    public function isLocked(): bool
    {
        return ! in_array($this, [self::Pending, self::Scheduled, self::AwaitingPayment], true);
    }

    /** Returns true if the order is pre-activation (not yet in the operational queue). */
    public function isPreActivation(): bool
    {
        return $this === self::Scheduled;
    }

    /**
     * Official V2 display order across all dashboards, filters, and analytics.
     * Order: Pending → Payment → Processing → Confirmed → Preparing → Out for Delivery
     *        → Delivered → Returned → Awaiting Stock → Rescheduled → Review → Cancelled → Completed
     */
    public static function displayOrder(): array
    {
        return [
            self::Scheduled,
            self::Pending,
            self::AwaitingPayment,
            self::Processing,
            self::Confirmed,
            self::Preparing,
            self::OutForDelivery,
            self::Delivered,
            self::Returned,
            self::AwaitingStock,
            self::Rescheduled,
            self::Review,
            self::Cancelled,
            self::Completed,
        ];
    }
}
