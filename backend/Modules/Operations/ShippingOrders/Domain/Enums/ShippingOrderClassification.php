<?php

declare(strict_types=1);

namespace Modules\Operations\ShippingOrders\Domain\Enums;

/**
 * Shipping Orders page classification — TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-
 * IMPLEMENTATION-002. A read-model presentation concept only: derived fresh on every
 * request from DeliveryStop/DeliveryAction/LoadingTask evidence (see
 * ShippingOrderClassificationService), never persisted, and never a second
 * OrderStatus/state-machine engine. Per Architecture-001 §28 ("Loading / Custody
 * Boundary") and 001-R1 §18 ("OrderStatus Boundary"), Commerce OrderStatus and
 * `orders.inventory_shipped_at` are NOT read anywhere in this classification — both
 * are disconnected from the live Distribution Group/Trip flow.
 */
enum ShippingOrderClassification: string
{
    case AssignedDriver = 'assigned_driver';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Postponed = 'postponed';
    case NoAnswer = 'no_answer';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
