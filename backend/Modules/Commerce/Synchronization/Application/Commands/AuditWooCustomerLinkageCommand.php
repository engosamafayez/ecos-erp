<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Commands;

use Illuminate\Console\Command;
use Modules\Commerce\Synchronization\Application\Actions\AuditWooCustomerCompanyLinkageAction;

/**
 * TASK-ECOS-V1.1-WOO-02-TENANT-SAFE-CUSTOMER-IDENTITY-044 §11.
 *
 * Usage:
 *   php artisan woo:audit-customer-linkage           # dry-run report only
 *   php artisan woo:audit-customer-linkage --apply   # also repair SAFE_AUTO_RELINK cases
 *
 * Safe to run repeatedly — see AuditWooCustomerCompanyLinkageAction's own
 * docblock for why an --apply run is idempotent.
 */
final class AuditWooCustomerLinkageCommand extends Command
{
    protected $signature = 'woo:audit-customer-linkage
                            {--apply : Actually repair SAFE_AUTO_RELINK cases (default: dry-run report only)}';

    protected $description = 'Audit historical Orders for cross-company customer_id linkage and optionally repair the safe cases.';

    public function handle(AuditWooCustomerCompanyLinkageAction $action): int
    {
        $apply = (bool) $this->option('apply');

        $result = $action->run($apply);

        $this->info("Scanned: {$result['scanned']} order(s) with a company and a linked customer.");
        $this->info("Cross-company mismatches found: {$result['mismatched']}");
        $this->line('  SAFE_AUTO_RELINK: '.$result['safe_auto_relink']
            .($apply ? " (relinked: {$result['relinked']})" : ' (dry-run — none applied)'));
        $this->line("  NEEDS_REVIEW: {$result['needs_review']} (never auto-acted on, at any setting)");

        foreach ($result['cases'] as $case) {
            $line = "  order {$case['order_number']} ({$case['order_id']}): {$case['classification']}"
                ." — linked customer {$case['linked_customer_id']} (company {$case['linked_customer_company_id']})"
                ." vs order company {$case['order_company_id']}";

            if ($case['classification'] === 'NEEDS_REVIEW') {
                $line .= " — {$case['reason']}";
            } elseif (isset($case['relinked_to_customer_id'])) {
                $line .= ' — relinked to '.$case['relinked_to_customer_id']
                    .($case['relinked_customer_was_created'] ? ' (newly created)' : ' (existing match)');
            }

            $this->line($line);
        }

        if (! $apply && $result['safe_auto_relink'] > 0) {
            $this->comment('Dry-run only — re-run with --apply to perform the SAFE_AUTO_RELINK repairs listed above.');
        }

        return self::SUCCESS;
    }
}
