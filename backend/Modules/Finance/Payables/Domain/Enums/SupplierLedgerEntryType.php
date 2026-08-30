<?php

declare(strict_types=1);

namespace Modules\Finance\Payables\Domain\Enums;

/**
 * The kinds of movement that appear on the supplier ledger.
 *
 * A positive amount increases what we owe (a bill / debit note); a negative
 * decreases it (a credit note / a payment). Mirror of the customer ledger.
 */
enum SupplierLedgerEntryType: string
{
    case Bill = 'bill';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case Payment = 'payment';
    // TASK-PROC-SUPPLIER-OPENING-BALANCE-001 (additive, no migration — entry_type is an
    // unconstrained string(20)). OpeningPayable is a pre-ECOS liability owed to the supplier
    // (behaves like a bill on the payable). Advance is a prepaid asset paid to the supplier
    // (a credit position) — kept in its OWN display bucket, never netted into the payable label.
    case OpeningPayable = 'opening_payable';
    case Advance = 'advance';

    /** +1 increases the payable (we owe more), −1 decreases it. */
    public function sign(): int
    {
        return match ($this) {
            self::Bill, self::DebitNote, self::OpeningPayable => 1,
            self::CreditNote, self::Payment, self::Advance => -1,
        };
    }

    /**
     * Advance entries are a prepaid-asset credit shown SEPARATELY as "Available Advance",
     * never inside the Outstanding Payable figure. All other types are payable movements.
     */
    public function isAdvance(): bool
    {
        return $this === self::Advance;
    }
}
