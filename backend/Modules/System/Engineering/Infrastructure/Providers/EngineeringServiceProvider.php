<?php

declare(strict_types=1);

namespace Modules\System\Engineering\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\System\Engineering\Application\Services\ImportEngineeringReportService;
use Modules\System\Engineering\Domain\Contracts\EngineeringRunRepositoryInterface;
use Modules\System\Engineering\Infrastructure\Repositories\EloquentEngineeringRunRepository;
use Modules\System\Engineering\Presentation\Console\Commands\ImportEngineeringReportCommand;

final class EngineeringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            EngineeringRunRepositoryInterface::class,
            EloquentEngineeringRunRepository::class,
        );

        $this->app->bind(ImportEngineeringReportService::class, function ($app) {
            return new ImportEngineeringReportService(
                $app->make(EngineeringRunRepositoryInterface::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            __DIR__ . '/../Database/Migrations'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportEngineeringReportCommand::class,
            ]);
        }
    }
}
