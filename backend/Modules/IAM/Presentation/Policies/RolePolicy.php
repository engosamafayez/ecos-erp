<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Policies;

use App\Core\Company\TenantOwnershipResolver;
use App\Models\User;
use Modules\IAM\Domain\Catalog\BusinessRoleCatalog;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Domain\Models\Role;

/**
 * Authorization for the Role lifecycle API
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11).
 *
 * A Role carries no `company_id` of its own — the tenant boundary lives on the Role
 * Template that compiled it, which is exactly the reasoning `RolePolicy`'s read-only
 * predecessor recorded and which `RoleTemplatePolicy` already implements. So this policy
 * resolves ownership THROUGH the backing template, using the same
 * `TenantOwnershipResolver` both other IAM policies use rather than reimplementing a
 * tenant check.
 *
 * Two roles of role are treated differently on purpose:
 *   • is_system, or a member of BusinessRoleCatalog::SYSTEM_PROTECTED_ROLES → never
 *     writable by anyone through this API. The refusal is a domain invariant, not a
 *     permission question, so `RoleAuthoringService` re-asserts it independently — a
 *     policy can be bypassed by a future caller that forgets Gate::authorize(); the
 *     service cannot.
 *   • a legacy role with NO backing template → treated as company-owned, because it was
 *     seeded into this installation and an actor with system authority (or the owning
 *     company's admin) is the only actor who ever reaches it.
 */
class RolePolicy extends BasePolicy
{
    public function __construct(
        PermissionServiceInterface $permissions,
        private readonly TenantOwnershipResolver $tenant,
    ) {
        parent::__construct($permissions);
    }

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'iam.roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->can($user, 'iam.roles.view') && $this->ownsRole($role);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'iam.roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->can($user, 'iam.roles.update')
            && $this->ownsRole($role)
            && ! $this->isProtected($role);
    }

    /** Cloning READS the source and writes a new role — create authority, source must be readable. */
    public function cloneFrom(User $user, Role $role): bool
    {
        return $this->can($user, 'iam.roles.create')
            && $this->can($user, 'iam.roles.view')
            && $this->ownsRole($role);
    }

    /** Archive is the §11/§12 safe lifecycle path, so it rides on update authority. */
    public function archive(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    public function restore(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->can($user, 'iam.roles.delete')
            && $this->ownsRole($role)
            && ! $this->isProtected($role);
    }

    private function isProtected(Role $role): bool
    {
        return $role->is_system
            || in_array((string) $role->slug, BusinessRoleCatalog::SYSTEM_PROTECTED_ROLES, true);
    }

    private function ownsRole(Role $role): bool
    {
        $template = $role->roleTemplate;

        // System templates are global (D11) — ADR-039's portable official job profiles.
        if ($template !== null && $template->is_system) {
            return true;
        }

        $companyId = $template !== null && is_string($template->company_id)
            ? $template->company_id
            : null;

        // A legacy role has no template and therefore no company of its own; `owns(null)`
        // resolves through the same TenantOwnershipResolver contract every other IAM
        // resource uses, so an unrestricted actor passes and a company-bound actor is
        // judged by that resolver's own rule rather than a second one invented here.
        return $this->tenant->owns($companyId);
    }
}
