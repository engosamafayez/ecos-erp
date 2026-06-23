<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Manufacturing\BillsOfMaterials\Domain\Contracts\BomRepositoryInterface;
use Modules\Manufacturing\BillsOfMaterials\Infrastructure\Repositories\EloquentBomRepository;

final class BomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BomRepositoryInterface::class, EloquentBomRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
