<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseOrders\Domain\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
