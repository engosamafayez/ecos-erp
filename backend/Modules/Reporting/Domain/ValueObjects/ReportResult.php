<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * Stable, read-only result contract for an executed report (TASK-ECOS-REPORTING-QUERY-
 * EXECUTION-AND-FIRST-REPORTS-003 §5).
 *
 * Deliberately covers only what the first tranche actually needs — scalar KPI values,
 * tabular rows, totals, period and applied-filter metadata. No charting, export, or pivot
 * abstraction is added here: this task is not the final Reporting UI/export task (§5/§19).
 */
final class ReportResult
{
    /**
     * @param  array<string, int|float|string|null>  $kpis  Metric id => scalar value, e.g. ['MET-SALES-01' => 12345.67]
     * @param  list<array<string, mixed>>  $rows  Tabular rows, empty when the report is KPI-only
     * @param  array<string, int|float|string|null>  $totals  Grand-total row over $rows, empty when not applicable
     * @param  array<string, mixed>  $appliedFilters  The normalized filters actually applied, not the raw request input
     */
    public function __construct(
        public readonly string $reportId,
        public readonly array $kpis,
        public readonly array $rows,
        public readonly array $totals,
        public readonly ReportPeriod $period,
        public readonly array $appliedFilters,
        public readonly DateTimeImmutable $generatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'report_id' => $this->reportId,
            'kpis' => $this->kpis,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'period' => $this->period->toArray(),
            'applied_filters' => $this->appliedFilters,
            'generated_at' => $this->generatedAt->format(DATE_ATOM),
        ];
    }
}
