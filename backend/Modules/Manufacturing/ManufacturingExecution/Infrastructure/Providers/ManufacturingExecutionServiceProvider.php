<?php

declare(strict_types=1);

namespace Modules\Manufacturing\ManufacturingExecution\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Manufacturing\ManufacturingExecution\Application\Services\ManufacturingExecutor;
use Modules\Manufacturing\ManufacturingExecution\Domain\Contracts\ManufacturingTransactionRepositoryInterface;
use Modules\Manufacturing\ManufacturingExecution\Domain\Services\ExecutionPipeline;
use Modules\Manufacturing\ManufacturingExecution\Infrastructure\Persistence\EloquentManufacturingTransactionRepository;

final class ManufacturingExecutionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ManufacturingTransactionRepositoryInterface::class,
            EloquentManufacturingTransactionRepository::class,
        );

        $this->app->singleton(ExecutionPipeline::class);
        $this->app->singleton(ManufacturingExecutor::class);
    }
}
