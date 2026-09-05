<?php

declare(strict_types=1);

namespace Modules\Finance\Reporting\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\Finance\Payables\Domain\Services\ApAgingService;
use Modules\Finance\Receivables\Domain\Services\ArAgingService;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-FIN-03 · AR / AP Aging (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-FIN-02 (Outstanding AR), MET-FIN-03 (Outstanding AP). Read strategy: A — thin
 * proxy into `ArAgingService::report()` / `ApAgingService::report()`, called verbatim, never
 * re-bucketed here.
 *
 * Both services return an identical bucket structure (`current, 1_30, 31_60, 61_90,
 * 90_plus`, plus a `total` key on every row/`totals` dict that is NOT listed in their own
 * `buckets` metadata array — a real asymmetry confirmed by direct source inspection, kept
 * here exactly as each service returns it rather than "corrected," since normalizing it
 * would diverge from Finance's own already-shipped, routed shape).
 */
final class ArApAgingReportQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly ArAgingService $arAging,
        private readonly ApAgingService $apAging,
    ) {}

    public function reportId(): string
    {
        return 'RPT-FIN-03';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'customer_id' => ['nullable', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
        ])->validate();

        return [
            'as_of' => $validated['as_of'] ?? null,
            'customer_id' => $validated['customer_id'] ?? null,
            'supplier_id' => $validated['supplier_id'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $asOf = $filters['as_of'] !== null ? Carbon::parse($filters['as_of']) : null;

        $ar = $this->arAging->report($context->companyId, $asOf, $filters['customer_id']);
        $ap = $this->apAging->report($context->companyId, $asOf, $filters['supplier_id']);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-FIN-02' => (float) ($ar['totals']['total'] ?? 0),
                'MET-FIN-03' => (float) ($ap['totals']['total'] ?? 0),
            ],
            rows: [],
            totals: ['ar' => $ar, 'ap' => $ap],
            period: new ReportPeriod($filters['as_of'], $filters['as_of']),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
