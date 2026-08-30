<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Enums;

/**
 * Payment state — DERIVED from amount paid vs order total. This is NOT an Order
 * status (OrderStatus) and never becomes a second stored source of truth: it is
 * always computed from deposit_amount (amount received so far) vs total.
 *
 * TASK-WAREHOUSE-BRAND-PAYMENT-IMPLEMENTATION-001 §B — approved rule:
 *   paid <= 0            → UNPAID
 *   0 < paid < total     → PARTIALLY PAID   (a deposit is NOT "paid")
 *   paid >= total        → PAID             (full order amount received)
 */
enum PaymentState: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    /** Float-safe derivation. A tiny epsilon absorbs rounding on the "full" boundary. */
    public static function fromAmounts(float $paid, float $total): self
    {
        if ($paid <= 0.0) {
            return self::Unpaid;
        }

        if ($total <= 0.0 || $paid + 0.001 >= $total) {
            return self::Paid;
        }

        return self::PartiallyPaid;
    }
}
