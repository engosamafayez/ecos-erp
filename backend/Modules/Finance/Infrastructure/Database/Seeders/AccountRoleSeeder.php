<?php

declare(strict_types=1);

namespace Modules\Finance\Infrastructure\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account role → GL account mapping (TASK-FIN-003).
 *
 * ┌─ WHY THIS EXISTS ───────────────────────────────────────────────────────┐
 * │ Posting rules address accounts by ROLE, never by code — a rule says      │
 * │ "debit inventory, credit grni", not "debit 1410, credit 2120". That      │
 * │ indirection is what lets one rule set serve companies with different     │
 * │ charts. AccountRoleResolver turns a role into an account id, and throws  │
 * │ accountRoleNotMapped when it cannot.                                    │
 * │                                                                          │
 * │ finance_account_roles shipped empty, so every posting resolved nothing   │
 * │ and was dead-lettered. The pipeline was live and correct and produced no │
 * │ journals.                                                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ WHAT IS DELIBERATELY NOT MAPPED ───────────────────────────────────────┐
 * │ Four roles required by existing rules are NOT mapped here, because the   │
 * │ Chart of Accounts does not name a single obvious account for them and    │
 * │ guessing would post real money to the wrong place:                      │
 * │                                                                          │
 * │   inventory       1400 Inventory is a non-postable parent. The postable  │
 * │                   children are 1410/1420/1430/1440 by class. A generic   │
 * │                   "inventory" leg has no single correct target.          │
 * │   sales_revenue   4100 Sales Revenue is a non-postable parent; 4110      │
 * │                   Product Sales and 4120 POS Sales are both plausible.   │
 * │   pos_clearing    No POS clearing account exists. 1130 Cash in Transit   │
 * │                   is the nearest, but that is an inference, not a name.  │
 * │   loyalty_expense Points EARNED create a cost; 4240 Loyalty Redemptions  │
 * │                   is about redemption. Earning is not redeeming.        │
 * │                                                                          │
 * │ Resolving these means either adding a postable control account or        │
 * │ splitting the rules by inventory class — a Chart of Accounts or posting  │
 * │ rule decision, both outside a configuration task. Until then the         │
 * │ affected events dead-letter, which is the safe failure.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * IDEMPOTENT: keyed on (company_id, role). Re-running never overwrites a
 * mapping a company has since re-pointed; it only fills what is missing.
 */
class AccountRoleSeeder extends Seeder
{
    /**
     * role => [account code, why this account]
     *
     * Every entry is a direct name correspondence between the role and an
     * existing postable account. No account is created, renamed or moved.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function definitions(): array
    {
        return [
            // ── Balance sheet — assets ───────────────────────────────────────
            'cash' => ['1110', 'Cash on Hand'],
            'inventory_in_transit' => ['1450', 'Goods In Transit'],
            'finished_goods' => ['1410', 'Finished Goods'],
            'raw_materials' => ['1420', 'Raw Materials'],
            'wip' => ['1430', 'Work In Progress'],
            'vat_input' => ['1530', 'VAT Receivable (Input)'],
            'ar_control' => ['1310', 'Trade Receivables — control, subledger receivables'],

            // ── Balance sheet — liabilities ──────────────────────────────────
            'ap_control' => ['2110', 'Trade Payables — control, subledger payables'],
            'grni' => ['2120', 'Goods Received Not Invoiced'],
            'carrier_payable' => ['2130', 'Shipping Payables'],
            'vat_output' => ['2210', 'VAT Payable (Output)'],
            'loyalty_liability' => ['2430', 'Loyalty Points Liability'],
            'refund_clearing' => ['2440', 'Refunds Payable'],

            // ── Revenue and revenue deductions ───────────────────────────────
            'sales_returns' => ['4210', 'Sales Returns'],
            'sales_discount' => ['4220', 'Sales Discounts'],
            'coupon_expense' => ['4230', 'Coupon Redemptions — contra-revenue, debit normal'],
            'inventory_adjustment_gain' => ['4920', 'Inventory Gain'],

            // ── Cost of sales ────────────────────────────────────────────────
            'scrap_expense' => ['5150', 'Scrap & Rework'],
            'inventory_writeoff_expense' => ['5160', 'Inventory Write-Off'],
            'inventory_adjustment_loss' => ['5170', 'Inventory Loss'],

            // ── Operating expenses ───────────────────────────────────────────
            'shipping_expense' => ['5550', 'Shipping & Delivery'],
            'marketing_credit_expense' => ['5560', 'Marketing & Advertising'],
        ];
    }

    public function run(): void
    {
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $this->seedCompany((string) $companyId);
        }
    }

    /** Seed one company. Safe to call repeatedly. Returns rows created. */
    public function seedCompany(string $companyId): int
    {
        $accounts = DB::table('finance_accounts')
            ->where('company_id', $companyId)
            ->pluck('id', 'code');

        $existing = DB::table('finance_account_roles')
            ->where('company_id', $companyId)
            ->pluck('role')
            ->flip();

        $created = 0;
        $now = now();

        foreach ($this->definitions() as $role => [$code, $description]) {
            if ($existing->has($role)) {
                continue;
            }

            $accountId = $accounts[$code] ?? null;

            if ($accountId === null) {
                // The chart has not been seeded for this company, or the code was
                // re-pointed. Skip rather than map the role to nothing — a null
                // account is exactly the failure this seeder exists to remove.
                continue;
            }

            DB::table('finance_account_roles')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'role' => $role,
                'account_id' => $accountId,
                'description' => $code.' — '.$description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
        }

        return $created;
    }
}
