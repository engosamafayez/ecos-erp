<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Core\Company\TenantOwnershipResolver;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Domain\Contracts\RoleCompositionInterface;
use Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface;
use Modules\IAM\Domain\Contracts\ScopeResolverInterface;
use Modules\IAM\Domain\Contracts\VisibilityResolverInterface;
use Modules\IAM\Domain\Exceptions\UserSecurityRuleException;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\RoleTemplate;
use Modules\IAM\Domain\Models\UserTemplateAssignment;
use Modules\IAM\Domain\ValueObjects\EffectiveRoleProfile;

/**
 * Assigns Role Templates to users — the ONLY path to granting a user access (ADR-040,
 * Decision 2). There is no direct permission assignment. Each assignment compiles the
 * template to a runtime role and attaches it, so the Authorization Platform is unchanged.
 *
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002: assignTemplate()/removeTemplate() now self-authorize
 * (permission + tenant ownership, matching UserPasswordService::adminReset()'s pattern) rather
 * than trusting every future HTTP caller to remember Gate::authorize(). A defensive same-company
 * is_system guard is also enforced (D14) — see assertSystemRoleAuthority() below.
 */
class UserRoleAssignmentService
{
    public function __construct(
        private readonly RoleTemplateRepositoryInterface $templates,
        private readonly RoleTemplateCompiler $compiler,
        private readonly RoleCompositionInterface $composition,
        private readonly PermissionServiceInterface $permissions,
        private readonly UserAuditService $audit,
        private readonly UserSecurityRules $security,
        private readonly TenantOwnershipResolver $tenant,
        private readonly VisibilityResolverInterface $visibility,
        private readonly ScopeResolverInterface $scope,
    ) {}

    /** Security Gate B: invalidate all three effective-authorization caches together, always. */
    private function invalidateEffectiveAccess(int $userId): void
    {
        $this->permissions->invalidateUserCache($userId);
        $this->visibility->invalidateUserCache($userId);
        $this->scope->invalidateUserCache($userId);
    }

    public function assignTemplate(User $user, RoleTemplate|string $template, bool $primary = false, ?int $actorId = null): UserTemplateAssignment
    {
        Gate::authorize('assignRole', $user);

        $template = $this->resolve($template);
        $role = $this->compiler->compile($template);

        // D14 (CTO-ratified): is_system must never be inferred as a tenant/normal-admin bypass.
        // No template compiled by RoleTemplateCompiler::resolveRole() is ever is_system=true today
        // (it hardcodes false) — this guard is therefore defense-in-depth against that invariant
        // ever changing, not a currently-reachable path. See the Engineering Report for the
        // source-verified reasoning.
        $this->assertSystemRoleAuthority($role->is_system);

        $user->assignRole($role);

        $assignment = UserTemplateAssignment::firstOrNew([
            'user_id' => $user->getKey(),
            'role_template_id' => $template->id,
        ]);
        $assignment->is_primary = $primary;
        $assignment->assigned_by = $actorId;
        $assignment->assigned_at = now();
        $assignment->save();

        if ($primary) {
            $this->markPrimary($user, $template->id);
        }

        $this->invalidateEffectiveAccess((int) $user->getKey());
        $this->audit->log('template_assigned', $user, [], ['template' => $template->key, 'primary' => $primary]);

        return $assignment;
    }

    public function removeTemplate(User $user, RoleTemplate|string $template): void
    {
        Gate::authorize('revokeRole', $user);

        $template = $this->resolve($template);
        $role = $template->role_id !== null ? Role::find($template->role_id) : null;

        if ($role !== null) {
            $this->assertSystemRoleAuthority($role->is_system);
            // Defensive (ADR-040, Decision 5): no template-compiled role is ever the literal
            // super-admin role today (see assignTemplate()'s note), but this preserves the
            // invariant unconditionally rather than assuming that can never change.
            /** @var User|null $actor */
            $actor = Auth::user();
            $this->security->assertNotRemovingOwnSuperAdmin($actor, $user, (string) $role->slug);
            $user->roles()->detach($template->role_id);
        }

        UserTemplateAssignment::where('user_id', $user->getKey())
            ->where('role_template_id', $template->id)
            ->delete();

        $this->invalidateEffectiveAccess((int) $user->getKey());
        $this->audit->log('template_removed', $user, ['template' => $template->key], []);
    }

    /** D14: assigning/revoking an is_system role requires the actor to hold system authority themselves. */
    private function assertSystemRoleAuthority(bool $roleIsSystem): void
    {
        if ($roleIsSystem && ! $this->tenant->isUnrestricted()) {
            throw UserSecurityRuleException::cannotAssignSystemRoleWithoutSystemAuthority();
        }
    }

    public function setPrimary(User $user, RoleTemplate|string $template): void
    {
        $template = $this->resolve($template);
        $this->markPrimary($user, $template->id);
        $this->audit->log('primary_template_set', $user, [], ['template' => $template->key]);
    }

    /**
     * The user's fully-resolved effective profile — composed from their assigned templates,
     * primary first. Drives effective permissions/visibility/scope/policies.
     */
    public function effectiveProfile(User $user): EffectiveRoleProfile
    {
        $assignments = UserTemplateAssignment::with('template')
            ->where('user_id', $user->getKey())
            ->orderByDesc('is_primary')
            ->get();

        $templates = $assignments
            ->map(fn (UserTemplateAssignment $a): ?RoleTemplate => $a->template)
            ->filter()
            ->values()
            ->all();

        return $this->composition->compose($templates);
    }

    private function markPrimary(User $user, string $templateId): void
    {
        UserTemplateAssignment::where('user_id', $user->getKey())->update(['is_primary' => false]);
        UserTemplateAssignment::where('user_id', $user->getKey())
            ->where('role_template_id', $templateId)
            ->update(['is_primary' => true]);
    }

    private function resolve(RoleTemplate|string $template): RoleTemplate
    {
        if ($template instanceof RoleTemplate) {
            return $template;
        }

        $model = $this->templates->findByKey($template);
        if ($model === null) {
            throw new InvalidArgumentException("Unknown role template '{$template}'.");
        }

        return $model;
    }
}
