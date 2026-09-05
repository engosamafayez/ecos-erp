<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Commerce\Orders\Application\Queries\ProductProfitabilityQuery;
use Modules\Commerce\Orders\Application\Queries\SalesOverviewQuery;
use Modules\Finance\Reporting\Application\Queries\ArApAgingReportQuery;
use Modules\Finance\Reporting\Application\Queries\FinancialStatementReportQuery;
use Modules\Inventory\InventoryItems\Application\Queries\InventoryValuationQuery;
use Modules\Inventory\InventoryItems\Application\Queries\StockOnHandQuery;
use Modules\Logistics\Distribution\Application\Queries\DeliveryPerformanceQuery;
use Modules\Operations\Preparation\Application\Queries\PreparationCompletionAndShortagesQuery;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;
use Modules\Sales\Customers\Application\Queries\CustomerOverviewQuery;

/**
 * RPT-EXEC-01 · Executive Overview (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-SALES-01/03/04, MET-PROD-04/06, MET-FIN-01/02/03, MET-INV-01/03,
 * MET-DIST-01, MET-PREP-01, MET-CUST-02 — 13 metrics across 6 domains. Read strategy: C
 * (Reporting-owned composition, ADR-045 Decision 3 Pattern C) — this handler's own code
 * does nothing but call already-live handlers (Task 3/4/5) and Finance's own thin-proxy
 * reports built earlier in this same task, extracting one KPI from each. No metric is
 * recomputed; a wrong figure here can only be a wiring mistake, never a formula drift,
 * because every value is read back verbatim from its own owning report (§13 cross-report
 * consistency by construction).
 *
 * MET-FIN-01 is read from `FinancialStatementReportQuery`'s income-statement path — the
 * SAME call `RPT-FIN-02` itself makes for the same metric, never a second, independent
 * Finance query. Per ADR-045 Decision 2b/§17: this may legitimately be a small or
 * POS-channel-only figure today (the delivery/COD revenue-recognition gap) — Executive
 * Overview must display Finance's own current truth exactly as it is, never a
 * Commerce-derived substitute standing in for it.
 */
final class ExecutiveOverviewQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly SalesOverviewQuery $salesOverview,
        private readonly ProductProfitabilityQuery $productProfitability,
        private readonly FinancialStatementReportQuery $financialStatement,
        private readonly ArApAgingReportQuery $arApAging,
        private readonly StockOnHandQuery $stockOnHand,
        private readonly InventoryValuationQuery $inventoryValuation,
        private readonly DeliveryPerformanceQuery $deliveryPerformance,
        private readonly PreparationCompletionAndShortagesQuery $preparationCompletion,
        private readonly CustomerOverviewQuery $customerOverview,
    ) {}

    public function reportId(): string
    {
        return 'RPT-EXEC-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $dateFilters = ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']];

        $sales = $this->salesOverview->execute($context, $this->salesOverview->validateFilters($dateFilters));
        $profitability = $this->productProfitability->execute($context, $this->productProfitability->validateFilters($dateFilters));
        $stock = $this->stockOnHand->execute($context, $this->stockOnHand->validateFilters([]));
        $valuation = $this->inventoryValuation->execute($context, $this->inventoryValuation->validateFilters([]));
        $delivery = $this->deliveryPerformance->execute($context, $this->deliveryPerformance->validateFilters($dateFilters));
        $preparation = $this->preparationCompletion->execute($context, $this->preparationCompletion->validateFilters($dateFilters));
        $customers = $this->customerOverview->execute($context, $this->customerOverview->validateFilters([]));
        $arAp = $this->arApAging->execute($context, $this->arApAging->validateFilters([]));

        $incomeStatementFilters = [
            'statement_type' => 'income_statement',
            'date_from' => $filters['date_from'] ?? now()->startOfYear()->toDateString(),
            'date_to' => $filters['date_to'] ?? now()->toDateString(),
        ];
        $financial = $this->financialStatement->execute($context, $this->financialStatement->validateFilters($incomeStatementFilters));

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-SALES-01' => $sales->kpis['MET-SALES-01'] ?? null,
                'MET-SALES-03' => $sales->kpis['MET-SALES-03'] ?? null,
                'MET-SALES-04' => $sales->kpis['MET-SALES-04'] ?? null,
                'MET-PROD-04' => $profitability->kpis['MET-PROD-04'] ?? null,
                'MET-PROD-06' => $profitability->kpis['MET-PROD-06'] ?? null,
                'MET-FIN-01' => $financial->kpis['MET-FIN-01'] ?? null,
                'MET-FIN-02' => $arAp->kpis['MET-FIN-02'] ?? null,
                'MET-FIN-03' => $arAp->kpis['MET-FIN-03'] ?? null,
                'MET-INV-01' => $stock->kpis['MET-INV-01'] ?? null,
                'MET-INV-03' => $valuation->kpis['MET-INV-03'] ?? null,
                'MET-DIST-01' => $delivery->kpis['MET-DIST-01'] ?? null,
                'MET-PREP-01' => $preparation->kpis['MET-PREP-01'] ?? null,
                'MET-CUST-02' => $customers->kpis['MET-CUST-02'] ?? null,
            ],
            rows: [],
            totals: [],
            period: new ReportPeriod($filters['date_from'], $filters['date_to']),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
