<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Domain\Enums;

/**
 * TASK-...-026 §3 — reset selections are BUSINESS DOMAINS, never raw tables. Four domains only:
 * the ones this task's research confirmed a real, evidenced deletion order for. Master/Reference
 * data (§3.A) is deliberately NOT a case here — it is never a reset target, only ever preserved.
 */
enum ResetDomain: string
{
    case Commerce = 'commerce';
    case Operations = 'operations';
    case Inventory = 'inventory';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Commerce => 'Commerce Transactions (Orders, Payments, Reservations)',
            self::Operations => 'Operations Transactions (Preparation, Distribution, Trips)',
            self::Inventory => 'Inventory Transactions (Stock Movements)',
            self::Finance => 'Finance Transactions (Journals, Ledgers)',
        };
    }
}
