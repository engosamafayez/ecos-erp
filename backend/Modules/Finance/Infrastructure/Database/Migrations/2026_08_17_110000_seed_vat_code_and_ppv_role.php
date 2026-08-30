<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Finance\Infrastructure\Database\Seeders\AccountRoleSeeder;
use Modules\Finance\Infrastructure\Database\Seeders\TaxCodeSeeder;

/**
 * V-1 and V-2 configuration, applied to installations that already have a chart of accounts.
 *
 * The seeders are the canonical provisioning mechanism and cover fresh installs; this migration
 * carries the same configuration to databases seeded before the two definitions existed. It runs
 * the seeders themselves rather than restating their logic, so there is exactly one definition
 * of each mapping.
 *
 * V-1 — the standard VAT code (`VAT14`, 14%), with its input/output accounts resolved through the
 *       `vat_input` / `vat_output` roles. Companies with no chart are skipped by the seeder.
 * V-2 — the `purchase_price_variance` role, mapped to the EXISTING `5180 Purchase Price Variance`
 *       account. No account is created: 5180 was already in the seeded chart, merely unmapped.
 *
 * Additive and idempotent: both seeders fill only what is missing and never overwrite a value a
 * company has since changed. `down()` removes only what this migration can have added, and only
 * while it is still untouched — a tax code that has been used by a posted bill is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_account_roles') || ! Schema::hasTable('finance_tax_codes')) {
            return;
        }

        (new AccountRoleSeeder)->run();
        (new TaxCodeSeeder)->run();
    }

    public function down(): void
    {
        if (Schema::hasTable('finance_tax_codes')) {
            // Never remove a tax code that a supplier bill line already references.
            $inUse = Schema::hasTable('finance_supplier_bill_lines')
                ? DB::table('finance_supplier_bill_lines')->whereNotNull('tax_code_id')->pluck('tax_code_id')->all()
                : [];

            DB::table('finance_tax_codes')
                ->where('code', TaxCodeSeeder::STANDARD_VAT_CODE)
                ->when($inUse !== [], fn ($q) => $q->whereNotIn('id', $inUse))
                ->delete();
        }

        if (Schema::hasTable('finance_account_roles')) {
            DB::table('finance_account_roles')->where('role', 'purchase_price_variance')->delete();
        }
    }
};
