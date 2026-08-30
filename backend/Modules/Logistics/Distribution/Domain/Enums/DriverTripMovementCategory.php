<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Enums;

/**
 * Approved driver trip operational-expense categories
 * (TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §31/§57).
 *
 *   Fuel / RoadToll / Other → the driver spends operational cash (CashOut, an EXPENSE).
 *   Advance                 → the driver receives operational cash (CashIn, NOT an expense).
 *
 * The direction is a pure function of the category — it is derived here, never guessed at the
 * call site, so an advance can never be mislabelled as an expense.
 */
enum DriverTripMovementCategory: string
{
    case Fuel = 'fuel';
    case RoadToll = 'road_toll';
    case Advance = 'advance';
    case Other = 'other';

    /** The cash direction this category always implies. */
    public function direction(): DriverTripMovementDirection
    {
        return $this === self::Advance
            ? DriverTripMovementDirection::CashIn
            : DriverTripMovementDirection::CashOut;
    }

    /**
     * Whether an APPROVED movement of this category counts toward the driver's operational
     * Expense total (§41). Only cash-out categories do; an advance never counts as an expense.
     */
    public function isExpense(): bool
    {
        return $this->direction() === DriverTripMovementDirection::CashOut;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
