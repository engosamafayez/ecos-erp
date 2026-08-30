<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Enums;

/**
 * The cash direction of a driver trip operational movement
 * (TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §32/§57).
 *
 * The two directions are NOT interchangeable and must never be collapsed:
 *   CashOut — the driver SPENDS operational cash (fuel, road toll, other expense).
 *   CashIn  — the driver RECEIVES operational cash (an advance handed to them).
 *
 * An advance is therefore NOT an expense; it increases the cash in the driver's custody.
 */
enum DriverTripMovementDirection: string
{
    case CashOut = 'cash_out';
    case CashIn = 'cash_in';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
