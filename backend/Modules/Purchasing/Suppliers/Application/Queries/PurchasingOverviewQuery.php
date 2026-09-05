<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Queries;

use DateTimeImmutable;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PROC-01 · Purchasing Overview (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-PROC-01 (Purchase Volume), MET-PROC-02 (Supplier Spend).
 *
 * Reuses `GetSupplierSummaryStatsQuery` verbatim, exactly as the architecture doc cites —
 * that function's own tenant-scoping defect (three of its eight fields carried no
 * `company_id` filter at all) was found and fixed as part of wiring this report (see that
 * class's own docblock for the full fix) rather than worked around here.
 */
final class PurchasingOverviewQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly GetSupplierSummaryStatsQuery $summaryStats,
    ) {}

    public function reportId(): string
    {
        return 'RPT-PROC-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        return [];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $stats = $this->summaryStats->execute();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                // MET-PROC-01 Purchase Volume — open PO count is the volume-in-flight figure
                // this existing service already exposes; total_outstanding is the value side.
                'MET-PROC-01' => $stats['open_pos_total'],
                // MET-PROC-02 Supplier Spend — invoiced-basis total this period (outstanding
                // balance across all suppliers, from posted Goods Receipts).
                'MET-PROC-02' => $stats['total_outstanding'],
            ],
            rows: [],
            totals: $stats,
            period: new ReportPeriod(null, null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
