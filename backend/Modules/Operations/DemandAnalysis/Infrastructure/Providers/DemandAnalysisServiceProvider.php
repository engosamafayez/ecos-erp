<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Infrastructure\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\DomainEvents\Events\InventoryStockReceived;
use Modules\Operations\DemandAnalysis\Application\Listeners\ManufacturingCompletedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\RefreshDemandOnStockReceivedListener;
use Modules\Operations\DemandAnalysis\Application\Services\DemandCalculationService;
use Modules\Operations\DemandAnalysis\Application\Services\DemandProjectionBuilder;
use Modules\Operations\DemandAnalysis\Application\Services\DemandReadRepository;
use Modules\Operations\DemandAnalysis\Application\Services\MaterialDemandCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\MissingMaterialCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\ProductDemandCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\WaveKpiCalculator;
use Modules\Operations\Preparation\Application\Events\Inbound\ManufacturingJobCompletedEvent;

final class DemandAnalysisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->app->singleton(ProductDemandCalculator::class);
        $this->app->singleton(MaterialDemandCalculator::class);
        $this->app->singleton(MissingMaterialCalculator::class);
        $this->app->singleton(WaveKpiCalculator::class);
        $this->app->singleton(DemandReadRepository::class);

        $this->app->singleton(DemandProjectionBuilder::class);
        $this->app->singleton(DemandCalculationService::class);
    }

    public function boot(): void
    {
        // Wave lifecycle and order membership events are now routed through the
        // Enterprise Event Platform (EventPlatformServiceProvider).
        // Subscribers (WaveCreatedListener, etc.) are registered there via EnterpriseEventBus.

        // ManufacturingJobCompletedEvent does not implement DomainEvent and is not yet
        // migrated to the Enterprise Event Platform — keep legacy listener for now.
        Event::listen(ManufacturingJobCompletedEvent::class, ManufacturingCompletedListener::class);

        // Material arrival -> re-project the waves that need it, so a product that was
        // WAITING_MATERIAL becomes READY with no operator action and no polling.
        // Reuses the existing InventoryStockReceived domain event and the existing
        // DemandProjectionBuilder; scoped by company + warehouse + material.
        Event::listen(InventoryStockReceived::class, RefreshDemandOnStockReceivedListener::class);
    }
}
