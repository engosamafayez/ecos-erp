<?php

declare(strict_types=1);

namespace Modules\System\Engineering\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\System\Engineering\Application\Services\ImportEngineeringReportService;
use Modules\System\Engineering\Application\Services\PipelineNotificationService;
use Modules\System\Engineering\Application\Services\PipelineStageExecutor;
use Modules\System\Engineering\Application\Services\ReleasePipelineService;
use Modules\System\Engineering\Domain\Contracts\EngineeringRunRepositoryInterface;
use Modules\System\Engineering\Infrastructure\Repositories\EloquentEngineeringRunRepository;
use Modules\System\Engineering\Presentation\Console\Commands\ImportEngineeringReportCommand;
use Modules\System\Engineering\Presentation\Console\Commands\RunPipelineCommand;

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

        $this->app->singleton(PipelineStageExecutor::class);
        $this->app->singleton(PipelineNotificationService::class);

        $this->app->bind(ReleasePipelineService::class, function ($app) {
            return new ReleasePipelineService(
                $app->make(PipelineStageExecutor::class),
                $app->make(PipelineNotificationService::class),
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
                RunPipelineCommand::class,
            ]);
        }
    }
}
