<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/**
 * ENTERPRISE-REPORTING-PLATFORM.md §7 Freshness Model / ADR-045 Decision 7.
 *
 * Exactly the three classes the architecture defines. Where a Metric Dictionary entry
 * names a compound value (e.g. "LIVE (recent) / EVENTUAL (trend)"), the primary class is
 * recorded here and the full nuance is preserved verbatim in that entry's `freshness_note`
 * — never invented as a fourth enum case.
 */
enum FreshnessClass: string
{
    case Live = 'live';
    case Eventual = 'eventual';
    case AccountingPosted = 'accounting_posted';
}
