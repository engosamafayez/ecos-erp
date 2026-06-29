<?php

declare(strict_types=1);

namespace Modules\IAM\Infrastructure\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware alias: permission
 *
 * Usage in routes:
 *   ->middleware('permission:inventory.products.view')
 *   ->middleware('permission:sales.orders.fulfill')
 *
 * Returns 401 for unauthenticated requests, 403 for authenticated requests
 * that lack the required permission.
 *
 * System role bypass: any role with is_system = true passes unconditionally,
 * regardless of slug. Never hardcode role names here.
 */
final class RequirePermissionMiddleware
{
    public function __construct(
        private readonly PermissionServiceInterface $permissions,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        if ($this->permissions->userHasSystemRole($user)) {
            return $next($request);
        }

        if (! $this->permissions->userHasPermission($user, $permission)) {
            abort(403, "Permission denied: {$permission}");
        }

        return $next($request);
    }
}
