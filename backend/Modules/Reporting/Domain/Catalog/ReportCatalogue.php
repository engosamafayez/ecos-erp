<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Catalog;

use Modules\Reporting\Domain\Enums\GapClassification;
use Modules\Reporting\Domain\Enums\ReadStrategy;
use Modules\Reporting\Domain\Enums\ReportCategory;

/**
 * The 35 ratified V1 reports (`docs/architecture/ENTERPRISE-REPORTING-PLATFORM.md` §18
 * Report Catalogue, ADR-045 Decision 10 — "roughly 35-40 reports"). Transcribed verbatim
 * from that document; this class defines no report not present there.
 *
 * RPT-FIN-04 (Customer/Supplier Statement) is the architecture's own single canonical
 * Financial-category entry for what is "the same item as RPT-CUST-03/RPT-PROC-03, listed
 * once here and cross-linked" (§18) — represented here exactly once, under Financial, with
 * its Customers/Procurement cross-links named in `notes`, to avoid double-counting one
 * report as three catalogue entries.
 *
 * Every entry's `metric_ids` cites only identifiers defined in {@see MetricDictionary}, and
 * `permission` is always `ReportCategory::permission()` for that report's own category
 * (ADR-045 Decision 5 category-level model) — verified by
 * `ReportCatalogueTest::test_every_report_permission_matches_its_own_category`.
 *
 * No DB dependency — same convention as `Modules\IAM\Domain\Catalog\RoleTemplateCatalog`.
 */
