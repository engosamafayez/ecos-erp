<?php

declare(strict_types=1);

namespace Modules\Finance\Reporting\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Finance\Closing\Domain\Services\ClosingWorkspaceService;
use Modules\Finance\Fiscal\Domain\Enums\PeriodStatus;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Intelligence\Domain\Services\ProfitabilityService;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-FIN-05 · Profitability & Closing (ENTERPRISE-REPORTING-PLATFORM.md §18). No metric
 * dictionary IDs are cataloged for this report (empty metrics list) — it composes two
 * Finance dashboards, not a single KPI.
 *
 * Read strategy: A — `ProfitabilityService::company()` (always) plus an optional
 * `dimension`-filtered breakdown (`byBranch`/`byCostCenter`/`byProject`/`byCustomer`, or
 * `byUntaggedDimension()` for `product`/`channel` — which, per direct source inspection,
 * genuinely still returns `available:false` today; this report surfaces that honestly
 * rather than omitting the option or fabricating a number, exactly as the catalogue's own
 * known dependency instructs) — plus `ClosingWorkspaceService::forPeriod()`.
 *
 * `ClosingWorkspaceService::forPeriod()` takes a hydrated `FiscalPeriod`, not a scalar id —
 * resolved here explicitly (no `FiscalPeriod` global scope exists, confirmed by direct
 * inspection, so `company_id` is filtered by hand). An optional `fiscal_period_id` selects a
 * specific period; otherwise the company's current Open period is used. If no Open period
 * exists for the company at all, `closing` is honestly `null` with a note — never a
 * fabricated readiness score.
 */
final class ProfitabilityAndClosingReportQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly ProfitabilityService $profitability,
        private readonly ClosingWorkspaceService $closing,
    ) {}

    public function reportId(): string
    {
        return 'RPT-FIN-05';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'dimension' => ['nullable', Rule::in(['branch', 'cost_center', 'project', 'customer', 'product', 'channel'])],
            'fiscal_period_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? Carbon::now()->startOfYear()->toDateString(),
            'date_to' => $validated['date_to'] ?? Carbon::now()->toDateString(),
            'dimension' => $validated['dimension'] ?? null,
            'fiscal_period_id' => isset($validated['fiscal_period_id']) ? (int) $validated['fiscal_period_id'] : null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $from = Carbon::parse($filters['date_from']);
        $to = Carbon::parse($filters['date_to']);

        $company = $this->profitability->company($context->companyId, $from, $to);

        $breakdown = match ($filters['dimension']) {
            'branch' => $this->profitability->byBranch($context->companyId, $from, $to),
            'cost_center' => $this->profitability->byCostCenter($context->companyId, $from, $to),
            'project' => $this->profitability->byProject($context->companyId, $from, $to),
            'customer' => $this->profitability->byCustomer($context->companyId, $from, $to),
            'product', 'channel' => $this->profitability->byUntaggedDimension($context->companyId, $filters['dimension'], $from, $to),
            default => null,
        };

        $period = $this->resolveFiscalPeriod($context->companyId, $filters['fiscal_period_id']);
        $closing = $period !== null ? $this->closing->forPeriod($period) : null;

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: [],
            totals: [
                'profitability' => $company,
                'breakdown' => $breakdown,
                'closing' => $closing,
                'closing_note' => $closing === null ? 'No Open fiscal period found for this company.' : null,
            ],
            period: new ReportPeriod($filters['date_from'], $filters['date_to']),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }

    private function resolveFiscalPeriod(string $companyId, ?int $fiscalPeriodId): ?FiscalPeriod
    {
        if ($fiscalPeriodId !== null) {
            return FiscalPeriod::query()->where('company_id', $companyId)->where('id', $fiscalPeriodId)->first();
        }

        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('status', PeriodStatus::Open)
            ->orderByDesc('start_date')
            ->first();
    }
}
