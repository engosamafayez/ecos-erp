<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Infrastructure\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Operations\DemandAnalysis\Application\Listeners\DemandRefreshRequestedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\GoodsReceiptCompletedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\InventoryReturnedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\ManufacturingCompletedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\OrderAddedToWaveListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\OrderMovedToPreparingListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\OrderRemovedFromWaveListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\WaveClosedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\WaveCreatedListener;
use Modules\Operations\DemandAnalysis\Application\Services\DemandCalculationService;
use Modules\Operations\DemandAnalysis\Application\Services\DemandProjectionBuilder;
use Modules\Operations\DemandAnalysis\Application\Services\DemandReadRepository;
use Modules\Operations\DemandAnalysis\Application\Services\MaterialDemandCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\MissingMaterialCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\ProductDemandCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\WaveKpiCalculator;
use Modules\Operations\Preparation\Application\Events\Inbound\ManufacturingJobCompletedEvent;
use Modules\Operations\Preparation\Domain\Events\DemandRefreshRequested;
use Modules\Operations\Preparation\Domain\Events\OrderAddedToWave;
use Modules\Operations\Preparation\Domain\Events\OrderMovedToPreparing;
use Modules\Operations\Preparation\Domain\Events\OrderRemovedFromWave;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Operations\Preparation\Domain\Events\WaveCreated;

final class DemandAnalysisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
        // ── Wave lifecycle ────────────────────────────────────────────────────
        Event::listen(WaveCreated::class, WaveCreatedListener::class);
        Event::listen(WaveClosed::class,  WaveClosedListener::class);

        // ── Order membership ──────────────────────────────────────────────────
        Event::listen(DemandRefreshRequested::class, DemandRefreshRequestedListener::class);
        Event::listen(OrderAddedToWave::class,       OrderAddedToWaveListener::class);
        Event::listen(OrderRemovedFromWave::class,   OrderRemovedFromWaveListener::class);
        Event::listen(OrderMovedToPreparing::class,  OrderMovedToPreparingListener::class);

        // ── External triggers (stock changes) ────────────────────────────────
        Event::listen(ManufacturingJobCompletedEvent::class, ManufacturingCompletedListener::class);
        // GoodsReceiptCompleted and InventoryReturned: wire here once Procurement/
        // Inventory modules expose those events. Listeners are ready.
    }
}
