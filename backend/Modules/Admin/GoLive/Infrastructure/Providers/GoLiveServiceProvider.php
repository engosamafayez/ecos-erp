<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;

final class GoLiveServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
