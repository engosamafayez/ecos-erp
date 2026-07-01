<?php

declare(strict_types=1);

namespace Modules\POS\Discount\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\POS\Discount\Domain\Contracts\DiscountRepositoryInterface;
use Modules\POS\Discount\Infrastructure\Repositories\EloquentDiscountRepository;

final class DiscountServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            DiscountRepositoryInterface::class,
            EloquentDiscountRepository::class,
        );
    }
}
