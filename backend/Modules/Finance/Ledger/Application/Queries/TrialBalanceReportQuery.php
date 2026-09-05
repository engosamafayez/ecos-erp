<?php

declare(strict_types=1);

namespace Modules\Finance\Ledger\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Finance\Ledger\Domain\Services\TrialBalanceService;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-FIN-01 · Trial Balance (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Read strategy: A — thin proxy into `TrialBalanceService::forPeriod()`, already live and
 * routed at `GET /finance/trial-balance` (ADR-045 Decision 6: "expose and govern," not
 * build). No metric dictionary IDs are cataloged for this report (catalogue entry carries
 * an empty metrics list) — the trial balance is a raw ledger listing, not a KPI.
 *
 * `TrialBalanceService` has no constructor and enforces no tenant scope of its own — the
 * caller (this handler) must pass `company_id` explicitly on every call (verified by direct
 * source inspection of every Finance reporting service this task touches).
 */
final class TrialBalanceReportQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
    ) {}

    public function reportId(): string
    {
        return 'RPT-FIN-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'fiscal_period_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            'fiscal_period_id' => isset($validated['fiscal_period_id']) ? (int) $validated['fiscal_period_id'] : null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $result = $this->trialBalance->forPeriod($context->companyId, $filters['fiscal_period_id']);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: $result['lines'],
            totals: [
                'total_debit' => $result['total_debit'],
                'total_credit' => $result['total_credit'],
                'is_balanced' => $result['is_balanced'],
                'fiscal_period_id' => $result['fiscal_period_id'],
            ],
            period: new ReportPeriod(null, null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
