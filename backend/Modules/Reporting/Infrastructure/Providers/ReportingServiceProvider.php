<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Commerce\Orders\Application\Queries\OrderStatusPaymentMixQuery;
use Modules\Commerce\Orders\Application\Queries\ProductProfitabilityQuery;
use Modules\Commerce\Orders\Application\Queries\RequestedDeliveryPerformanceQuery;
use Modules\Commerce\Orders\Application\Queries\SalesByDimensionQuery;
use Modules\Commerce\Orders\Application\Queries\SalesOverviewQuery;
use Modules\Finance\Ledger\Application\Queries\TrialBalanceReportQuery;
use Modules\Finance\Payables\Application\Queries\SupplierStatementQuery;
use Modules\Finance\Receivables\Application\Queries\CustomerOutstandingArQuery;
use Modules\Finance\Reporting\Application\Queries\ArApAgingReportQuery;
use Modules\Finance\Reporting\Application\Queries\FinancialStatementReportQuery;
use Modules\Finance\Reporting\Application\Queries\PartyStatementReportQuery;
use Modules\Finance\Reporting\Application\Queries\ProfitabilityAndClosingReportQuery;
use Modules\Inventory\InventoryItems\Application\Queries\InventoryValuationQuery;
use Modules\Inventory\InventoryItems\Application\Queries\ShortageAndZeroStockQuery;
use Modules\Inventory\InventoryItems\Application\Queries\StockMovementsQuery;
use Modules\Inventory\InventoryItems\Application\Queries\StockOnHandQuery;
use Modules\Inventory\Products\Application\Queries\ProductPerformanceQuery;
use Modules\Inventory\Products\Application\Queries\ProductRankingQuery;
use Modules\Logistics\Distribution\Application\Queries\DeliveryPerformanceQuery;
use Modules\Logistics\Distribution\Application\Queries\DriverDaySettlementQuery;
use Modules\Logistics\Distribution\Application\Queries\DriverMonthlyStatementQuery;
use Modules\Logistics\Distribution\Application\Queries\DriverOperationalSummaryQuery;
use Modules\Logistics\Distribution\Application\Queries\VehicleTripUtilizationQuery;
use Modules\Logistics\Distribution\Application\Queries\WindowGroupUtilizationQuery;
use Modules\Logistics\Distribution\Application\Queries\ZonePerformanceQuery;
use Modules\Operations\Preparation\Application\Queries\PostponedAndBottleneckProductsQuery;
use Modules\Operations\Preparation\Application\Queries\PreparationCompletionAndShortagesQuery;
use Modules\Operations\Preparation\Application\Queries\WaveOverviewQuery;
use Modules\Purchasing\Suppliers\Application\Queries\PurchasingOverviewQuery;
use Modules\Purchasing\Suppliers\Application\Queries\SupplierScorecardQuery;
use Modules\Reporting\Application\Queries\ExecutiveOverviewQuery;
use Modules\Reporting\Application\Queries\OperationalHealthSnapshotQuery;
use Modules\Reporting\Application\Queries\TopPerformersQuery;
use Modules\Reporting\Application\Services\ReportHandlerRegistry;
use Modules\Sales\Customers\Application\Queries\Customer360ListQuery;
use Modules\Sales\Customers\Application\Queries\CustomerOverviewQuery;

/**
 * Service provider for the Reporting module (ADR-045 Decision 1).
 *
 * No repository binding for {@see \Modules\Reporting\Application\Services\MetricRegistryService}
 * / {@see \Modules\Reporting\Application\Services\ReportCatalogueService} — they read the
 * static, code-defined Catalog classes directly and have no constructor dependencies.
 *
 * {@see ReportHandlerRegistry} is bound here as the one explicit place new handlers are
 * wired in (§4: "every executable report ID maps to exactly one handler" — an explicit
 * list, never class-path auto-discovery). Handlers are resolved through the container
 * (`$this->app->make(...)`), not `new`'d directly, so a handler's own constructor
 * dependencies (e.g. `Customer360ListQuery`'s `BlockedCustomerPolicy`,
 * `DriverOperationalSummaryQuery`'s `DriverReportsReadService`) are wired automatically the
 * same way Laravel resolves any other class.
 *
 * TASK-ECOS-REPORTING-CROSS-DOMAIN-AND-FINANCIAL-REPORTS-004 — second tranche (16 handlers):
 * Inventory (4), Procurement (2), Preparation (3), Distribution (4), Drivers (3), joining
 * Task 3's original 6 (Sales x4, Customers x2) for 22 total registered handlers.
 *
 * TASK-ECOS-REPORTING-V1-FINAL-COVERAGE-AND-SOURCE-CLOSURE-005 — final tranche (13
 * handlers), reaching 35/35: Executive (3, Reporting-owned Pattern-C composition — the one
 * deliberate exception to "never inside Modules\Reporting itself"), Customers (1, Finance
 * thin-proxy), Products (3), Procurement (1, Finance thin-proxy), Financial (5, Finance thin
 * proxies) — for 35 total registered handlers.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportHandlerRegistry::class, function ($app): ReportHandlerRegistry {
            return new ReportHandlerRegistry([
                // Task 3 — Sales + Customers
                $app->make(SalesOverviewQuery::class),
                $app->make(SalesByDimensionQuery::class),
                $app->make(OrderStatusPaymentMixQuery::class),
                $app->make(RequestedDeliveryPerformanceQuery::class),
                $app->make(CustomerOverviewQuery::class),
                $app->make(Customer360ListQuery::class),
                // Task 4 — Inventory
                $app->make(StockOnHandQuery::class),
                $app->make(InventoryValuationQuery::class),
                $app->make(StockMovementsQuery::class),
                $app->make(ShortageAndZeroStockQuery::class),
                // Task 4 — Procurement
                $app->make(PurchasingOverviewQuery::class),
                $app->make(SupplierScorecardQuery::class),
                // Task 4 — Preparation
                $app->make(WaveOverviewQuery::class),
                $app->make(PreparationCompletionAndShortagesQuery::class),
                $app->make(PostponedAndBottleneckProductsQuery::class),
                // Task 4 — Distribution
                $app->make(WindowGroupUtilizationQuery::class),
                $app->make(DeliveryPerformanceQuery::class),
                $app->make(ZonePerformanceQuery::class),
                $app->make(VehicleTripUtilizationQuery::class),
                // Task 4 — Drivers
                $app->make(DriverOperationalSummaryQuery::class),
                $app->make(DriverDaySettlementQuery::class),
                $app->make(DriverMonthlyStatementQuery::class),
                // Task 5 — Products
                $app->make(ProductPerformanceQuery::class),
                $app->make(ProductRankingQuery::class),
                $app->make(ProductProfitabilityQuery::class),
                // Task 5 — Customers / Procurement (Finance thin proxies)
                $app->make(CustomerOutstandingArQuery::class),
                $app->make(SupplierStatementQuery::class),
                // Task 5 — Financial (Finance thin proxies)
                $app->make(TrialBalanceReportQuery::class),
                $app->make(FinancialStatementReportQuery::class),
                $app->make(ArApAgingReportQuery::class),
                $app->make(PartyStatementReportQuery::class),
                $app->make(ProfitabilityAndClosingReportQuery::class),
                // Task 5 — Executive (Reporting-owned composition)
                $app->make(OperationalHealthSnapshotQuery::class),
                $app->make(TopPerformersQuery::class),
                $app->make(ExecutiveOverviewQuery::class),
            ]);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
