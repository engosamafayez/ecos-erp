<?php

declare(strict_types=1);

namespace Modules\Platform\EventPlatform\Infrastructure\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\DomainEvents\Contracts\DomainEventBus;
use Modules\Operations\DemandAnalysis\Application\Listeners\DemandRefreshRequestedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\GoodsReceiptCompletedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\InventoryReturnedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\ManufacturingCompletedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\OrderAddedToWaveListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\OrderMovedToPreparingListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\OrderRemovedFromWaveListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\WaveClosedListener;
use Modules\Operations\DemandAnalysis\Application\Listeners\WaveCreatedListener;
use Modules\Operations\Preparation\Application\Events\Inbound\ManufacturingJobCompletedEvent;
use Modules\Operations\Preparation\Domain\Events\DemandRefreshRequested;
use Modules\Operations\Preparation\Domain\Events\OrderAddedToWave;
use Modules\Operations\Preparation\Domain\Events\OrderMovedToPreparing;
use Modules\Operations\Preparation\Domain\Events\OrderRemovedFromWave;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Operations\Preparation\Domain\Events\WaveCreated;
use Modules\Platform\EventPlatform\Application\Services\EnterpriseDeadLetterQueue;
use Modules\Platform\EventPlatform\Application\Services\EnterpriseEventBus;
use Modules\Platform\EventPlatform\Application\Services\EnterpriseEventDispatcher;
use Modules\Platform\EventPlatform\Application\Services\EnterpriseEventMonitor;
use Modules\Platform\EventPlatform\Application\Services\EnterpriseEventPublisher;
use Modules\Platform\EventPlatform\Application\Services\EnterpriseEventRegistry;
use Modules\Platform\EventPlatform\Application\Services\EnterpriseEventReplayService;
use Modules\Platform\EventPlatform\Application\Services\EnterpriseEventSerializer;
use Modules\Platform\EventPlatform\Domain\Contracts\EnterpriseDeadLetterQueueInterface;
use Modules\Platform\EventPlatform\Domain\Contracts\EnterpriseEventBusInterface;
use Modules\Platform\EventPlatform\Domain\Contracts\EnterpriseEventRegistryInterface;
use Modules\Platform\EventPlatform\Domain\Contracts\EnterpriseEventStoreInterface;
use Modules\Platform\EventPlatform\Domain\ValueObjects\RetryPolicy;
use Modules\Platform\EventPlatform\Infrastructure\Repositories\EloquentDeadLetterQueueRepository;
use Modules\Platform\EventPlatform\Infrastructure\Repositories\EloquentEventStore;
use Modules\Platform\EventPlatform\Presentation\Console\DrainDlqCommand;
use Modules\Platform\EventPlatform\Presentation\Console\EventMonitorCommand;
use Modules\Platform\EventPlatform\Presentation\Console\ReplayEventsCommand;

