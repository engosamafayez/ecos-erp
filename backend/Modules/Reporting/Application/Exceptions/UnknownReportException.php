<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a report id does not exist anywhere in {@see \Modules\Reporting\Domain\Catalog\ReportCatalogue}
 * — the id itself is unknown, distinct from {@see ReportNotExecutableException} (a real,
 * cataloged report with no registered handler yet).
 */
final class UnknownReportException extends InvalidArgumentException
{
    public static function forId(string $reportId): self
    {
        return new self("Unknown report id '{$reportId}' — not present in the Report Catalogue.");
    }
}
