<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Policies;

use App\Core\Company\TenantOwnershipResolver;
use App\Models\User;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;

/**
 * Authorization for the Enterprise User Management Platform (ADR-040). All abilities map to
 * the additive `iam.users.*` permission catalog. Super-admin bypass is global (Gate::before).
 *
 * TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-IMPLEMENTATION-001 — tenant boundary.
 *
 * Every ability that names a target User now requires BOTH:
 *
 *     permission granted   AND   actor's company owns the target's company
 *
 * The audit (TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-001) proved at runtime that a Company A
 * administrator was authorized against Company B users on all ten abilities, including
 * resetting a Company B Super Administrator's password.
 *
 * Ownership is delegated to App\Core\Company\TenantOwnershipResolver — the platform's single
 * server-side authority, already used by Order, Warehouse, Supplier and Product. It is not
 * reimplemented here, and cross-company access remains granted solely by an `is_system` role.
 * A null company is never read as privilege.
 *
 * `viewAny` and `create` take no target, so the boundary cannot be applied to them here; they
 * are scoped by the query/creation path instead.
 */
class UserPolicy extends BasePolicy
{
    public function __construct(
        PermissionServiceInterface $permissions,
        private readonly TenantOwnershipResolver $tenant,
    ) {
        parent::__construct($permissions);
    }

    /**
     * Permission AND tenant ownership — never one or the other.
     *
     * Order matters only for clarity, not security: both must hold. Evaluating the
     * permission first keeps the failure mode identical to the pre-boundary behaviour
     * for an actor who simply lacks the grant.
     */
    private function allow(User $user, string $permission, User $target): bool
    {
        return $this->can($user, $permission) && $this->ownsTarget($target);
    }

    /**
     * Whether the acting tenant may address this target user at all.
     *
     * Delegates wholly to the single ownership authority. That resolver reads the
     * authenticated actor, which in the standard Gate path is the same user handed to this
     * policy. When there is no authenticated actor it denies, so the boundary fails closed.
     */
    private function ownsTarget(User $target): bool
    {
        $companyId = is_string($target->company_id) ? $target->company_id : null;

        return $this->tenant->owns($companyId);
    }

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'iam.users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.view', $target);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'iam.users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.update', $target);
    }

    public function delete(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.delete', $target);
    }

    public function activate(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.activate', $target);
    }

    public function suspend(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.suspend', $target);
    }

    public function assignRole(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.assign-role', $target);
    }

    public function assignOrganization(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.assign-org', $target);
    }

    public function invite(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.invite', $target);
    }

    public function resetPassword(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.reset-password', $target);
    }

    public function manageSessions(User $user, User $target): bool
    {
        return $this->allow($user, 'iam.users.manage-sessions', $target);
    }
}
