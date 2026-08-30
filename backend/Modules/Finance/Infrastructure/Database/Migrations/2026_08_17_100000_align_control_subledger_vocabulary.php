<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-1 — align `finance_accounts.control_subledger` with the vocabulary its readers use.
 *
 * THE DEFECT. `ControlAccountResolver` resolves control accounts by an exact string:
 * `receivable()` looks for `'ar'`, `payable()` for `'ap'`. `ChartOfAccountsSeeder` wrote
 * `'receivables'` and `'payables'`. Nothing matched, so BOTH resolvers threw
 * "No AP/AR control account is configured" — and because `AccountsPayableService::postDocument()`
 * resolves the control account before anything else, the AP and AR subledgers were unusable
 * platform-wide. That is why `finance_supplier_bills` and `finance_supplier_ledger_entries` are
 * both empty despite a complete, working AP implementation.
 *
 * WHY 'ar'/'ap' IS THE CORRECT SIDE TO ALIGN. The accounts table migration documents the
 * intended vocabulary in the column itself — `// ar | ap | inventory | ...` — and the other two
 * seeded values (`inventory`, `vat`) already conform. `ControlAccountReconciliationService` also
 * uses 'ar'/'ap'. Changing the readers instead would entrench the only two values that diverge.
 *
 * SAFETY. Nothing reads the old strings: a repo-wide search finds `'payables'`/`'receivables'`
 * only as unrelated dashboard array keys, never against `control_subledger`. The only reader of
 * this column is `ControlAccountResolver`. No column is renamed, no account is created, deleted
 * or re-pointed, and no control account changes meaning — 2110 Trade Payables was already the AP
 * control and still is.
 *
 * Idempotent and reversible: it rewrites two values in place and only where they are present.
 */
return new class extends Migration
{
    /** old value => canonical value */
    private const ALIGNMENT = [
        'payables' => 'ap',
        'receivables' => 'ar',
    ];

    public function up(): void
    {
        $this->apply(self::ALIGNMENT);
    }

    public function down(): void
    {
        $this->apply(array_flip(self::ALIGNMENT));
    }

    /** @param array<string, string> $map */
    private function apply(array $map): void
    {
        if (! Schema::hasTable('finance_accounts')) {
            return;
        }

        foreach ($map as $from => $to) {
            DB::table('finance_accounts')
                ->where('is_control', true)
                ->where('control_subledger', $from)
                ->update(['control_subledger' => $to]);
        }
    }
};
