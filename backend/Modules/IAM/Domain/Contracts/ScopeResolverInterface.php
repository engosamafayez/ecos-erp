<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Contracts;

use App\Models\User;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\ValueObjects\ScopeConstraint;

/**
 * ScopeResolverInterface — the Data Scope Engine (TASK-IAM-002 / ADR-038, Part 3).
 *
 * Turns a user + resource into a declarative ScopeConstraint. Business modules never
 * filter data by hand; they apply the constraint via the `scopedTo()` query macro.
 */
interface ScopeResolverInterface
{
    /**
     * Resolve the widest data scope the user holds for the resource and return the
     * constraint that narrows a query to it. ALL → unrestricted; unresolved → deny.
     *
     * @param  string  $resource  e.g. "sales.orders"
     * @param  string|null  $ownerColumn  the column that carries the record owner for SELF (default: created_by)
     */
    public function resolve(User $user, string $resource, ?string $ownerColumn = null): ScopeConstraint;

    /**
     * Drop every cached scope resolution for a specific user, across every resource it was
     * ever computed for (TASK-ECOS-IAM-SECURE-ADMIN-API-002, Security Gate B). Call this
     * wherever PermissionServiceInterface::invalidateUserCache() is called — a permission
     * or data_scope change can change the resolved scope too.
     */
    public function invalidateUserCache(int $userId): void;

    /**
     * Drop the cached scope resolution for every user who holds the given role.
     */
    public function invalidateRoleCache(Role $role): void;
}
