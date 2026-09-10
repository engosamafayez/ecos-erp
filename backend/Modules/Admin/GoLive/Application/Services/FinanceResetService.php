<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * TASK-...-026 §3.E/§10 — Finance domain reset.
 *
 * PRESERVES structure/configuration absolutely — this service never references
 * `finance_accounts` (Chart of Accounts), account roles, or tax configuration tables. It only
 * ever touches transactional postings and subledger entries.
 *
 * No FK ordering concerns with other domains: Finance's link to its source (an Order, a Supplier
 * Opening posting, etc.) is the polymorphic `source_module`/`source_event_id` string pair —
 * confirmed by reading the migration directly, never a real FK — so this domain is independent of
 * whether Commerce was also selected. `finance_journal_lines.journal_entry_id` IS a real cascade
 * FK, so deleting `finance_journal_entries` removes its lines automatically; deleted explicitly
 * first anyway for a clear, auditable per-table count.
 */
final class FinanceResetService
{
    public function previewCounts(string $companyId): array
    {
        $journalIds = $this->journalIds($companyId);

        return [
            'finance_journal_entries' => count($journalIds),
            'finance_supplier_ledger_entries' => DB::table('finance_supplier_ledger_entries')->where('company_id', $companyId)->count(),
            'finance_customer_ledger_entries' => DB::table('finance_customer_ledger_entries')->where('company_id', $companyId)->count(),
            'finance_driver_ledger_entries' => DB::table('finance_driver_ledger_entries')->where('company_id', $companyId)->count(),
        ];
    }

    public function execute(string $companyId): array
    {
        $counts = [];
        $journalIds = $this->journalIds($companyId);

        if ($journalIds !== []) {
            DB::table('finance_posted_event_receipts')->whereIn('journal_entry_id', $journalIds)->delete();
            $counts['finance_journal_lines'] = DB::table('finance_journal_lines')->whereIn('journal_entry_id', $journalIds)->delete();
        }

        $counts['finance_supplier_ledger_entries'] = DB::table('finance_supplier_ledger_entries')->where('company_id', $companyId)->delete();
        $counts['finance_customer_ledger_entries'] = DB::table('finance_customer_ledger_entries')->where('company_id', $companyId)->delete();
        $counts['finance_driver_ledger_entries'] = DB::table('finance_driver_ledger_entries')->where('company_id', $companyId)->delete();
        $counts['finance_customer_invoices'] = $this->safeDeleteWhereCompany('finance_customer_invoices', $companyId);
        $counts['finance_customer_receipts'] = $this->safeDeleteWhereCompany('finance_customer_receipts', $companyId);

        $counts['finance_journal_entries'] = $journalIds !== [] ? DB::table('finance_journal_entries')->whereIn('id', $journalIds)->delete() : 0;

        return $counts;
    }

    /** @return list<string> */
    private function journalIds(string $companyId): array
    {
        return DB::table('finance_journal_entries')->where('company_id', $companyId)->pluck('id')->all();
    }

    private function safeDeleteWhereCompany(string $table, string $companyId): int
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table) || ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id')) {
                return 0;
            }

            return DB::table($table)->where('company_id', $companyId)->delete();
        } catch (\Throwable) {
            return 0;
        }
    }
}
