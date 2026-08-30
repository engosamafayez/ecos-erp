<?php

declare(strict_types=1);

namespace Modules\Finance\Infrastructure\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The canonical VAT tax codes — V-1.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY THIS EXISTS. `AccountsPayableService` reads a line's `tax_code_id`,   │
 * │ looks up `TaxCode.input_account_id` and posts the input-VAT leg from it.  │
 * │ No tax code existed, so a VAT-bearing supplier bill had nowhere to book   │
 * │ recoverable VAT. The rate lives HERE — in configuration — and never in    │
 * │ posting logic: no `if rate == 14` exists anywhere.                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ACCOUNTS ARE RESOLVED BY ROLE, NEVER BY CODE. `vat_input` and `vat_output` are looked up
 * through the same `finance_account_roles` mapping every posting rule uses, so a company that
 * re-points those roles gets its own accounts here automatically. No account id is hardcoded
 * and no account is created, renamed or moved.
 *
 * COMPANY-SCOPED. `finance_tax_codes.company_id` is NOT NULL, so each company gets its own
 * code and one company's VAT configuration cannot affect another's. Companies with no chart of
 * accounts are skipped rather than given a code that could never resolve an account — see the
 * Nile Foods Trading note in the V-1/V-2 engineering report.
 *
 * IDEMPOTENT: keyed on (company_id, code). Re-running never overwrites a rate a company has
 * since changed; it only fills what is missing.
 */
class TaxCodeSeeder extends Seeder
{
    /** The approved ECOS Egypt standard VAT rate (percent). Configuration, not logic. */
    public const STANDARD_VAT_RATE = 14.0;

    public const STANDARD_VAT_CODE = 'VAT14';

    private const CATEGORY_CODE = 'STD';

    public function run(): void
    {
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $this->seedCompany((string) $companyId);
        }
    }

    public function seedCompany(string $companyId): void
    {
        $inputAccountId = $this->accountForRole($companyId, 'vat_input');

        // No chart of accounts (or no VAT mapping) → nothing meaningful to configure.
        if ($inputAccountId === null) {
            return;
        }

        $categoryId = $this->ensureCategory($companyId);

        $exists = DB::table('finance_tax_codes')
            ->where('company_id', $companyId)
            ->where('code', self::STANDARD_VAT_CODE)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('finance_tax_codes')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'tax_category_id' => $categoryId,
            'code' => self::STANDARD_VAT_CODE,
            'name' => 'VAT 14%',
            'tax_type' => 'vat',
            'rate' => self::STANDARD_VAT_RATE,
            'is_recoverable' => true,
            'input_account_id' => $inputAccountId,
            'output_account_id' => $this->accountForRole($companyId, 'vat_output'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureCategory(string $companyId): int
    {
        $existing = DB::table('finance_tax_categories')
            ->where('company_id', $companyId)
            ->where('code', self::CATEGORY_CODE)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('finance_tax_categories')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'code' => self::CATEGORY_CODE,
            'name' => 'Standard Rated',
            'name_ar' => 'خاضع للضريبة بالسعر العادي',
            'is_recoverable' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function accountForRole(string $companyId, string $role): ?int
    {
        $id = DB::table('finance_account_roles')
            ->where('company_id', $companyId)
            ->where('role', $role)
            ->value('account_id');

        return $id === null ? null : (int) $id;
    }
}
