<?php

declare(strict_types=1);

namespace Modules\Logistics\Carriers\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Logistics\Carriers\Domain\Services\CarrierAdapterFactory;
use Modules\Logistics\Carriers\Infrastructure\Adapters\Bosta\BostaCarrierAdapter;
use Modules\Logistics\Carriers\Infrastructure\Adapters\InternalFleetAdapter;

/**
 * Carriers — the integration FOUNDATION.
 *
 * Phase 2 registered the internal fleet adapter only. TASK-ECOS-V1.1-OPS-03-
 * TASK1-BOSTA adds the first provider-specific adapter, in business-priority
 * order (D4/D7) — exactly as this docblock originally anticipated: a new
 * class in its own folder, registered here, nothing outside that folder
 * changes.
 */
final class LogisticsCarriersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CarrierAdapterFactory::class, static function (): CarrierAdapterFactory {
            return new CarrierAdapterFactory([
                // Own fleet is a first-class carrier, so the core cannot tell
                // the difference between delivering ourselves and tendering out.
                new InternalFleetAdapter,
                new BostaCarrierAdapter,
            ]);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
