<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PROC-02 · Supplier Scorecard (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-PROC-03 (Supplier Procurement Health Score), MET-PROC-04 (Supplier On-Time
 * Delivery Rate) — "Reuse GetProcurementHealthQuery / GetSupplierAnalyticsQuery verbatim
 * (DO-NOT-REIMPLEMENT)."
 *
 * Both source functions take a single `supplierId` (confirmed: neither has a bulk/portfolio
 * overload anywhere in the module), so this report is inherently per-supplier — matching
 * "Scorecard" naming (a specific supplier's card), not a portfolio list.
 *
 * `GetSupplierAnalyticsQuery`'s own live ambiguous-`company_id`-join defect (found while
 * wiring this exact report) was fixed at its source — see that class's docblock.
 */
final class SupplierScorecardQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly GetProcurementHealthQuery $health,
        private readonly GetSupplierAnalyticsQuery $analytics,
    ) {}

    public function reportId(): string
    {
        return 'RPT-PROC-02';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'supplier_id' => ['required', 'uuid'],
        ])->validate();

        return [
            'supplier_id' => $validated['supplier_id'],
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $supplierId = $filters['supplier_id'];

        // Supplier's own global scope (TASK-GOLIVE-RC6-REPAIR-001-style booted()) makes this
        // fail closed for a supplier outside the caller's company — no manual re-check needed.
        Supplier::query()->where('company_id', $context->companyId)->findOrFail($supplierId);

        $healthResult = $this->health->execute($supplierId);
        $analyticsResult = $this->analytics->execute($supplierId);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-PROC-03' => $healthResult['score'] ?? null,
                'MET-PROC-04' => $analyticsResult['on_time_delivery_rate'] ?? null,
            ],
            rows: [],
            totals: [
                'health' => $healthResult,
                'analytics' => $analyticsResult,
            ],
            period: new ReportPeriod(null, null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
