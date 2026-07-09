<?php

declare(strict_types=1);

namespace Modules\Marketing\MetaConnector\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Marketing\Connections\Application\Services\ConnectorRegistry;
use Modules\Marketing\MetaConnector\Application\Services\MetaConnector;
use Modules\Marketing\MetaConnector\Application\Services\MetaOAuthService;
use Modules\Marketing\MetaConnector\Domain\Services\MetaApiClient;

final class MetaConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind MetaApiClient with config values
        $this->app->singleton(MetaApiClient::class, function () {
            return new MetaApiClient(
                appId:     (string) config('services.meta.app_id', ''),
                appSecret: (string) config('services.meta.app_secret', ''),
            );
        });

        $redirectUri = (string) config('services.meta.redirect_uri', '');

        $this->app->singleton(MetaOAuthService::class, function ($app) use ($redirectUri) {
            return new MetaOAuthService(
                apiClient:   $app->make(MetaApiClient::class),
                redirectUri: $redirectUri,
            );
        });

        $this->app->singleton(MetaConnector::class, function ($app) use ($redirectUri) {
            return new MetaConnector(
                apiClient:   $app->make(MetaApiClient::class),
                oauthService: $app->make(MetaOAuthService::class),
                redirectUri: $redirectUri,
            );
        });
    }

    public function boot(): void
    {
        // Register the Meta connector into the shared ConnectorRegistry
        $registry  = $this->app->make(ConnectorRegistry::class);
        $connector = $this->app->make(MetaConnector::class);
        $registry->register($connector);
    }
}
