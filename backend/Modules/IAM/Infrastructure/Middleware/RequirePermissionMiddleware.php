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
 *   ->middleware('permission:products.view')
 *   ->middleware('permission:orders.fulfill')
 *
 * Returns 401 for unauthenticated requests, 403 for authenticated requests
 * that lack the required permission.
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

        // Super Admin bypass: if the Gate has already returned true via
        // Gate::before(), the route would not reach here. The explicit role
        // check below ensures the middleware path also respects super-admin.
        if ($this->permissions->userHasRole($user, 'super-admin')) {
            return $next($request);
        }

        if (! $this->permissions->userHasPermission($user, $permission)) {
            abort(403, "Permission denied: {$permission}");
        }

        return $next($request);
    }
}
