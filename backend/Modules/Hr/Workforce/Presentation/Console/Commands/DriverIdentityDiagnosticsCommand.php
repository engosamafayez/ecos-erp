<?php

declare(strict_types=1);

namespace Modules\Hr\Workforce\Presentation\Console\Commands;

use Illuminate\Console\Command;
use Modules\Hr\Workforce\Domain\Services\DriverEmployeeResolver;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * FIN-01 §5 — read-only Driver ↔ Employee identity data-quality report.
 *
 * Visibility only: this never creates an Employee, never rewrites a
 * Driver's user_id, and never merges any record. It only classifies what
 * already exists.
 */
final class DriverIdentityDiagnosticsCommand extends Command
{
    protected $signature = 'hr:driver-identity-diagnostics {company_id? : Limit to one company; omit to report every company}';

    protected $description = 'Read-only Driver <-> Employee identity classification (matched/unmatched/ambiguous/cross-company).';

    public function handle(DriverEmployeeResolver $resolver): int
    {
        /** @var string|null $requested */
        $requested = $this->argument('company_id');

        $companyIds = $requested !== null
            ? [$requested]
            : Company::query()->orderBy('name')->pluck('id')->map(fn ($id): string => (string) $id)->all();

        if ($companyIds === []) {
            $this->info('No companies found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($companyIds as $companyId) {
            $report = $resolver->diagnoseCompany($companyId);
            $rows[] = [
                $companyId,
                $report['total'],
                $report['matched'],
                $report['unmatched'],
                $report['ambiguous'],
                $report['cross_company'],
            ];
        }

        $this->table(['Company', 'Drivers', 'Matched', 'Unmatched', 'Ambiguous', 'Cross-company'], $rows);

        return self::SUCCESS;
    }
}
