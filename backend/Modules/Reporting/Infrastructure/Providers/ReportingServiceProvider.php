<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Reporting module (ADR-045 Decision 1).
 *
 * No repository binding: {@see \Modules\Reporting\Application\Services\MetricRegistryService}
 * and {@see \Modules\Reporting\Application\Services\ReportCatalogueService} read the static,
 * code-defined Catalog classes directly and have no constructor dependencies to bind.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
