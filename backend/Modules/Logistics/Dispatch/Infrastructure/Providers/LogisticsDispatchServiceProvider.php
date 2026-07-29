<?php

declare(strict_types=1);

namespace Modules\Logistics\Dispatch\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Logistics\Dispatch\Domain\Services\AssignmentScoringService;
use Modules\Logistics\Dispatch\Domain\Services\DispatchProposalService;
use Modules\Logistics\Dispatch\Domain\Services\DispatchReleaseService;
use Modules\Logistics\Dispatch\Domain\Services\ResourcePoolService;

/**
 * Dispatch — which trip gets which resources.
 *
 * ResourcePoolService receives FleetReadinessQueryInterface by injection, which
 * Fleet binds to its own implementation. Dispatch therefore consumes Fleet's
 * PUBLIC contract and never its internals (Directive 5).
 */
final class LogisticsDispatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ResourcePoolService::class);
        $this->app->singleton(AssignmentScoringService::class);
        $this->app->singleton(DispatchProposalService::class);
        $this->app->singleton(DispatchReleaseService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
