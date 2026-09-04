<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Commerce\Orders\Application\Queries\OrderStatusPaymentMixQuery;
use Modules\Commerce\Orders\Application\Queries\RequestedDeliveryPerformanceQuery;
use Modules\Commerce\Orders\Application\Queries\SalesByDimensionQuery;
use Modules\Commerce\Orders\Application\Queries\SalesOverviewQuery;
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
 * dependencies (e.g. `Customer360ListQuery`'s `BlockedCustomerPolicy`) are wired
 * automatically the same way Laravel resolves any other class.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportHandlerRegistry::class, function ($app): ReportHandlerRegistry {
            return new ReportHandlerRegistry([
                $app->make(SalesOverviewQuery::class),
                $app->make(SalesByDimensionQuery::class),
                $app->make(OrderStatusPaymentMixQuery::class),
                $app->make(RequestedDeliveryPerformanceQuery::class),
                $app->make(CustomerOverviewQuery::class),
                $app->make(Customer360ListQuery::class),
            ]);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
