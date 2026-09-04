<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Domain\Contracts\SensitiveFieldRegistryInterface;
use Modules\IAM\Domain\Contracts\VisibilityResolverInterface;
use Modules\IAM\Domain\Enums\FieldVisibility;
use Modules\IAM\Domain\Models\Role;

/**
 * VisibilityResolver — the Information Visibility Engine (TASK-IAM-002 / ADR-038, Part 2).
 *
 * A field is HIDDEN when it is declared sensitive AND the user lacks its required
 * permission. Everything else is VISIBLE. (READ_ONLY is reserved for the edit-permission
 * mapping added when write-visibility is needed; the enum already supports it.)
 *
 * Reuses the existing per-user permission cache indirectly (PermissionService is cached)
 * and memoises the per-(user,resource) hidden set for the request under an `rbac.vis.*`
 * key.
 *
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002, Security Gate B: `rbac.vis.*` is keyed per
 * (user, resource), and resource is open-ended, so it cannot be forgotten by a single key —
 * unlike PermissionService's single `rbac.user.{id}.perms` key. A small per-user index
 * (`rbac.vis.{id}.index`, same TTL) records every resource ever cached for that user, so
 * invalidateUserCache() can deterministically forget every one of them rather than relying
 * on the 300s TTL alone. This reuses the same Cache facade/keying scheme already in use here
 * — it is not a second cache mechanism.
 */
final class VisibilityResolver implements VisibilityResolverInterface
{
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly SensitiveFieldRegistryInterface $fields,
        private readonly PermissionServiceInterface $permissions,
    ) {
    }

    public function fieldState(User $user, string $resource, string $field): FieldVisibility
    {
        $permission = $this->fields->permissionFor($resource, $field);

        // Not declared sensitive → always visible.
        if ($permission === null) {
            return FieldVisibility::VISIBLE;
        }

        return $this->permissions->userHasPermission($user, $permission)
            ? FieldVisibility::VISIBLE
            : FieldVisibility::HIDDEN;
    }

    public function hiddenFields(User $user, string $resource): array
    {
        $map = $this->fields->fieldsFor($resource);

        if ($map === []) {
            return [];
        }

        // System roles see everything.
        if ($this->permissions->userHasSystemRole($user)) {
            return [];
        }

        $userId = (int) $user->getKey();
        $key = "rbac.vis.{$userId}.{$resource}";

        /** @var list<string> $hidden */
        $hidden = Cache::remember($key, self::CACHE_TTL, function () use ($user, $map): array {
            $hidden = [];

            foreach ($map as $field => $permission) {
                if (! $this->permissions->userHasPermission($user, $permission)) {
                    $hidden[] = $field;
                }
            }

            return $hidden;
        });

        $this->trackCachedResource($userId, $resource);

        return $hidden;
    }

    public function invalidateUserCache(int $userId): void
    {
        $indexKey = "rbac.vis.{$userId}.index";

        /** @var list<string> $resources */
        $resources = Cache::get($indexKey, []);
        foreach ($resources as $resource) {
            Cache::forget("rbac.vis.{$userId}.{$resource}");
        }
        Cache::forget($indexKey);
    }

    public function invalidateRoleCache(Role $role): void
    {
        $role->users()->select('users.id')->each(
            fn (User $user) => $this->invalidateUserCache((int) $user->getKey()),
        );
    }

    private function trackCachedResource(int $userId, string $resource): void
    {
        $indexKey = "rbac.vis.{$userId}.index";

        /** @var list<string> $known */
        $known = Cache::get($indexKey, []);
        if (! in_array($resource, $known, true)) {
            $known[] = $resource;
            Cache::put($indexKey, $known, self::CACHE_TTL);
        }
    }
}
