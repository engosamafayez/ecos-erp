<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Enums;

enum OrderStatus: string
{
    case Pending           = 'pending';
    case InProgress        = 'in_progress';
    case Processing        = 'processing';
    case Preparing         = 'preparing';
    case ReadyForLoading   = 'ready_for_loading';
    case AwaitingPayment   = 'awaiting_payment';
    case ConfirmOrder      = 'confirm_order';
    case Completed         = 'completed';
    case Cancelled         = 'cancelled';
    case OutForDelivery    = 'out_for_delivery';
    case Returned          = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending           => 'Pending',
            self::InProgress        => 'In Progress',
            self::Processing        => 'Processing',
            self::Preparing         => 'Preparing',
            self::ReadyForLoading   => 'Ready for Loading',
            self::AwaitingPayment   => 'Awaiting Payment',
            self::ConfirmOrder      => 'Confirm Order',
            self::Completed         => 'Completed',
            self::Cancelled         => 'Cancelled',
            self::OutForDelivery    => 'Out for Delivery',
            self::Returned          => 'Returned',
        };
    }
}
