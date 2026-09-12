<?php

declare(strict_types=1);

namespace App\Providers;

use App\Broadcasting\FailClosedLogBroadcaster;
use App\Broadcasting\FailClosedNullBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TASK-ECOS-V1-PRIVATE-CONVERSATION-BROADCAST-SECURITY-038. Stock
        // LogBroadcaster/NullBroadcaster::auth() never enforce
        // routes/channels.php at all (see FailClosedLogBroadcaster's
        // docblock) — every private channel was open to every authenticated
        // user under either driver. These extend() calls replace both
        // built-in drivers with fail-closed subclasses that enforce the
        // exact same channel-authorization callbacks every real broadcaster
        // (Pusher/Reverb/Redis/Ably) already enforces. Keyed by driver name
        // (BroadcastManager::resolve() checks customCreators before its own
        // create{Driver}Driver() methods), so this applies regardless of
        // which connection name in config/broadcasting.php uses that driver.
        Broadcast::extend('log', fn ($app) => new FailClosedLogBroadcaster(
            $app->make(LoggerInterface::class),
        ));

        Broadcast::extend('null', fn () => new FailClosedNullBroadcaster);
    }
}