final class ReportCatalogue
{
    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     category: string,
     *     metric_ids: list<string>,
     *     read_strategy: string,
     *     permission: string,
     *     gap_classification: string,
     *     is_v1: bool,
     *     source_modules: list<string>,
     *     notes: string|null,
     * }>
     */
    public static function all(): array
    {
        return [
            ...self::executive(),
            ...self::sales(),
            ...self::customers(),
            ...self::products(),
            ...self::inventory(),
            ...self::procurement(),
            ...self::preparation(),
            ...self::distribution(),
            ...self::drivers(),
            ...self::financial(),
        ];
    }

    private static function executive(): array
    {
        $category = ReportCategory::Executive;

        return [
            self::make('RPT-EXEC-01', 'Executive Overview', $category,
                ['MET-SALES-01', 'MET-SALES-03', 'MET-SALES-04', 'MET-PROD-04', 'MET-PROD-06', 'MET-FIN-01', 'MET-FIN-02', 'MET-FIN-03', 'MET-INV-01', 'MET-INV-03', 'MET-DIST-01', 'MET-PREP-01', 'MET-CUST-02'],
                ReadStrategy::ReportingOwnedComposition, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Commerce\\Orders', 'Modules\\Finance', 'Modules\\Inventory\\InventoryItems', 'Modules\\Logistics\\Distribution', 'Modules\\Operations\\DemandAnalysis', 'Modules\\Sales\\Customers'],
                'Composition layer only — every underlying figure already exists. Each KPI drills into its own source workspace. Freshness: EVENTUAL. Export: CSV.'),

            self::make('RPT-EXEC-02', 'Top Performers', $category,
                ['MET-SALES-01', 'MET-SALES-02', 'MET-CUST-06'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Commerce\\Orders', 'Modules\\Sales\\Customers'],
                'Top Products / Top Customers / Top Brands by Net Sales. Read strategy A/B. Export: CSV.'),

            self::make('RPT-EXEC-03', 'Operational Health Snapshot', $category,
                ['MET-DIST-01', 'MET-PREP-01', 'MET-INV-01'],
                ReadStrategy::ReportingOwnedComposition, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Logistics\\Distribution', 'Modules\\Operations\\DemandAnalysis', 'Modules\\Inventory\\InventoryItems'],
                'Delivery rate + preparation completion + inventory availability on one screen. Pure composition of three already-live services. Freshness: LIVE.'),
        ];
    }

    private static function sales(): array
    {
        $category = ReportCategory::Sales;

        return [
            self::make('RPT-SALES-01', 'Sales Overview', $category,
                ['MET-SALES-01', 'MET-SALES-02', 'MET-SALES-03', 'MET-SALES-04', 'MET-SALES-06', 'MET-SALES-07'],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Commerce\\Orders'],
                'Gross/Net/Delivered Sales, AOV, cancelled/scheduled counts, trend over comparison periods. New SalesOverviewQuery in Commerce\\Orders — no existing service composes these together today. Date basis: order_date. Drill-through: Orders workspace.'),

            self::make('RPT-SALES-02', 'Sales by Dimension', $category,
                ['MET-SALES-01', 'MET-SALES-02', 'MET-SALES-05'],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Commerce\\Orders'],
                'One parameterized report — Brand / Product / Category / Customer / Channel / Warehouse, user-selected breakout.'),

            self::make('RPT-SALES-03', 'Order Status & Payment Mix', $category,
                ['MET-SALES-08', 'MET-SALES-09'],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Commerce\\Orders'], null),

            self::make('RPT-SALES-04', 'Requested-Delivery Performance', $category,
                ['MET-SALES-10'],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReportingReadModelRequired, true,
                ['Modules\\Commerce\\Orders', 'Modules\\Logistics\\Distribution'],
                'The only Sales-category report needing a genuinely new cross-module query, not just a same-module rollup.'),
        ];
    }

    private static function customers(): array
    {
        $category = ReportCategory::Customers;

        return [
            self::make('RPT-CUST-01', 'Customer Overview', $category,
                ['MET-CUST-01', 'MET-CUST-02', 'MET-CUST-03', 'MET-CUST-04', 'MET-CUST-05'],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Sales\\Customers'], null),

            self::make('RPT-CUST-02', 'Customer 360 List', $category,
                ['MET-CUST-06'],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Sales\\Customers', 'Modules\\Crm\\Customers'],
                'Per-customer: orders, AOV, total/delivered sales, last order, LTV, preferred Brand/Category, blocked state. Known dependency: verify Crm\\Customers enrichment columns (status/blocked-state) have executed in target environment before shipping this column — CANONICAL-DEVELOP RECONCILIATION REQUIRED. Drill-through: Customer detail / Orders.'),

            self::make('RPT-CUST-03', 'Customer Outstanding AR', $category,
                ['MET-FIN-02'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Finance'],
                'Thin proxy into Finance (CustomerLedgerService). FINANCE DEPENDENCY (permission co-gating: reports.customers.view AND finance.ar.view). Cross-linked with RPT-FIN-04.'),
        ];
    }

    private static function products(): array
    {
        $category = ReportCategory::Products;

        return [
            self::make('RPT-PROD-01', 'Product Performance', $category,
                ['MET-SALES-05', 'MET-PROD-01'],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Inventory\\Products', 'Modules\\Commerce\\Orders'],
                'Units sold, sales value, ASP, by Brand/Category.'),

            self::make('RPT-PROD-02', 'Top Sellers / Slow Movers / Zero-Sale', $category,
                ['MET-SALES-05'],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Inventory\\Products', 'Modules\\Commerce\\Orders'],
                'Rank/filter over Units Sold within a date window.'),

            self::make('RPT-PROD-03', 'Product Profitability (Operational)', $category,
                ['MET-PROD-02', 'MET-PROD-04', 'MET-PROD-06'],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Commerce\\Orders'],
                'V1 is the OPERATIONAL cut only — never the Accounting variants (MET-PROD-03/05/07). The Accounting cut is explicitly LATER, blocked on Finance\'s own product/channel ledger-dimension gap (MET-PROD-07), a gap distinct from the revenue/COGS-posting-existence question.'),
        ];
    }

    private static function inventory(): array
    {
        $category = ReportCategory::Inventory;

        return [
            self::make('RPT-INV-01', 'Stock on Hand / Available / Reserved', $category,
                ['MET-INV-01', 'MET-INV-02'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Inventory\\InventoryItems'], null),

            self::make('RPT-INV-02', 'Inventory Valuation', $category,
                ['MET-INV-03'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\CostManagement'], null),

            self::make('RPT-INV-03', 'Stock Movements', $category,
                [],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Inventory\\InventoryItems'],
                'Movement log by type/date/warehouse. Source: StockLedgerEntry (canonical — never the legacy stock_movements table). Catalogue correction (TASK-ECOS-REPORTING-CROSS-DOMAIN-AND-FINANCIAL-REPORTS-004 §24): StockLedgerEntry lives in Modules\\Inventory\\InventoryItems, not Modules\\Inventory\\StockLedger — that namespace does not exist; StockMovement (the legacy model this report must never use) lives there instead. UPSTREAM DATA QUALITY DEPENDENCY if the legacy stock_movements read path is ever accidentally reused — Reporting must not touch that controller at all.'),

            self::make('RPT-INV-04', 'Shortage & Zero-Stock', $category,
                ['MET-INV-04', 'MET-INV-05'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Operations\\DemandAnalysis', 'Modules\\Inventory\\InventoryItems'],
                'Shortage cut: READY — EXISTING QUERY (via DemandAnalysis). Zero-stock cut: READY — SMALL QUERY REQUIRED.'),
        ];
    }

    private static function procurement(): array
    {
        $category = ReportCategory::Procurement;

        return [
            self::make('RPT-PROC-01', 'Purchasing Overview', $category,
                ['MET-PROC-01', 'MET-PROC-02'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Purchasing'], null),

            self::make('RPT-PROC-02', 'Supplier Scorecard', $category,
                ['MET-PROC-03', 'MET-PROC-04'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Purchasing'],
                'Reuse GetProcurementHealthQuery / GetSupplierAnalyticsQuery verbatim (DO-NOT-REIMPLEMENT).'),

            self::make('RPT-PROC-03', 'Supplier Statement', $category,
                ['MET-FIN-03'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Finance'],
                'Thin proxy into Finance (SupplierLedgerService::statement() — backend-complete, routed, never surfaced in any frontend today). The single highest-leverage "expose, don\'t build" item in the whole catalogue. Permission co-gating: reports.procurement.view AND finance.ap.view. Cross-linked with RPT-FIN-04.'),
        ];
    }

    private static function preparation(): array
    {
        $category = ReportCategory::Preparation;

        return [
            self::make('RPT-PREP-01', 'Wave Overview', $category,
                [],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Operations\\Preparation'],
                'Waves opened/completed, duration. Source: PreparationDashboardController.'),

            self::make('RPT-PREP-02', 'Preparation Completion & Shortages', $category,
                ['MET-PREP-01', 'MET-PREP-02'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Operations\\DemandAnalysis'], null),

            self::make('RPT-PREP-03', 'Postponed & Bottleneck Products', $category,
                ['MET-PREP-03'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Operations\\DemandAnalysis', 'Modules\\Operations\\Preparation'],
                'Plus top-shorted-product ranking (PreparationAnalyticsController.top_shorted_products, existing). Shortages cut: READY — EXISTING QUERY. Postponed count: READY — SMALL QUERY REQUIRED.'),
        ];
    }

    private static function distribution(): array
    {
        $category = ReportCategory::Distribution;

        return [
            self::make('RPT-DIST-01', 'Window/Group Utilization', $category,
                ['MET-DIST-02'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Logistics\\Distribution'],
                'Source: DistributionAggregationService.'),

            self::make('RPT-DIST-02', 'Delivery Performance', $category,
                ['MET-DIST-01', 'MET-DIST-03'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Logistics\\Distribution'], null),

            self::make('RPT-DIST-03', 'Zone Performance', $category,
                [],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Logistics\\Distribution'],
                'Delivery rate/volume by Zone (distribution_window_orders.distribution_zone_id — never orders.delivery_zone_id, a different catalog entirely). Enforce the correct join at the query layer, not the UI layer.'),

            self::make('RPT-DIST-04', 'Vehicle/Trip Utilization', $category,
                [],
                ReadStrategy::NewSourceOwnedQueryService, GapClassification::ReadySmallQueryRequired, true,
                ['Modules\\Logistics\\Distribution'],
                'Trip capacity fill rate. V1 covers Group/Trip only — a Vehicle-identity-accurate cut is LATER, blocked on open blocker VP-1 (Operations\\Loading\'s vehicle_assignments.vehicle_id is unconstrained/untyped against logistics_vehicles.id).'),
        ];
    }

    private static function drivers(): array
    {
        $category = ReportCategory::Drivers;

        return [
            self::make('RPT-DRV-01', 'Driver Operational Summary', $category,
                ['MET-DRV-01', 'MET-DRV-02'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Logistics\\Distribution'],
                'Orders received/delivered, cash handled (raw), waste/damage raw counts. Source: DriverReportsReadService.'),

            self::make('RPT-DRV-02', 'Driver Day Settlement', $category,
                ['MET-DRV-01', 'MET-DRV-02', 'MET-DRV-03', 'MET-DRV-04'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Logistics\\Distribution'],
                'The already-built 9-KPI set. Source: DriverDaySettlementReadService.kpis() — reuse verbatim.'),

            self::make('RPT-DRV-03', 'Driver Monthly Statement', $category,
                [],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Logistics\\Distribution'],
                'Source: DriverReportsReadService::monthlyStatement(). UPSTREAM DATA QUALITY DEPENDENCY: this service\'s own wallet() method still hardcodes advances/expenses as unavailable (no_canonical_authority) — a stale docblock relative to driver_trip_movements, which now IS a real authority. Flag for the owning module to reconcile; do not silently "fix" it from within Reporting.'),
        ];
    }

    private static function financial(): array
    {
        $category = ReportCategory::Financial;

        return [
            self::make('RPT-FIN-01', 'Trial Balance', $category,
                [],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Finance'],
                'Source: TrialBalanceService, already routed GET /finance/trial-balance. Permission: reports.finance.view + finance.trialbalance.view.'),

            self::make('RPT-FIN-02', 'P&L / Balance Sheet', $category,
                ['MET-FIN-01'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Finance'],
                'Source: FinancialStatementService. Permission: reports.finance.view + Finance\'s own.'),

            self::make('RPT-FIN-03', 'AR / AP Aging', $category,
                ['MET-FIN-02', 'MET-FIN-03'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Finance'],
                'Source: ArAgingService / ApAgingService. Permission: reports.finance.view + finance.ar.view/finance.ap.view.'),

            self::make('RPT-FIN-04', 'Customer / Supplier Statement', $category,
                ['MET-FIN-02', 'MET-FIN-03'],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Finance'],
                'Source: CustomerLedgerService::statement() / SupplierLedgerService::statement() — backend-complete, never surfaced. Same item as RPT-CUST-03/RPT-PROC-03; listed once here as the canonical Financial-category entry, cross-linked from Customers/Procurement. Frontend tab never built: READY — SMALL QUERY/API REQUIRED for that half.'),

            self::make('RPT-FIN-05', 'Profitability & Closing', $category,
                [],
                ReadStrategy::CallExistingSourceService, GapClassification::ReadyExistingQuery, true,
                ['Modules\\Finance'],
                'Source: ProfitabilityService, ClosingWorkspaceService. Product/channel profitability cuts honestly return available:false today (MET-PROD-07\'s ledger-dimension gap) — Reporting must preserve that honesty, not paper over it.'),
        ];
    }

    /**
     * @param  list<string>  $metricIds
     * @param  list<string>  $sourceModules
     * @return array{id: string, name: string, category: string, metric_ids: list<string>, read_strategy: string, permission: string, gap_classification: string, is_v1: bool, source_modules: list<string>, notes: string|null}
     */
    private static function make(
        string $id,
        string $name,
        ReportCategory $category,
        array $metricIds,
        ReadStrategy $readStrategy,
        GapClassification $gapClassification,
        bool $isV1,
        array $sourceModules,
        ?string $notes,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'category' => $category->value,
            'metric_ids' => $metricIds,
            'read_strategy' => $readStrategy->value,
            'permission' => $category->permission(),
            'gap_classification' => $gapClassification->value,
            'is_v1' => $isV1,
            'source_modules' => $sourceModules,
            'notes' => $notes,
        ];
    }
}
