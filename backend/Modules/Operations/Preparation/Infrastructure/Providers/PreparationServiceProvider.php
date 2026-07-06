<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Infrastructure\Providers;

use App\Core\Audit\AuditService;
use App\Core\Documents\DocumentService;
use App\Core\FeatureFlags\FeatureFlagService;
use App\Core\Timeline\TimelineService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\DomainEvents\Events\InventoryStockReceived;
use Modules\Operations\Preparation\Application\Events\Inbound\LoadingPoolReservationReleasedEvent;
use Modules\Operations\Preparation\Application\Events\Inbound\LoadingPoolReservedEvent;
use Modules\Operations\Preparation\Application\Events\Inbound\LoadingProductLoadedEvent;
use Modules\Operations\Preparation\Application\Events\Inbound\ManufacturingJobCompletedEvent;
use Modules\Operations\Preparation\Application\Events\Inbound\ManufacturingJobCreatedEvent;
use Modules\Operations\Preparation\Application\Listeners\LoadingPoolReservationReleasedListener;
use Modules\Operations\Preparation\Application\Listeners\LoadingPoolReservedListener;
use Modules\Operations\Preparation\Application\Listeners\LoadingProductLoadedListener;
use Modules\Operations\Preparation\Application\Listeners\ManufacturingJobCompletedListener;
use Modules\Operations\Preparation\Application\Listeners\ManufacturingJobCreatedListener;
use Modules\Operations\Preparation\Application\Listeners\StockAddedListener;
use Modules\Operations\Preparation\Domain\Models\PreparedProductsPool;
use Modules\Operations\Preparation\Domain\Models\PreparationStation;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Services\FulfillmentPolicyService;
use Modules\Operations\Preparation\Domain\Services\PreparationPolicyService;
use Modules\Operations\Preparation\Policies\PreparedPoolPolicy;
use Modules\Operations\Preparation\Policies\PreparationStationPolicy;
use Modules\Operations\Preparation\Policies\PreparationWavePolicy;

final class PreparationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
        $this->app->singleton(FeatureFlagService::class);
        $this->app->singleton(TimelineService::class);
        $this->app->singleton(DocumentService::class);

        $this->app->singleton(
            FulfillmentPolicyService::class,
            fn ($app) => new FulfillmentPolicyService($app->make(FeatureFlagService::class))
        );

        $this->app->singleton(
            PreparationPolicyService::class,
            fn ($app) => new PreparationPolicyService($app->make(FeatureFlagService::class))
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Gate::policy(PreparationWave::class, PreparationWavePolicy::class);
        Gate::policy(PreparedProductsPool::class, PreparedPoolPolicy::class);
        Gate::policy(PreparationStation::class, PreparationStationPolicy::class);

        $events = $this->app->make('events');

        // Inbound listeners (other modules → Preparation)
        $events->listen(InventoryStockReceived::class, StockAddedListener::class);
        $events->listen(ManufacturingJobCreatedEvent::class, ManufacturingJobCreatedListener::class);
        $events->listen(ManufacturingJobCompletedEvent::class, ManufacturingJobCompletedListener::class);
        $events->listen(LoadingPoolReservedEvent::class, LoadingPoolReservedListener::class);
        $events->listen(LoadingPoolReservationReleasedEvent::class, LoadingPoolReservationReleasedListener::class);
        $events->listen(LoadingProductLoadedEvent::class, LoadingProductLoadedListener::class);
    }
}
