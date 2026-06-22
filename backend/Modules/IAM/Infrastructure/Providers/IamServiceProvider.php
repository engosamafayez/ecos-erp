<?php

declare(strict_types=1);

namespace Modules\IAM\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\IAM\Domain\Contracts\AuthServiceInterface;
use Modules\IAM\Infrastructure\Services\SanctumAuthService;

/**
 * Service provider for the IAM module. Binds the authentication port to its
 * Sanctum implementation.
 */
final class IamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthServiceInterface::class, SanctumAuthService::class);
    }
}
