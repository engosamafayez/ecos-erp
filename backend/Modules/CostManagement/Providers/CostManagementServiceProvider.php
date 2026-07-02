<?php

declare(strict_types=1);

namespace Modules\CostManagement\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\CostManagement\Domain\Services\CostCascadeService;
use Modules\CostManagement\Domain\Services\MaterialCostService;
use Modules\CostManagement\Domain\Services\PricingReviewService;
use Modules\CostManagement\Domain\Services\ProductCostCalculator;
use Modules\CostManagement\Domain\Services\RecipeCostCalculator;

class CostManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecipeCostCalculator::class);
        $this->app->singleton(ProductCostCalculator::class);

        $this->app->singleton(CostCascadeService::class, function ($app) {
            return new CostCascadeService(
                $app->make(RecipeCostCalculator::class),
                $app->make(ProductCostCalculator::class),
            );
        });

        $this->app->singleton(MaterialCostService::class, function ($app) {
            return new MaterialCostService(
                $app->make(CostCascadeService::class),
            );
        });

        $this->app->singleton(PricingReviewService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Infrastructure/Database/Migrations');
    }
}
