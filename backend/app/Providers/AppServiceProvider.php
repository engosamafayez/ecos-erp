<?php

declare(strict_types=1);

namespace App\Providers;

use App\Broadcasting\FailClosedLogBroadcaster;
use App\Broadcasting\FailClosedNullBroadcaster;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
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

        // CORE-03 Task 2 §3 — a NAMED limiter (not an inline 'throttle:N,1'
        // string) so the bound is read from config on every request rather than
        // baked in once at route-registration time; this is what makes the
        // threshold actually testable via config(['ai.rate_limit_per_minute' => N])
        // inside a test, and keyed by user id (falling back to IP), matching the
        // codebase's existing throttle:N,1 convention's own default behaviour.
        RateLimiter::for('ai-assistant', function (Request $request) {
            return Limit::perMinute((int) config('ai.rate_limit_per_minute', 20))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
