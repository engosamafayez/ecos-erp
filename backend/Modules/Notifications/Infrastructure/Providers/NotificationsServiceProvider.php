<?php

declare(strict_types=1);

namespace Modules\Notifications\Infrastructure\Providers;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\Domain\Contracts\NotificationDeliveryPolicyInterface;
use Modules\Notifications\Application\Services\NotificationDeliveryPolicy;
use Modules\Notifications\Infrastructure\Notifications\Channels\CoreDatabaseChannel;

/**
 * Notifications module service provider (ADR-047 / TASK-ECOS-NOTIFICATIONS-FOUNDATION-002).
 *
 * Registers the shared producer contract's delivery channel and loads this module's
 * schema-extension migration. Does not touch routes/api.php's existing notification
 * endpoints — those stay on {@see \App\Http\Controllers\NotificationController}, extended
 * in place rather than relocated, per this task's own "no rewrite for stylistic
 * uniformity alone" instruction.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationDeliveryPolicyInterface::class, NotificationDeliveryPolicy::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Notification::extend('notifications-core', fn ($app) => $app->make(CoreDatabaseChannel::class));
    }
}
