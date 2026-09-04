<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Contracts;

use App\Models\User;
use Modules\IAM\Domain\Enums\FieldVisibility;
use Modules\IAM\Domain\Models\Role;

/**
 * VisibilityResolverInterface — the Information Visibility Engine
 * (TASK-IAM-002 / ADR-038, Part 2).
 *
 * Independent from authorization: decides which fields of an already-authorized
 * resource a user may see or edit, based on the field's required permission.
 */
interface VisibilityResolverInterface
{
    /**
     * The visibility state of a single field for the user.
     */
    public function fieldState(User $user, string $resource, string $field): FieldVisibility;

    /**
     * Fields that must be removed before serialisation (state HIDDEN).
     *
     * @return list<string>
     */
    public function hiddenFields(User $user, string $resource): array;

    /**
     * Drop every cached hidden-field set for a specific user, across every resource it was
     * ever computed for (TASK-ECOS-IAM-SECURE-ADMIN-API-002, Security Gate B). Call this
     * wherever PermissionServiceInterface::invalidateUserCache() is called — a permission
     * change can change field visibility too.
     */
    public function invalidateUserCache(int $userId): void;

    /**
     * Drop the cached hidden-field set for every user who holds the given role.
     */
    public function invalidateRoleCache(Role $role): void;
}
