<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Finance\Infrastructure\Database\Seeders\AccountRoleSeeder;

/**
 * TASK-ECOS-V1.1-OPS-02-CLOSURE — backfill the `driver_cash_clearing` account
 * role for installations that already have a chart of accounts.
 *
 * Same shape as 2026_08_17_110000_seed_vat_code_and_ppv_role.php's V-2 half: the
 * seeder is the canonical provisioning mechanism and already covers fresh installs
 * (via CompanyFinanceProvisioner -> AccountRoleSeeder::seedCompany()); this migration
 * carries the same mapping to companies provisioned before this role existed. It runs
 * the seeder itself rather than restating its logic, so there is exactly one definition
 * of the mapping.
 *
 * No account is created: 1130 Cash in Transit was already in the seeded chart (it is
 * what `cod_clearing` already resolves to) — merely unmapped for this second role name.
 *
 * Additive and idempotent: AccountRoleSeeder::seedCompany() fills only what is missing
 * per company and never overwrites a mapping a company has since re-pointed (including a
 * bespoke, non-default mapping for this very role, if one was ever set by hand before this
 * migration ran). `down()` removes only the role indirection this migration can have
 * added — it never touches a posted journal, which stores a real account id directly, not
 * the role name, so removing the mapping row is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_account_roles') || ! Schema::hasTable('finance_accounts')) {
            return;
        }

        (new AccountRoleSeeder)->run();
    }

    public function down(): void
    {
        if (Schema::hasTable('finance_account_roles')) {
            DB::table('finance_account_roles')->where('role', 'driver_cash_clearing')->delete();
        }
    }
};
