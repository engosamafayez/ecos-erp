<?php

declare(strict_types=1);

namespace Modules\POS\Application\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\POS\Application\Contracts\DomainEventPublisherInterface;
use Modules\POS\Application\Infrastructure\EventPublishing\LaravelDomainEventPublisher;
use Modules\POS\Application\Services\CloseSessionService;
use Modules\POS\Application\Services\CloseShiftService;
use Modules\POS\Application\Services\OpenSessionService;
use Modules\POS\Application\Services\OpenShiftService;
use Modules\POS\Application\Services\ProcessExchangeService;
use Modules\POS\Application\Services\ProcessReturnService;
use Modules\POS\Application\Services\ProcessSaleService;
use Modules\POS\Application\Services\ReprintReceiptService;
use Modules\POS\Application\Services\VoidReceiptService;

final class ApplicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DomainEventPublisherInterface::class, LaravelDomainEventPublisher::class);

        $this->app->singleton(OpenSessionService::class);
        $this->app->singleton(CloseSessionService::class);
        $this->app->singleton(OpenShiftService::class);
        $this->app->singleton(CloseShiftService::class);
        $this->app->singleton(ProcessSaleService::class);
        $this->app->singleton(ProcessReturnService::class);
        $this->app->singleton(ProcessExchangeService::class);
        $this->app->singleton(ReprintReceiptService::class);
        $this->app->singleton(VoidReceiptService::class);
    }
}
