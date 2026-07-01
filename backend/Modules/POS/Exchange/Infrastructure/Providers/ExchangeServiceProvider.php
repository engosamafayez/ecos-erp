<?php

declare(strict_types=1);

namespace Modules\POS\Exchange\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\POS\Exchange\Domain\Contracts\ExchangeRepositoryInterface;
use Modules\POS\Exchange\Infrastructure\Repositories\EloquentExchangeRepository;

final class ExchangeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExchangeRepositoryInterface::class, EloquentExchangeRepository::class);
    }
}
