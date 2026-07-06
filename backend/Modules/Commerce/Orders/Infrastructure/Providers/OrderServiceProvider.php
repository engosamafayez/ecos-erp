<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWaveCancelled;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWaveCompleted;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWaveStarted;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Infrastructure\Repositories\EloquentOrderRepository;
use Modules\Operations\Preparation\Domain\Events\WaveCancelled;
use Modules\Operations\Preparation\Domain\Events\WaveCompleted;
use Modules\Operations\Preparation\Domain\Events\WaveStarted;

final class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $events = $this->app->make('events');
        $events->listen(WaveStarted::class, HandlePreparationWaveStarted::class);
        $events->listen(WaveCompleted::class, HandlePreparationWaveCompleted::class);
        $events->listen(WaveCancelled::class, HandlePreparationWaveCancelled::class);
    }
}
