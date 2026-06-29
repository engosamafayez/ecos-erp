<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Domain\Models\Role;

/**
 * Central permission authority.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  Cache strategy                                                          │
 * │                                                                          │
 * │  Key   rbac.user.{id}.perms                                              │
 * │  TTL   300 s (auto-expires; safety net when invalidation is missed)      │
 * │                                                                          │
 * │  Invalidation                                                            │
 * │  • Role assigned/revoked for a user → invalidateUserCache($userId)       │
 * │  • Permission added/removed from a role → invalidateRoleCache($role)     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class PermissionService implements PermissionServiceInterface
{
    private const TTL = 300;

    private const CACHE_PREFIX = 'rbac.user.';

    public function userHasPermission(User $user, string $permission): bool
    {
        return in_array($permission, $this->getUserPermissions($user), true);
    }

    public function userHasRole(User $user, string $roleSlug): bool
    {
        // Avoid loading the full permission cache just for a role check.
        return $user->roles()->where('slug', $roleSlug)->exists();
    }

    public function roleHasPermission(Role $role, string $permission): bool
    {
        return $role->permissions()->where('name', $permission)->exists();
    }

    /**
     * @return list<string>
     */
    public function getUserPermissions(User $user): array
    {
        /** @var list<string> $perms */
        $perms = Cache::remember(
            self::CACHE_PREFIX.$user->id.'.perms',
            self::TTL,
            function () use ($user): array {
                return $user->roles()
                    ->with('permissions')
                    ->get()
                    ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
                    ->unique()
                    ->values()
                    ->all();
            },
        );

        return $perms;
    }

    public function invalidateUserCache(int $userId): void
    {
        Cache::forget(self::CACHE_PREFIX.$userId.'.perms');
    }

    public function invalidateRoleCache(Role $role): void
    {
        // Find every user assigned this role and drop their cache entry.
        $role->users()->select('users.id')->each(
            fn (User $user) => $this->invalidateUserCache($user->id),
        );
    }
}
