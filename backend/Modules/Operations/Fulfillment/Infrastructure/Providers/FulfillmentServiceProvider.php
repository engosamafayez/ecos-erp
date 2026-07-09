<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Services\BulkWorkflowEngine;
use Modules\Operations\Fulfillment\Application\Workflows\CancelOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\CompleteDeliveryWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ConfirmOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\LoadVehicleWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToPreparationWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReceiveReturnWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReturnOrderWorkflow;

final class FulfillmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FulfillmentEngine::class);
        $this->app->singleton(BulkWorkflowEngine::class);

        // Workflows — transient: each request gets a fresh instance
        $this->app->bind(ConfirmOrderWorkflow::class);
        $this->app->bind(CancelOrderWorkflow::class);
        $this->app->bind(MoveToPreparationWorkflow::class);
        $this->app->bind(CompleteDeliveryWorkflow::class);
        $this->app->bind(ReturnOrderWorkflow::class);
        $this->app->bind(LoadVehicleWorkflow::class);
        $this->app->bind(ReceiveReturnWorkflow::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            __DIR__ . '/../Database/Migrations'
        );
    }
}
