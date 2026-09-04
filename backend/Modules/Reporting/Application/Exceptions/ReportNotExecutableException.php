<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when a report id is a real, cataloged entry ({@see \Modules\Reporting\Domain\Catalog\ReportCatalogue})
 * but no handler has been registered for it yet — the correct, safe failure for any of
 * the 29 V1 reports outside this task's first tranche (§3: "Do NOT implement all 35
 * reports in this task"). Distinct from {@see UnknownReportException}, which means the id
 * itself is not real.
 */
final class ReportNotExecutableException extends RuntimeException
{
    public static function forId(string $reportId): self
    {
        return new self("Report '{$reportId}' is cataloged but has no registered execution handler yet.");
    }
}
