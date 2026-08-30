<?php

declare(strict_types=1);

namespace Modules\Finance\Infrastructure\Console\Commands;

use Illuminate\Console\Command;
use Modules\Finance\Shared\Domain\Services\CompanyFinanceProvisioner;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * TASK-PROC-SUPPLIER-OPENING-BALANCE-001 §9 — backfill the Finance chart for EXISTING companies.
 *
 * Per the approved decision, the new `3600 Opening Balance Equity` account is delivered to
 * existing companies by a provisioner COMMAND, not a migration. `CompanyFinanceProvisioner`
 * (over `ChartOfAccountsSeeder::seedCompany`) is idempotent and gap-filling, so re-running it
 * adds only the missing account and never duplicates existing accounts/roles/tax-codes. New
 * companies already receive it through the provisioner's canonical create-time call site.
 */
final class ProvisionCompanyFinanceCommand extends Command
{
    protected $signature = 'finance:provision-companies {--company= : Provision only this company UUID}';

    protected $description = 'Idempotently (re)provision the Finance chart of accounts, roles and tax codes for companies';

    public function handle(CompanyFinanceProvisioner $provisioner): int
    {
        $only = $this->option('company');

        $companyIds = $only !== null && $only !== ''
            ? [(string) $only]
            : Company::query()->pluck('id')->map(static fn ($id) => (string) $id)->all();

        if ($companyIds === []) {
            $this->warn('No companies found to provision.');

            return self::SUCCESS;
        }

        foreach ($companyIds as $companyId) {
            $provisioner->provision($companyId);
            $this->line("  provisioned {$companyId}");
        }

        $this->info(sprintf('Finance provisioning complete for %d compan%s.', count($companyIds), count($companyIds) === 1 ? 'y' : 'ies'));

        return self::SUCCESS;
    }
}
