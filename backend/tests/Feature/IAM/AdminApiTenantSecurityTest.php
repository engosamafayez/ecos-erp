<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Tests\TestCase;

/**
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002, §21 "TENANT SECURITY" (scenarios 1-9).
 *
 * WRITTEN on the second device; NOT EXECUTED (no PHP/DB toolchain available here — §2/§23).
 * Verification is deferred to the first/canonical ECOS device per this task's own policy.
 *
 * TestCase::actingAs() grants a baseline is_system role to any role-less user (see
 * Tests\TestCase's own docblock) — that role bypasses TenantOwnershipResolver entirely via
 * Gate::before()/isUnrestricted(). Every test here that asserts a tenant DENIAL therefore uses
 * actingAsUnprivileged() plus a hand-built, non-system, company-scoped role, so the actor is a
 * genuinely bounded company admin — not the accidental super-admin actingAs() would otherwise
 * hand every test.
 */
class AdminApiTenantSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function perm(string $name): Permission
    {
        [$d, $r, $a] = explode('.', $name);

        return Permission::firstOrCreate(['name' => $name], ['module' => $d, 'resource' => $r, 'action' => $a]);
    }

    /** A non-system, company-scoped admin holding exactly the given permissions. */
    private function companyAdmin(string $companyId, array $permissionNames): User
    {
        $role = Role::create(['name' => 'Company Admin '.Str::random(6), 'slug' => 'ca-'.Str::random(8), 'is_system' => false]);
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

    // 1. company ownership is server-derived on create.
    public function test_company_id_is_server_derived_on_create(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $admin = $this->companyAdmin($companyA, ['iam.users.view', 'iam.users.create']);

        $this->actingAsUnprivileged($admin)
            ->postJson('/api/iam/users', [
                'name' => 'New Hire', 'email' => 'newhire@ecos.test', 'company_id' => $companyB,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'newhire@ecos.test', 'company_id' => $companyA]);
        $this->assertDatabaseMissing('users', ['email' => 'newhire@ecos.test', 'company_id' => $companyB]);
    }

    // 2. company_id cannot be changed by normal update.
    public function test_company_id_is_not_writable_on_update(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $admin = $this->companyAdmin($companyA, ['iam.users.view', 'iam.users.update']);
        $target = $this->targetUser($companyA, 'mover@ecos.test');

        $this->actingAsUnprivileged($admin)
            ->patchJson("/api/iam/users/{$target->id}", ['name' => 'Renamed', 'company_id' => $companyB])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'company_id' => $companyA, 'name' => 'Renamed']);
    }

    // 3. foreign user read denied/hidden.
    public function test_foreign_user_read_is_denied(): void
    {
        $admin = $this->companyAdmin((string) Str::uuid(), ['iam.users.view']);
        $foreign = $this->targetUser((string) Str::uuid(), 'foreign@ecos.test');

        $this->actingAsUnprivileged($admin)
            ->getJson("/api/iam/users/{$foreign->id}")
            ->assertForbidden();
    }

    // 4. foreign user mutation denied.
    public function test_foreign_user_mutation_is_denied(): void
    {
        $admin = $this->companyAdmin((string) Str::uuid(), ['iam.users.update']);
        $foreign = $this->targetUser((string) Str::uuid(), 'foreign2@ecos.test');

        $this->actingAsUnprivileged($admin)
            ->patchJson("/api/iam/users/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    // 5. foreign role access denied — via the template that compiled it, since roles are read-only (§7).
    public function test_foreign_custom_template_is_denied(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $admin = $this->companyAdmin($companyA, ['iam.role-templates.view']);
        $foreignTemplate = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'foreign-clerk', 'name' => 'Foreign Clerk', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => $companyB,
        ]);

        $this->actingAsUnprivileged($admin)
            ->getJson("/api/iam/role-templates/{$foreignTemplate->key}")
            ->assertForbidden();
    }

    // 6. foreign custom template access denied (edit path).
    public function test_foreign_custom_template_update_is_denied(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $admin = $this->companyAdmin($companyA, ['iam.role-templates.update']);
        $foreignTemplate = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'foreign-clerk-2', 'name' => 'Foreign Clerk 2', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => $companyB,
        ]);

        $this->actingAsUnprivileged($admin)
            ->patchJson("/api/iam/role-templates/{$foreignTemplate->key}", ['name' => 'Renamed'])
            ->assertForbidden();
    }

    // 7. cross-company role assignment denied.
    public function test_cross_company_template_assignment_is_denied(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $admin = $this->companyAdmin($companyA, ['iam.users.assign-role']);
        $foreign = $this->targetUser($companyB, 'foreign3@ecos.test');
        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'assignable-role', 'name' => 'Assignable', 'category' => 'custom',
            'definition' => ['permissions' => []], 'company_id' => $companyA,
        ]);

        $this->actingAsUnprivileged($admin)
            ->putJson("/api/iam/users/{$foreign->id}/templates/{$template->key}", [])
            ->assertForbidden();
    }

    // 8. foreign password reset denied.
    public function test_foreign_password_reset_is_denied(): void
    {
        $admin = $this->companyAdmin((string) Str::uuid(), ['iam.users.reset-password']);
        $foreign = $this->targetUser((string) Str::uuid(), 'foreign4@ecos.test');

        $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$foreign->id}/reset-password", [
                'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
            ])
            ->assertForbidden();
    }

    // 9. foreign session action denied.
    public function test_foreign_session_action_is_denied(): void
    {
        $admin = $this->companyAdmin((string) Str::uuid(), ['iam.users.manage-sessions']);
        $foreign = $this->targetUser((string) Str::uuid(), 'foreign5@ecos.test');

        $this->actingAsUnprivileged($admin)
            ->getJson("/api/iam/users/{$foreign->id}/sessions")
            ->assertForbidden();

        $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$foreign->id}/sessions/force-logout")
            ->assertForbidden();
    }

    // Query-filter bypass (STOP 3): the list endpoint must never return a foreign company's users.
    public function test_list_users_never_returns_foreign_company_rows(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $admin = $this->companyAdmin($companyA, ['iam.users.view']);
        $this->targetUser($companyA, 'own@ecos.test');
        $this->targetUser($companyB, 'foreign6@ecos.test');

        $response = $this->actingAsUnprivileged($admin)->getJson('/api/iam/users')->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->all();

        $this->assertContains('own@ecos.test', $emails);
        $this->assertNotContains('foreign6@ecos.test', $emails);
    }
}
