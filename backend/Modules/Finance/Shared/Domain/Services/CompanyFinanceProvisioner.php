<?php

declare(strict_types=1);

namespace Modules\Finance\Shared\Domain\Services;

use Modules\Finance\Infrastructure\Database\Seeders\AccountRoleSeeder;
use Modules\Finance\Infrastructure\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Infrastructure\Database\Seeders\TaxCodeSeeder;

/**
 * V-3 — gives a company the Finance data every subledger posting depends on.
 *
 * THE PROBLEM THIS CLOSES. The three Finance seeders each already expose a per-company entry
 * point, but nothing invoked them when a company was created: they iterate `companies` at
 * setup/migration time, so any company created afterwards had zero accounts and zero roles.
 * `AccountRoleResolver::resolve($company, 'grni')` then threw, and no supplier invoice could post
 * a payable in either inbound mode. That is the whole of V-3 — a missing trigger, not a missing
 * mechanism.
 *
 * THIS CLASS ADDS NO PROVISIONING LOGIC. It is a single ordered call site over the existing
 * canonical seeders, so production and the Finance/Procurement test fixtures share one path
 * instead of each growing their own copy.
 *
 * ORDER IS A REAL DEPENDENCY, not a preference — verified by reading the seeders:
 *   1. ChartOfAccountsSeeder — creates the accounts;
 *   2. AccountRoleSeeder     — reads `finance_accounts` to map roles to account codes;
 *   3. TaxCodeSeeder         — reads `finance_account_roles` to resolve `vat_input`/`vat_output`.
 * Running them out of order silently provisions nothing.
 *
 * IDEMPOTENT by delegation: every seeder computes what already exists for the company and fills
 * only the gap, so repeating this is safe and creates no duplicate account, role or tax code.
 *
 * DELIBERATELY NOT AN OBSERVER. A `Company::created` hook would also catch the ~134 test files
 * that build companies through the factory, provisioning ~100 accounts and ~27 roles for each and
 * changing the starting conditions of many unrelated suites. Production is wired at its canonical
 * action instead, and Finance/Procurement tests opt in explicitly.
 */
final class CompanyFinanceProvisioner
{
    /**
     * Provision one company's chart of accounts, account roles and tax codes.
     *
     * Safe to call more than once for the same company.
     */
    public function provision(string $companyId): void
    {
        if ($companyId === '') {
            return;
        }

        (new ChartOfAccountsSeeder)->seedCompany($companyId);
        (new AccountRoleSeeder)->seedCompany($companyId);
        (new TaxCodeSeeder)->seedCompany($companyId);
    }
}