final class EventPlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Core infrastructure singletons ────────────────────────────────────
        $this->app->singleton(EnterpriseEventSerializer::class);
        $this->app->singleton(EnterpriseEventRegistry::class);

        $this->app->singleton(EnterpriseEventStoreInterface::class, EloquentEventStore::class);
        $this->app->singleton(EnterpriseEventRegistryInterface::class, EnterpriseEventRegistry::class);
        $this->app->singleton(EnterpriseDeadLetterQueueInterface::class, EloquentDeadLetterQueueRepository::class);

        $this->app->singleton(EnterpriseEventDispatcher::class);
        $this->app->singleton(EnterpriseEventPublisher::class);
        $this->app->singleton(EnterpriseEventReplayService::class);
        $this->app->singleton(EnterpriseEventMonitor::class);

        $this->app->singleton(EnterpriseEventBus::class);
        $this->app->singleton(EnterpriseEventBusInterface::class, EnterpriseEventBus::class);

        // ── Bind legacy DomainEventBus to the enterprise bus ─────────────────
        // Any code injecting DomainEventBus now routes through the enterprise bus.
        $this->app->bind(DomainEventBus::class, EnterpriseEventBus::class);
    }

    public function boot(): void
    {
        /** @var EnterpriseEventBus $bus */
        $bus = $this->app->make(EnterpriseEventBus::class);

        // ── Register Demand Engine subscribers ───────────────────────────────
        $this->registerDemandEngineSubscribers($bus);

        // ── Bridge: route legacy Dispatchable events into the enterprise bus ─
        // When WaveCreated::dispatch() fires, the bus receives it, persists it
        // to the event store, and routes to subscribers via HandleEnterpriseEventJob.
        $this->bridgeLegacyEvents($bus);

        // ── Console commands ──────────────────────────────────────────────────
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReplayEventsCommand::class,
                DrainDlqCommand::class,
                EventMonitorCommand::class,
            ]);
        }
    }

    private function registerDemandEngineSubscribers(EnterpriseEventBus $bus): void
    {
        $bus->subscribe('preparation.wave.created',                  WaveCreatedListener::class,             RetryPolicy::standard(), priority: 10, queue: 'demand');
        $bus->subscribe('preparation.wave.closed',                   WaveClosedListener::class,              RetryPolicy::standard(), priority: 10, queue: 'demand');
        $bus->subscribe('preparation.wave.demand_refresh_requested', DemandRefreshRequestedListener::class,  RetryPolicy::standard(), priority: 10, queue: 'demand');
        $bus->subscribe('preparation.wave.order_added',              OrderAddedToWaveListener::class,        RetryPolicy::standard(), priority: 10, queue: 'demand');
        $bus->subscribe('preparation.wave.order_removed',            OrderRemovedFromWaveListener::class,    RetryPolicy::standard(), priority: 10, queue: 'demand');
        $bus->subscribe('preparation.wave.order_moved_to_preparing', OrderMovedToPreparingListener::class,  RetryPolicy::standard(), priority: 10, queue: 'demand');
        $bus->subscribe('manufacturing.production_job.completed',    ManufacturingCompletedListener::class,  RetryPolicy::standard(), priority: 10, queue: 'demand');
        // GoodsReceiptCompleted and InventoryReturned: listeners are ready; wire once events are available.
        // $bus->subscribe('inventory.goods_receipt.completed', GoodsReceiptCompletedListener::class, RetryPolicy::standard(), priority: 10, queue: 'demand');
        // $bus->subscribe('inventory.returned',                InventoryReturnedListener::class,     RetryPolicy::standard(), priority: 10, queue: 'demand');
    }

    /**
     * Bridge legacy DomainEvent dispatches into the Enterprise Event Platform.
     *
     * Existing code calls WaveCreated::dispatch(...) which fires via Laravel's Dispatchable trait.
     * These closures intercept those dispatches and route them through the enterprise bus,
     * which stores them and delivers to subscribers via HandleEnterpriseEventJob.
     *
     * No existing call sites need to change.
     */
    private function bridgeLegacyEvents(EnterpriseEventBus $bus): void
    {
        Event::listen(WaveCreated::class,            fn (WaveCreated $e)            => $bus->publish($e));
        Event::listen(WaveClosed::class,             fn (WaveClosed $e)             => $bus->publish($e));
        Event::listen(DemandRefreshRequested::class, fn (DemandRefreshRequested $e) => $bus->publish($e));
        Event::listen(OrderAddedToWave::class,       fn (OrderAddedToWave $e)       => $bus->publish($e));
        Event::listen(OrderRemovedFromWave::class,   fn (OrderRemovedFromWave $e)   => $bus->publish($e));
        Event::listen(OrderMovedToPreparing::class,  fn (OrderMovedToPreparing $e)  => $bus->publish($e));

        // ManufacturingJobCompletedEvent does not implement DomainEvent — keep legacy listener for now.
        // TODO: Convert ManufacturingJobCompletedEvent to a proper EnterpriseEvent when Manufacturing OS is migrated.
    }
}
