<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Contracts;

use App\Models\User;
use Modules\IAM\Domain\Models\Role;

interface PermissionServiceInterface
{
    /**
     * Return true when the user holds the given permission through any of
     * their assigned roles.
     */
    public function userHasPermission(User $user, string $permission): bool;

    /**
     * Return true when the user holds the given role (matched by slug).
     */
    public function userHasRole(User $user, string $roleSlug): bool;

    /**
     * Return true when the role directly has the given permission.
     */
    public function roleHasPermission(Role $role, string $permission): bool;

    /**
     * Return all permission names for the given user (merged across all roles).
     *
     * @return list<string>
     */
    public function getUserPermissions(User $user): array;

    /**
     * Drop the permission cache for a specific user.
     * Call this after any role or permission assignment change.
     */
    public function invalidateUserCache(int $userId): void;

    /**
     * Drop the permission cache for every user who holds the given role.
     * Call this after a role's permission set changes.
     */
    public function invalidateRoleCache(Role $role): void;
}
