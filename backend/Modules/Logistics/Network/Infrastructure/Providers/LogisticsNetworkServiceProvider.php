<?php

declare(strict_types=1);

namespace Modules\Logistics\Network\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Network context — service areas, coverage and capacity (Phase 2).
 *
 * Currently carries the D1 migration only: nullable latitude/longitude on
 * logistics_cities, the single CTO-approved additive extension to a V1 table.
 * Route optimisation needs points, and duplicating geography in a V2 table
 * would violate Directive 2.
 */
final class LogisticsNetworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
