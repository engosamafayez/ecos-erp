<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/**
 * ENTERPRISE-REPORTING-PLATFORM.md Core Principle #4: "Operational ≠ Accounting, by label,
 * always." Every metric is classified exactly one of these two — never left ambiguous
 * (ADR-045 Decision 2b: Operational Sales != Finance Recognized Revenue).
 */
enum MetricClassification: string
{
    case Operational = 'operational';
    case Accounting = 'accounting';

    /**
     * True only for a metric whose system of record is Finance's own General Ledger
     * (ADR-045 Decision 6: Reporting proxies and links Finance, never recomputes).
     */
    public function isFinanceAuthoritative(): bool
    {
        return $this === self::Accounting;
    }
}
