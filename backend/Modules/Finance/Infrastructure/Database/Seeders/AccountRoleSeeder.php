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
 * ┌─ WHY 'inventory' IS NOT MAPPED (TASK-FIN-003A) ─────────────────────────┐
 * │ The approved inventory policy keeps stock separated by class. There is   │
 * │ deliberately NO single postable Inventory account: 1400 is a header, and │
 * │ the postable control accounts are                                        │
 * │                                                                          │
 * │   1420 Raw Materials      1440 Packaging Materials                       │
 * │   1430 Work In Progress   1410 Finished Goods                            │
 * │                                                                          │
 * │ all four carrying control_subledger = inventory. Nine posting rules      │
 * │ still name a generic 'inventory' role, and RulePostingStrategy resolves  │
 * │ leg roles verbatim — it has no way to pick a class from the event. So    │
 * │ the generic role CANNOT be mapped without collapsing the four classes    │
 * │ into one account, which the policy forbids.                              │
 * │                                                                          │
 * │ Those nine rules must be re-authored to name the class role they mean.   │
 * │ That is posting-rule work, not chart configuration. Until then they      │
 * │ dead-letter: the operational transaction is unaffected and no amount is  │
 * │ posted to a guessed account.                                             │
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
            'packaging_materials' => ['1440', 'Packaging Materials'],
            'wip' => ['1430', 'Work In Progress'],
            'pos_clearing' => ['1140', 'POS Clearing'],
            // TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006: cash a driver is
            // physically holding after COD collection but has not yet banked —
            // "Cash in Transit" (1130) already existed for exactly this and is
            // reused rather than minting a new account code.
            'cod_clearing' => ['1130', 'Cash in Transit'],
            // TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007: a driver's net
            // financial position (advances owed BY them, netted against
            // approved expense reimbursements/shortages owed TO them) — a
            // swing account by design, the same "due to/from" pattern many
            // charts use for employee balances rather than a separate asset
            // and liability account for the same underlying party.
            'driver_receivable' => ['1320', 'Employee Receivables'],
            'vat_input' => ['1530', 'VAT Receivable (Input)'],
            'ar_control' => ['1310', 'Trade Receivables — control, subledger receivables'],
            // TASK-ECOS-V1.1-OPS-02-CLOSURE: the physical cash a driver hands back to
            // Treasury at trip-settlement time is the SAME economic position 'cod_clearing'
            // already models — cash a driver is physically holding, not yet banked — viewed
            // from the relief side (Treasury receives it) rather than the recognition side
            // (driver first collected it). Reuses 1130 Cash in Transit rather than minting a
            // second account for the same underlying fact; roles are addressed independently
            // of one another even when they share an account (see class docblock).
            'driver_cash_clearing' => ['1130', 'Cash in Transit'],

            // ── Balance sheet — liabilities ──────────────────────────────────
            'ap_control' => ['2110', 'Trade Payables — control, subledger payables'],
            'grni' => ['2120', 'Goods Received Not Invoiced'],
            'carrier_payable' => ['2130', 'Shipping Payables'],
            'vat_output' => ['2210', 'VAT Payable (Output)'],
            'loyalty_liability' => ['2430', 'Loyalty Points Liability'],
            'refund_clearing' => ['2440', 'Refunds Payable'],

            // ── Revenue and revenue deductions ───────────────────────────────
            // Product Sales is the default operational revenue account for every
            // channel. POS, WooCommerce and future channels report by channel
            // analytically; they do not each get their own revenue account unless
            // a posting rule names one.
            'sales_revenue' => ['4110', 'Product Sales — default operational revenue, all channels'],
            'sales_returns' => ['4210', 'Sales Returns'],
            'sales_discount' => ['4220', 'Sales Discounts'],
            'coupon_expense' => ['4230', 'Coupon Redemptions — contra-revenue, debit normal'],
            'inventory_adjustment_gain' => ['4920', 'Inventory Gain'],
            // TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007: an APPROVED
            // driver shortage recovery. Chosen deliberately over crediting back
            // 5170 Inventory Loss: this task found no confirmed evidence that
            // the originating waste/damage record itself already posts to
            // Finance as an inventory loss, so netting against it would risk
            // relieving an expense that was never recognised. If a future task
            // confirms that linkage, this role should be revisited to net
            // against 5170 instead of recognising fresh other income.
            'driver_shortage_recovery' => ['4910', 'Other Income'],

            // ── Cost of sales ────────────────────────────────────────────────
            // TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006: COGS on commercial
            // delivery (Dr 5100, Cr finished_goods 1410 — see the
            // shipping.delivery_confirmation posting rule).
            'cost_of_goods_sold' => ['5100', 'Cost of Goods Sold'],
            'scrap_expense' => ['5150', 'Scrap & Rework'],
            'inventory_writeoff_expense' => ['5160', 'Inventory Write-Off'],
            'inventory_adjustment_loss' => ['5170', 'Inventory Loss'],
            // V-2. Where an approved supplier invoice prices goods differently from the
            // valuation the physical receipt already committed to Inventory/FIFO, the
            // difference lands here rather than rewriting a historical FIFO layer.
            'purchase_price_variance' => ['5180', 'Purchase Price Variance'],

            // ── Operating expenses ───────────────────────────────────────────
            'shipping_expense' => ['5550', 'Shipping & Delivery'],
            'marketing_credit_expense' => ['5560', 'Marketing & Advertising'],
            'loyalty_expense' => ['5940', 'Loyalty Expense — points EARNED, not redeemed'],
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
