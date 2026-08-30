<?php

declare(strict_types=1);

namespace Modules\Manufacturing\ManufacturingWorkflow\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Manufacturing\AvailabilityEngine\Domain\Services\InventoryAvailabilityEngine;
use Modules\Manufacturing\DecisionOrchestrator\Domain\Contracts\RuleProviderRegistryInterface;
use Modules\Manufacturing\DecisionOrchestrator\Domain\Services\DecisionOrchestrator;
use Modules\Manufacturing\ManufacturingPlanner\Domain\Services\ManufacturingPlanner;
use Modules\Manufacturing\ManufacturingWorkflow\Domain\Services\ManufacturingKernelGateProvider;
use Modules\Manufacturing\ManufacturingWorkflow\Domain\Services\ManufacturingWorkflow;

final class ManufacturingWorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ManufacturingWorkflow::class, function ($app): ManufacturingWorkflow {
            return new ManufacturingWorkflow(
                orchestrator: $app->make(DecisionOrchestrator::class),
                availabilityEngine: $app->make(InventoryAvailabilityEngine::class),
                planner: $app->make(ManufacturingPlanner::class),
            );
        });
    }

    /**
     * Register the `manufacturing` Decision-Kernel rule provider — the thin pass-through
     * gate (Option A). Without this, `registry->for('manufacturing')` throws
     * NoProviderForContextException and the canonical MTO workflow cannot reach its
     * downstream authorities (ManufacturingPolicy + InventoryAvailabilityEngine).
     *
     * The registry is a shared singleton (bound by DecisionOrchestratorServiceProvider);
     * registering here at boot makes the provider available application-wide. This is the
     * caller-registers-its-own-provider pattern the registry documents. It never owns any
     * manufacturing business rule — see ManufacturingKernelGateProvider.
     */
    public function boot(): void
    {
        $this->app->make(RuleProviderRegistryInterface::class)
            ->register('manufacturing', new ManufacturingKernelGateProvider);
    }
}
