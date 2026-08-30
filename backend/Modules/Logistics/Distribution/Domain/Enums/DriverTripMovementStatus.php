<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Enums;

/**
 * The operational lifecycle of a driver trip movement
 * (TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §35/§40).
 *
 * A driver-created movement always starts Pending — the driver may NOT self-approve or
 * self-settle. Only an Approved movement affects the driver's operational Expense / Net-Cash
 * totals (§40/§41); Pending and Rejected never do. This is an OPERATIONAL lifecycle, not a
 * General-Ledger posting.
 */
enum DriverTripMovementStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Settled = 'settled';

    /** Approved (and its terminal Settled) are the only states that count toward driver totals. */
    public function countsTowardTotals(): bool
    {
        return in_array($this, [self::Approved, self::Settled], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
