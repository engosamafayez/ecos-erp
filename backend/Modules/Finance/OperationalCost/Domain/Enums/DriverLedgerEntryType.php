<?php

declare(strict_types=1);

namespace Modules\Finance\OperationalCost\Domain\Enums;

/**
 * The kind of movement on a driver's financial subledger. Sign is fixed by
 * the type (mirroring CustomerLedgerEntryType/SupplierLedgerEntryType): an
 * advance or an approved shortage increases what the driver owes; an
 * expense or a settlement decreases it.
 */
enum DriverLedgerEntryType: string
{
    case Advance = 'advance';
    case Expense = 'expense';
    case Shortage = 'shortage';
    case Settlement = 'settlement';

    public function sign(): int
    {
        return match ($this) {
            self::Advance, self::Shortage => 1,
            self::Expense, self::Settlement => -1,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
