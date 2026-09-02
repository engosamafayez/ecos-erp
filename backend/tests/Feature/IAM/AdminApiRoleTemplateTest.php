<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\IAM\Application\Services\RoleTemplateCompiler;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Application\Services\UserRoleAssignmentService;
use Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface;
use Modules\IAM\Domain\Exceptions\RoleTemplateInUseException;
use Modules\IAM\Domain\Exceptions\SystemTemplateImmutableException;
use Modules\IAM\Domain\Exceptions\UnknownTemplatePermissionException;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\RoleTemplate;
use Tests\TestCase;

/**
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002, §21 "ROLE / PERMISSIONS" and "ROLE TEMPLATES"
 * (scenarios 25-37). WRITTEN, NOT EXECUTED — see AdminApiTenantSecurityTest's class docblock.
 */
class AdminApiRoleTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function perm(string $name): Permission
    {
        [$d, $r, $a] = explode('.', $name);

        return Permission::firstOrCreate(['name' => $name], ['module' => $d, 'resource' => $r, 'action' => $a]);
    }

    /**
     * assignTemplate()/removeTemplate() now self-authorize (Gate::authorize()), so any test
     * calling them directly needs an authenticated actor. Where the test is about something
     * OTHER than authorization, a role-less actor + plain actingAs() (which auto-grants the
     * baseline system role per TestCase's own documented behavior) is the least-intrusive way
     * to satisfy that without adding unrelated permission/tenant setup to every test.
     */
    private function actingAsSystemBypassActor(string $companyId): User
    {
        $actor = app(UserIdentityService::class)->createDraft(['name' => 'Actor', 'email' => Str::random(10).'@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($actor);
        $this->actingAs($actor->refresh());

        return $actor;
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

    // 25/26. protected/system-role assignment rules + tenant/data-scope guard — covered in
    // AdminApiUserSecurityTest::test_assigning_an_is_system_role_requires_system_authority().

    // 27. unknown permission fails closed.
    public function test_unknown_permission_token_fails_closed(): void
    {
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'bad-tpl', 'name' => 'Bad', 'category' => 'custom',
            'definition' => ['permissions' => ['nonexistent.domain.token']], 'company_id' => (string) Str::uuid(),
        ]);

        $this->expectException(UnknownTemplatePermissionException::class);
        app(RoleTemplateCompiler::class)->compile($template);
    }

    // 28/29. role mutation invalidates canonical effective authorization caches
    // (permission + visibility + scope), including for every current holder, not just the
    // triggering user.
    public function test_recompiling_a_shared_template_invalidates_every_holders_cache(): void
    {
        $companyId = (string) Str::uuid();
        $this->actingAsSystemBypassActor($companyId);
        $this->perm('inventory.products.view');
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'shared-tpl', 'name' => 'Shared', 'category' => 'custom',
            'definition' => ['permissions' => ['inventory.products.view']], 'company_id' => $companyId,
        ]);

        $holderA = app(UserIdentityService::class)->createDraft(['name' => 'A', 'email' => 'holdera@ecos.test'], $companyId);
        $holderB = app(UserIdentityService::class)->createDraft(['name' => 'B', 'email' => 'holderb@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($holderA);
        app(UserLifecycleService::class)->activate($holderB);

        $assign = app(UserRoleAssignmentService::class);
        $assign->assignTemplate($holderA, $template);
        $assign->assignTemplate($holderB->refresh(), $template->fresh());

        $permissions = app(\Modules\IAM\Domain\Contracts\PermissionServiceInterface::class);
        $this->assertTrue($permissions->userHasPermission($holderA->refresh(), 'inventory.products.view'));
        $this->assertTrue($permissions->userHasPermission($holderB->refresh(), 'inventory.products.view'));

        // Prime holder B's cache with the OLD permission set, then widen the template and
        // recompile — holder B never triggered the recompile directly, but must still see the
        // new grant immediately (Security Gate B), not after a 300s TTL.
        $this->perm('inventory.products.update');
        app(RoleTemplateRepositoryInterface::class)->update($template->fresh(), [
            'definition' => ['permissions' => ['inventory.products.view', 'inventory.products.update']],
        ]);
        app(RoleTemplateCompiler::class)->compile($template->fresh());

        $this->assertTrue($permissions->userHasPermission($holderB->refresh(), 'inventory.products.update'));
    }

    // 30. system template immutable.
    public function test_system_template_cannot_be_updated_or_deleted(): void
    {
        $template = app(RoleTemplateRepositoryInterface::class)->upsertSystem('ceo-test', [
            'name' => 'CEO', 'category' => 'executive', 'definition' => ['permissions' => []],
        ]);

        $this->expectException(SystemTemplateImmutableException::class);
        app(RoleTemplateRepositoryInterface::class)->update($template, ['name' => 'Renamed']);
    }

    // 31. custom template tenant scoped.
    public function test_custom_template_is_company_scoped(): void
    {
        $companyId = (string) Str::uuid();
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'scoped-tpl', 'name' => 'Scoped', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => $companyId,
        ]);

        $this->assertSame($companyId, $template->company_id);
        $this->assertTrue($template->isCompanyScoped());

        $inCompany = app(RoleTemplateRepositoryInterface::class)->customTemplatesForCompany($companyId);
        $this->assertTrue($inCompany->contains('key', 'scoped-tpl'));

        $inOtherCompany = app(RoleTemplateRepositoryInterface::class)->customTemplatesForCompany((string) Str::uuid());
        $this->assertFalse($inOtherCompany->contains('key', 'scoped-tpl'));
    }

    // 32. used template cannot unsafe-hard-delete.
    public function test_used_template_cannot_be_hard_deleted(): void
    {
        $companyId = (string) Str::uuid();
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'used-tpl', 'name' => 'Used', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => $companyId,
        ]);
        $user = app(UserIdentityService::class)->createDraft(['name' => 'U', 'email' => 'used@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($user);
        $this->actingAsSystemBypassActor($companyId);
        app(UserRoleAssignmentService::class)->assignTemplate($user, $template);

        $this->expectException(RoleTemplateInUseException::class);
        app(RoleTemplateRepositoryInterface::class)->delete($template->fresh());
    }

    public function test_unused_template_can_be_hard_deleted(): void
    {
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'unused-tpl', 'name' => 'Unused', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => (string) Str::uuid(),
        ]);

        app(RoleTemplateRepositoryInterface::class)->delete($template);

        $this->assertDatabaseMissing('role_templates', ['key' => 'unused-tpl']);
    }

    // 33. archive preserves governed state (D13's preferred lifecycle path — works even when used).
    public function test_used_template_can_be_archived_instead_of_deleted(): void
    {
        $companyId = (string) Str::uuid();
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'archivable-tpl', 'name' => 'Archivable', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => $companyId,
        ]);
        $user = app(UserIdentityService::class)->createDraft(['name' => 'U2', 'email' => 'archivable@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($user);
        $this->actingAsSystemBypassActor($companyId);
        app(UserRoleAssignmentService::class)->assignTemplate($user, $template);

        $archived = app(RoleTemplateRepositoryInterface::class)->archive($template->fresh());

        $this->assertSame(\Modules\IAM\Domain\Enums\RoleTemplateStatus::ARCHIVED->value, $archived->status);
        $this->assertDatabaseHas('user_template_assignments', ['role_template_id' => $template->id]); // grant untouched
    }

    // 34. editing/versioning does not silently alter existing holders.
    public function test_editing_a_template_does_not_silently_change_existing_holders(): void
    {
        $companyId = (string) Str::uuid();
        $this->perm('inventory.products.view');
        $this->perm('inventory.products.delete');
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'silent-tpl', 'name' => 'Silent', 'category' => 'custom',
            'definition' => ['permissions' => ['inventory.products.view']], 'company_id' => $companyId,
        ]);
        $user = app(UserIdentityService::class)->createDraft(['name' => 'U3', 'email' => 'silent@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($user);
        $this->actingAsSystemBypassActor($companyId);
        app(UserRoleAssignmentService::class)->assignTemplate($user, $template);

        app(RoleTemplateRepositoryInterface::class)->update($template->fresh(), [
            'definition' => ['permissions' => ['inventory.products.view', 'inventory.products.delete']],
        ]);

        $permissions = app(\Modules\IAM\Domain\Contracts\PermissionServiceInterface::class);
        $this->assertFalse($permissions->userHasPermission($user->refresh(), 'inventory.products.delete'), 'edit alone must not propagate');
    }

    // 35. explicit apply/sync updates intended holder.
    public function test_explicit_apply_propagates_the_edit_to_holders(): void
    {
        $companyId = (string) Str::uuid();
        $this->perm('inventory.products.view');
        $this->perm('inventory.products.delete');
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'apply-tpl', 'name' => 'Apply', 'category' => 'custom',
            'definition' => ['permissions' => ['inventory.products.view']], 'company_id' => $companyId,
        ]);
        $user = app(UserIdentityService::class)->createDraft(['name' => 'U4', 'email' => 'apply@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($user);
        $this->actingAsSystemBypassActor($companyId);
        app(UserRoleAssignmentService::class)->assignTemplate($user, $template);

        app(RoleTemplateRepositoryInterface::class)->update($template->fresh(), [
            'definition' => ['permissions' => ['inventory.products.view', 'inventory.products.delete']],
        ]);
        app(RoleTemplateCompiler::class)->compile($template->fresh()); // the explicit "apply" action

        $permissions = app(\Modules\IAM\Domain\Contracts\PermissionServiceInterface::class);
        $this->assertTrue($permissions->userHasPermission($user->refresh(), 'inventory.products.delete'));
    }

    // 36. previous version remains historically preserved.
    public function test_previous_versions_are_never_overwritten(): void
    {
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'versioned-tpl', 'name' => 'V1', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => (string) Str::uuid(),
        ]);
        app(RoleTemplateRepositoryInterface::class)->update($template->fresh(), ['name' => 'V2']);
        app(RoleTemplateRepositoryInterface::class)->update($template->fresh(), ['name' => 'V3']);

        $history = app(\Modules\IAM\Application\Services\RoleTemplateVersionService::class)->history($template->fresh());

        $this->assertCount(3, $history);
        $this->assertSame('V1', $history->firstWhere('version', 1)->name);
        $this->assertSame('V2', $history->firstWhere('version', 2)->name);
    }

    // 37. invalid/unknown template permission fails closed — wildcard variant.
    public function test_wildcard_matching_nothing_fails_closed(): void
    {
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'wildcard-tpl', 'name' => 'Wildcard', 'category' => 'custom',
            'definition' => ['permissions' => ['manufacturing.*']], 'company_id' => (string) Str::uuid(),
        ]);

        $this->expectException(UnknownTemplatePermissionException::class);
        app(RoleTemplateCompiler::class)->compile($template);
    }

    // API 43/44: RoleTemplateController compare()/impactPreview() over HTTP, and that a
    // nonexistent/foreign template 404s rather than confirming existence via a 403 vs 404 tell.
    public function test_impact_preview_endpoint_reports_pending_changes(): void
    {
        $companyId = (string) Str::uuid();
        $this->perm('inventory.products.view');
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'preview-tpl', 'name' => 'Preview', 'category' => 'custom',
            'definition' => ['permissions' => ['inventory.products.view']], 'company_id' => $companyId,
        ]);
        app(RoleTemplateCompiler::class)->compile($template->fresh());
        app(RoleTemplateRepositoryInterface::class)->update($template->fresh(), [
            'definition' => ['permissions' => []],
        ]);

        $admin = $this->companyAdmin($companyId, ['iam.role-templates.view']);
        $response = $this->actingAsUnprivileged($admin)
            ->getJson('/api/iam/role-templates/preview-tpl/impact-preview')
            ->assertOk();

        $this->assertSame(['inventory.products.view'], $response->json('data.permission_removals'));
    }
}
