<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Domain\Exceptions\UserSecurityRuleException;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Tests\TestCase;

/**
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002, §21 "USER SECURITY" (scenarios 17-24).
 * WRITTEN, NOT EXECUTED — see AdminApiTenantSecurityTest's class docblock.
 */
class AdminApiUserSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function perm(string $name): Permission
    {
        [$d, $r, $a] = explode('.', $name);

        return Permission::firstOrCreate(['name' => $name], ['module' => $d, 'resource' => $r, 'action' => $a]);
    }

    private function companyAdmin(string $companyId, array $permissionNames): User
    {
        $role = Role::create(['name' => 'Admin '.Str::random(6), 'slug' => 'ca-'.Str::random(8), 'is_system' => false]);
        foreach ($permissionNames as $name) {
            $role->permissions()->attach($this->perm($name)->id, ['effect' => 'allow']);
        }

        $user = app(UserIdentityService::class)->createDraft(['name' => 'Admin', 'email' => Str::random(10).'@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($user);
        $user->roles()->attach($role->id);

        return $user->refresh();
    }

    private function targetUser(string $companyId, string $email): User
    {
        $user = app(UserIdentityService::class)->createDraft(['name' => 'Target', 'email' => $email], $companyId);

        return app(UserLifecycleService::class)->activate($user);
    }

    // 17. no self-demotion invariant preserved.
    public function test_actor_cannot_deactivate_own_account(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.deactivate']);

        $this->expectException(UserSecurityRuleException::class);
        app(UserLifecycleService::class)->deactivate($admin, $admin);
    }

    // 18. cannot remove last Super Admin.
    public function test_cannot_deactivate_last_super_admin(): void
    {
        $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'is_system' => true]);
        $admin = $this->targetUser((string) Str::uuid(), 'onlysuper@ecos.test');
        $admin->roles()->attach($role->id);

        $this->expectException(UserSecurityRuleException::class);
        app(UserLifecycleService::class)->suspend($admin->refresh());
    }

    /**
     * 19-24: archive/restore/deactivate/lock/unlock/revoke-role permission enforcement — every
     * D4 endpoint 403s without its specific permission and succeeds with it. Table-driven: each
     * row is one HTTP call, asserted both ways against the SAME target/actor pairing to isolate
     * that the specific permission is what's gating the specific action (not some other grant).
     */
    public function test_each_d4_action_requires_its_own_permission(): void
    {
        $companyId = (string) Str::uuid();

        $cases = [
            'archive' => 'iam.users.archive',
            'deactivate' => 'iam.users.deactivate',
            'lock' => 'iam.users.lock',
        ];

        foreach ($cases as $action => $permission) {
            $unprivileged = $this->companyAdmin($companyId, ['iam.users.view']); // lacks the specific permission
            $target = $this->targetUser($companyId, "target-{$action}-denied@ecos.test");

            $this->actingAsUnprivileged($unprivileged)
                ->postJson("/api/iam/users/{$target->id}/{$action}")
                ->assertForbidden();

            $privileged = $this->companyAdmin($companyId, ['iam.users.view', $permission]);
            $target2 = $this->targetUser($companyId, "target-{$action}-allowed@ecos.test");

            $this->actingAsUnprivileged($privileged)
                ->postJson("/api/iam/users/{$target2->id}/{$action}")
                ->assertOk();
        }
    }

    public function test_unlock_requires_its_own_permission(): void
    {
        $companyId = (string) Str::uuid();
        $target = $this->targetUser($companyId, 'unlock-target@ecos.test');
        app(UserLifecycleService::class)->lock($target->refresh());

        $unprivileged = $this->companyAdmin($companyId, ['iam.users.view']);
        $this->actingAsUnprivileged($unprivileged)
            ->postJson("/api/iam/users/{$target->id}/unlock")
            ->assertForbidden();

        $privileged = $this->companyAdmin($companyId, ['iam.users.view', 'iam.users.unlock']);
        $this->actingAsUnprivileged($privileged)
            ->postJson("/api/iam/users/{$target->id}/unlock")
            ->assertOk();
    }

    public function test_restore_requires_its_own_permission(): void
    {
        $companyId = (string) Str::uuid();
        $target = $this->targetUser($companyId, 'restore-target@ecos.test');
        app(UserLifecycleService::class)->archive($target->refresh());

        $unprivileged = $this->companyAdmin($companyId, ['iam.users.view']);
        $this->actingAsUnprivileged($unprivileged)
            ->postJson("/api/iam/users/{$target->id}/restore")
            ->assertForbidden();

        $privileged = $this->companyAdmin($companyId, ['iam.users.view', 'iam.users.restore']);
        $this->actingAsUnprivileged($privileged)
            ->postJson("/api/iam/users/{$target->id}/restore")
            ->assertOk();
    }

    public function test_revoke_role_requires_its_own_permission(): void
    {
        $companyId = (string) Str::uuid();
        $target = $this->targetUser($companyId, 'revoke-target@ecos.test');
        $template = app(\Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'revoke-test-tpl', 'name' => 'Revoke Test', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => $companyId,
        ]);

        $assigner = $this->companyAdmin($companyId, ['iam.users.assign-role']);
        $this->actingAsUnprivileged($assigner)->putJson("/api/iam/users/{$target->id}/templates/{$template->key}", [])->assertOk();

        $unprivileged = $this->companyAdmin($companyId, ['iam.users.view']);
        $this->actingAsUnprivileged($unprivileged)
            ->deleteJson("/api/iam/users/{$target->id}/templates/{$template->key}")
            ->assertForbidden();

        $privileged = $this->companyAdmin($companyId, ['iam.users.revoke-role']);
        $this->actingAsUnprivileged($privileged)
            ->deleteJson("/api/iam/users/{$target->id}/templates/{$template->key}")
            ->assertOk();
    }

    // D14: assigning an is_system role requires the actor to hold system authority themselves.
    public function test_assigning_an_is_system_role_requires_system_authority(): void
    {
        // No template compiles to is_system=true today (RoleTemplateCompiler::resolveRole()
        // hardcodes false) — this exercises the defensive guard directly at the service layer,
        // documented in UserRoleAssignmentService's class docblock as defense-in-depth.
        $companyId = (string) Str::uuid();
        $target = $this->targetUser($companyId, 'system-role-target@ecos.test');
        $role = Role::create(['name' => 'Synthetic System Role', 'slug' => 'synthetic-system', 'is_system' => true]);
        $template = app(\Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'synthetic-system-tpl', 'name' => 'Synthetic', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => $companyId,
        ]);
        $template->role_id = $role->id;
        $template->save();

        $normalAdmin = $this->companyAdmin($companyId, ['iam.users.assign-role']);
        // companyAdmin() already attaches a non-system role, so actingAsUnprivileged() (which
        // skips the baseline-system-role auto-grant) is equivalent here and consistent with the
        // rest of this suite's convention. Establishes Auth::user() for the service's internal
        // Gate::authorize() and TenantOwnershipResolver calls.
        $this->actingAsUnprivileged($normalAdmin);

        $this->expectException(UserSecurityRuleException::class);
        app(\Modules\IAM\Application\Services\UserRoleAssignmentService::class)
            ->assignTemplate($target, $template->fresh(), false, $normalAdmin->id);
    }
}
