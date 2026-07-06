<?php

declare(strict_types=1);

namespace Modules\IAM\Infrastructure\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\IAM\Application\Services\PermissionService;
use Modules\IAM\Application\Commands\ResetDevAdminCommand;
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
 *  4. Wire Gate::before() so system roles bypass all ability checks.
 *     The bypass is keyed on is_system = true — never on a hardcoded slug.
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
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ResetDevAdminCommand::class]);
        }

        $this->app['router']->aliasMiddleware('permission', RequirePermissionMiddleware::class);

        // System role gate bypass: any role with is_system = true skips all
        // subsequent policy / ability checks. This covers Super Admin today and
        // any future system roles (Owner, Support, etc.) without code changes.
        Gate::before(function (User $user, string $ability): ?bool {
            /** @var PermissionServiceInterface $permissions */
            $permissions = $this->app->make(PermissionServiceInterface::class);

            if ($permissions->userHasSystemRole($user)) {
                return true;
            }

            return null;
        });
    }
}
