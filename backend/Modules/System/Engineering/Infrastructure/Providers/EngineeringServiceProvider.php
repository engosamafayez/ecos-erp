<?php

declare(strict_types=1);

namespace Modules\System\Engineering\Infrastructure\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\System\Engineering\Application\Listeners\Pipeline\NotifyOnPipelineEventListener;
use Modules\System\Engineering\Application\Services\ImportEngineeringReportService;
use Modules\System\Engineering\Application\Services\PipelineAnalyticsService;
use Modules\System\Engineering\Application\Services\PipelineNotificationService;
use Modules\System\Engineering\Application\Services\PipelineRecoveryService;
use Modules\System\Engineering\Application\Services\PipelineStageExecutor;
use Modules\System\Engineering\Application\Services\PipelineTemplateEngine;
use Modules\System\Engineering\Application\Services\ReleasePipelineService;
use Modules\System\Engineering\Application\Services\RetryPolicyFactory;
use Modules\System\Engineering\Domain\Contracts\EngineeringRunRepositoryInterface;
use Modules\System\Engineering\Domain\Events\Pipeline\PipelineCancelled;
use Modules\System\Engineering\Domain\Events\Pipeline\PipelineCompleted;
use Modules\System\Engineering\Domain\Events\Pipeline\PipelineCreated;
use Modules\System\Engineering\Domain\Events\Pipeline\PipelineFailed;
use Modules\System\Engineering\Domain\Events\Pipeline\PipelineStarted;
use Modules\System\Engineering\Domain\Events\Pipeline\StageFailed;
use Modules\System\Engineering\Infrastructure\Registry\ProviderRegistry;
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

        // Provider Registry — resolves CI providers from config
        $this->app->singleton(ProviderRegistry::class);

        // ENG-007 services
        $this->app->singleton(PipelineStageExecutor::class, function ($app) {
            return new PipelineStageExecutor($app->make(ProviderRegistry::class));
        });

        $this->app->singleton(PipelineNotificationService::class);
        $this->app->singleton(PipelineTemplateEngine::class);
        $this->app->singleton(RetryPolicyFactory::class);
        $this->app->singleton(PipelineAnalyticsService::class);
        $this->app->singleton(PipelineRecoveryService::class);

        $this->app->bind(ReleasePipelineService::class, function ($app) {
            return new ReleasePipelineService(
                $app->make(PipelineStageExecutor::class),
                $app->make(PipelineTemplateEngine::class),
                $app->make(RetryPolicyFactory::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            __DIR__ . '/../Database/Migrations'
        );

        $this->registerPipelineEventListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportEngineeringReportCommand::class,
                RunPipelineCommand::class,
            ]);
        }
    }

    private function registerPipelineEventListeners(): void
    {
        Event::listen(PipelineStarted::class,  function ($e) {
            $this->app->make(NotifyOnPipelineEventListener::class)->handlePipelineStarted($e);
        });

        Event::listen(PipelineCompleted::class, function ($e) {
            $this->app->make(NotifyOnPipelineEventListener::class)->handlePipelineCompleted($e);
        });

        Event::listen(PipelineFailed::class, function ($e) {
            $this->app->make(NotifyOnPipelineEventListener::class)->handlePipelineFailed($e);
        });

        Event::listen(StageFailed::class, function ($e) {
            $this->app->make(NotifyOnPipelineEventListener::class)->handleStageFailed($e);
        });

        Event::listen(PipelineCreated::class, function ($e) {
            $this->app->make(NotifyOnPipelineEventListener::class)->handlePipelineCreated($e);
        });

        Event::listen(PipelineCancelled::class, function ($e) {
            $this->app->make(NotifyOnPipelineEventListener::class)->handlePipelineCancelled($e);
        });
    }
}
