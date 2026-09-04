<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Exceptions;

use LogicException;

/**
 * Thrown at registry construction time when two handlers claim the same report id — a
 * wiring defect, never a runtime/request condition. Fails deterministically and
 * immediately rather than letting the second registration silently shadow the first.
 */
final class DuplicateReportHandlerException extends LogicException
{
    public static function forId(string $reportId): self
    {
        return new self("Duplicate report handler registration for '{$reportId}' — exactly one handler per report id is required.");
    }
}
