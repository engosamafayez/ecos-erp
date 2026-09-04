<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Contracts;

use Illuminate\Validation\ValidationException;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * An executable report — the Task 3 counterpart to {@see SourceReportQueryContract}.
 *
 * One handler per catalogued report id (§4: "every executable report ID maps to exactly
 * one handler"). A handler owns both its own filter validation and its own query
 * behaviour — catalogue definitions (Task 2) remain the source of report *metadata*;
 * handlers (Task 3) are the source of report *behaviour* (§4).
 */
interface ReportHandlerInterface
{
    /**
     * The exact `RPT-...` id from {@see \Modules\Reporting\Domain\Catalog\ReportCatalogue}
     * this handler executes. Used by the registry to detect duplicate/missing wiring.
     */
    public function reportId(): string;

    /**
     * Validate and normalize caller-supplied filters against exactly what this report
     * supports — never a generic passthrough (§9: "Do not expose arbitrary filtering").
     * Implementations use Laravel's own `Validator::make(...)->validate()`, the same
     * inline-validation convention `PreparationAnalyticsController::index()` already uses
     * for report-style filters — never a hand-rolled parser.
     *
     * @param  array<string, mixed>  $rawFilters
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateFilters(array $rawFilters): array;

    /**
     * @param  array<string, mixed>  $filters  Already validated by {@see validateFilters()}.
     */
    public function execute(ReportQueryContext $context, array $filters): ReportResult;
}
