<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Core\Company\TenantOwnershipResolver;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Domain\Exceptions\PermissionGrantCeilingExceededException;

/**
 * CORE-02 Task 1: the single authority for the permission grant ceiling —
 * "an actor may not author a Role or Role Template into granting a permission they do not
 * themselves hold." Called from RoleTemplateRepository::createCustom()/update()/clone(), the
 * one place every Role/Role Template authoring path (RoleAuthoringService AND the direct
 * RoleTemplateController HTTP actions) ultimately writes a definition — so this is the ONLY
 * enforcement point, not a second parallel ceiling implementation per call site.
 *
 * Only NET NEW permissions (not already in the template's current definition) are checked:
 * re-saving an unchanged definition, or RoleAuthoringService::adoptIntoTemplate()'s
 * grant-preserving mirror of a legacy role's own existing grants, therefore never trips this
 * check — nothing new is being granted in either case.
 *
 * Collaborators are resolved lazily (same reasoning as TenantOwnershipResolver): this keeps
 * construction infallible for contexts with no authenticated actor (console, seeders).
 */
final class PermissionGrantCeiling
{
    private function tenant(): TenantOwnershipResolver
    {
        return app(TenantOwnershipResolver::class);
    }

    private function permissions(): PermissionServiceInterface
    {
        return app(PermissionServiceInterface::class);
    }

    /**
     * @param  list<string>  $newPermissionNames  the definition's full requested permission set
     * @param  list<string>  $currentPermissionNames  the template's CURRENT permission set (empty for a brand-new template/clone)
     *
     * @throws PermissionGrantCeilingExceededException
     */
    public function assertWithinCeiling(array $newPermissionNames, array $currentPermissionNames = []): void
    {
        $added = array_values(array_diff($newPermissionNames, $currentPermissionNames));

        if ($added === []) {
            return;
        }

        // No authenticated actor (console/queue/seeder context) — nothing to gate against.
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return;
        }

        // System/Super Admin exception — the one canonical bypass (Gate::before's own flag).
        if ($this->tenant()->isUnrestricted()) {
            return;
        }

        $held = $this->permissions()->getUserPermissions($actor);
        $unauthorized = array_values(array_diff($added, $held));

        if ($unauthorized !== []) {
            throw PermissionGrantCeilingExceededException::forPermissions($unauthorized);
        }
    }
}
