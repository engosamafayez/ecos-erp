<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;

final class DemandAnalysisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // DemandAnalysisService is concrete with no interface binding.
        // Laravel's container auto-resolves it via constructor injection.
    }
}
