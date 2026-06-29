<?php

declare(strict_types=1);

namespace Modules\IAM\Infrastructure\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\IAM\Application\Services\PermissionService;
use Modules\IAM\Domain\Contracts\AuthServiceInterface;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Infrastructure\Middleware\RequirePermissionMiddleware;
use Modules\IAM\Infrastructure\Services\SanctumAuthService;

/**
 * IAM module service provider.
 *
 * Responsibilities:
 *  1. Bind auth + permission service ports to their implementations.
 *  2. Load RBAC migrations.
 *  3. Register the `permission:` route middleware alias.
 *  4. Wire Gate::before() so Super Admin bypasses all ability checks.
 */
final class IamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthServiceInterface::class, SanctumAuthService::class);

        $this->app->bind(PermissionServiceInterface::class, PermissionService::class);
    }

    public function boot(): void
    {
        // Load RBAC migrations from the IAM module directory.
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Register the `permission:` middleware alias so routes can use
        // ->middleware('permission:products.view') without touching bootstrap/app.php.
        $this->app['router']->aliasMiddleware('permission', RequirePermissionMiddleware::class);

        // Super Admin gate bypass: returning `true` from Gate::before() skips
        // all subsequent policy / ability checks for this request.
        Gate::before(function (User $user, string $ability): ?bool {
            /** @var PermissionServiceInterface $permissions */
            $permissions = $this->app->make(PermissionServiceInterface::class);

            if ($permissions->userHasRole($user, 'super-admin')) {
                return true;
            }

            return null; // Fall through to individual policies / Gate::define() checks.
        });
    }
}
