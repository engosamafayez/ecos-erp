<?php

declare(strict_types=1);

namespace Modules\Operations\OrderLifecycle\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Manufacturing\ManufacturingPolicy\Domain\Services\ManufacturingPolicy;
use Modules\Manufacturing\ManufacturingService\Application\Services\ManufacturingApplicationService;
use Modules\Operations\OrderLifecycle\Application\Services\OrderLifecycleCoordinator;

final class OrderLifecycleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            OrderLifecycleCoordinator::class,
            function ($app): OrderLifecycleCoordinator {
                return new OrderLifecycleCoordinator(
                    policy:        $app->make(ManufacturingPolicy::class),
                    manufacturing: $app->make(ManufacturingApplicationService::class),
                );
            },
        );
    }
}
