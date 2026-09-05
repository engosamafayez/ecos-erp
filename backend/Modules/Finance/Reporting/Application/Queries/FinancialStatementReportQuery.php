<?php

declare(strict_types=1);

namespace Modules\Finance\Reporting\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Finance\Reporting\Domain\Services\FinancialStatementService;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-FIN-02 · P&L / Balance Sheet (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-FIN-01 (Recognized Revenue) — read from the Income Statement's own `revenue`
 * section total, never recomputed. Read strategy: A — thin proxy into
 * `FinancialStatementService::incomeStatement()`/`balanceSheet()`.
 *
 * One report, two statement shapes with different required filters (`statement_type`
 * selects which): Income Statement needs a `date_from`/`date_to` range; Balance Sheet needs
 * a single `as_of` date. Never blended into one schema — each is returned verbatim in its
 * own native shape under `totals.statement`.
 *
 * `FinancialStatementService` is not container-bound as a singleton (confirmed by direct
 * inspection — absent from `FinanceServiceProvider`) but resolves correctly via constructor
 * injection regardless (Laravel's reflection auto-wiring); this handler does not `new` it
 * directly so the container remains the single resolution path.
 */
final class FinancialStatementReportQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly FinancialStatementService $statements,
    ) {}

    public function reportId(): string
    {
        return 'RPT-FIN-02';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'statement_type' => ['required', Rule::in(['income_statement', 'balance_sheet'])],
            'date_from' => ['required_if:statement_type,income_statement', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['required_if:statement_type,income_statement', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'as_of' => ['required_if:statement_type,balance_sheet', 'nullable', 'date_format:Y-m-d'],
        ])->validate();

        return [
            'statement_type' => $validated['statement_type'],
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'as_of' => $validated['as_of'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        if ($filters['statement_type'] === 'income_statement') {
            $statement = $this->statements->incomeStatement(
                $context->companyId,
                Carbon::parse($filters['date_from']),
                Carbon::parse($filters['date_to']),
            );

            $revenue = (float) ($statement['sections']['revenue']['total'] ?? 0);

            return new ReportResult(
                reportId: $this->reportId(),
                kpis: ['MET-FIN-01' => $revenue],
                rows: [],
                totals: ['statement_type' => 'income_statement', 'statement' => $statement],
                period: new ReportPeriod($filters['date_from'], $filters['date_to']),
                appliedFilters: $filters,
                generatedAt: new DateTimeImmutable,
            );
        }

        $statement = $this->statements->balanceSheet($context->companyId, Carbon::parse($filters['as_of']));

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: [],
            totals: ['statement_type' => 'balance_sheet', 'statement' => $statement],
            period: new ReportPeriod($filters['as_of'], $filters['as_of']),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
