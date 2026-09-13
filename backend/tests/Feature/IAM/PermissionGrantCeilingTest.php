<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\IAM\Application\Services\RoleAuthoringService;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface;
use Modules\IAM\Domain\Exceptions\PermissionGrantCeilingExceededException;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Tests\TestCase;

/**
 * CORE-02 Task 1 — Permission Grant Ceiling. An actor authoring a Role or Role Template must
 * never be able to grant a permission they do not themselves hold. RoleTemplateRepository is
 * the single enforcement point (createCustom/update/clone) — RoleAuthoringService funnels
 * through it, so these tests exercise both entry points against the same one implementation.
 */
class PermissionGrantCeilingTest extends TestCase
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

    private function systemActor(string $companyId): User
    {
        $actor = app(UserIdentityService::class)->createDraft(['name' => 'Sys', 'email' => Str::random(10).'@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($actor);
        $this->grantSystemRole($actor);

        return $actor->refresh();
    }

    public function test_actor_cannot_create_a_role_template_granting_a_permission_they_do_not_hold(): void
    {
        $companyId = (string) Str::uuid();
        $this->perm('inventory.products.view');
        $this->perm('inventory.products.delete'); // exists in the catalog, but NOT granted to this actor
        $actor = $this->companyAdmin($companyId, ['iam.role-templates.create', 'inventory.products.view']);
        $this->actingAsUnprivileged($actor);

        $this->expectException(PermissionGrantCeilingExceededException::class);
        app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'escalating-tpl', 'name' => 'Escalating', 'category' => 'custom',
            'definition' => ['permissions' => ['inventory.products.view', 'inventory.products.delete']],
            'company_id' => $companyId,
        ]);
    }

    public function test_actor_can_create_a_role_template_within_their_own_ceiling(): void
    {
        $companyId = (string) Str::uuid();
        $this->perm('inventory.products.view');
        $actor = $this->companyAdmin($companyId, ['iam.role-templates.create', 'inventory.products.view']);
        $this->actingAsUnprivileged($actor);

        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'within-ceiling-tpl', 'name' => 'Within Ceiling', 'category' => 'custom',
            'definition' => ['permissions' => ['inventory.products.view']],
            'company_id' => $companyId,
        ]);

        $this->assertSame('within-ceiling-tpl', $template->key);
    }

    public function test_updating_a_templates_permissions_beyond_the_actors_ceiling_is_refused(): void
    {
        $companyId = (string) Str::uuid();
        $this->perm('inventory.products.view');
        $this->perm('inventory.products.delete');
        $actor = $this->companyAdmin($companyId, ['iam.role-templates.update', 'inventory.products.view']);

        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'update-ceiling-tpl', 'name' => 'Update Ceiling', 'category' => 'custom',
            'definition' => ['permissions' => ['inventory.products.view']],
            'company_id' => $companyId,
        ]);

        $this->actingAsUnprivileged($actor);

        $this->expectException(PermissionGrantCeilingExceededException::class);
        app(RoleTemplateRepositoryInterface::class)->update($template->fresh(), [
            'definition' => ['permissions' => ['inventory.products.view', 'inventory.products.delete']],
        ]);
    }

    public function test_re_saving_an_unchanged_definition_never_trips_the_ceiling(): void
    {
        // A template can carry a permission the CURRENT editor doesn't hold (granted
        // historically by someone else). Merely re-saving it (net-added = empty) must not be
        // blocked, or an ordinary metadata edit would break for any pre-existing template.
        $companyId = (string) Str::uuid();
        $this->perm('inventory.products.view');
        $this->perm('inventory.products.delete');

        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'preexisting-tpl', 'name' => 'Preexisting', 'category' => 'custom',
            'definition' => ['permissions' => ['inventory.products.view', 'inventory.products.delete']],
            'company_id' => $companyId,
        ]);

        // Actor holds only ONE of the two permissions already on the template.
        $actor = $this->companyAdmin($companyId, ['iam.role-templates.update', 'inventory.products.view']);
        $this->actingAsUnprivileged($actor);

        $updated = app(RoleTemplateRepositoryInterface::class)->update($template->fresh(), [
            'definition' => ['permissions' => ['inventory.products.view', 'inventory.products.delete']],
            'name' => 'Preexisting Renamed',
        ]);

        $this->assertSame('Preexisting Renamed', $updated->name);
    }

    public function test_cloning_a_system_template_cannot_bypass_the_ceiling(): void
    {
        $this->perm('finance.ledger.view');
        $this->perm('finance.ledger.post'); // sensitive; the cloner does not hold this

        $system = app(RoleTemplateRepositoryInterface::class)->upsertSystem('finance-controller-test', [
            'name' => 'Finance Controller', 'category' => 'executive',
            'definition' => ['permissions' => ['finance.ledger.view', 'finance.ledger.post']],
        ]);

        $companyId = (string) Str::uuid();
        $actor = $this->companyAdmin($companyId, ['iam.role-templates.create', 'finance.ledger.view']);
        $this->actingAsUnprivileged($actor);

        $this->expectException(PermissionGrantCeilingExceededException::class);
        app(RoleTemplateRepositoryInterface::class)->clone($system, 'finance-controller-clone', $companyId);
    }

    public function test_role_authoring_service_create_is_subject_to_the_same_ceiling(): void
    {
        // RoleAuthoringService::create() funnels through RoleTemplateRepository::createCustom()
        // — one enforcement point, not a second parallel implementation.
        $companyId = (string) Str::uuid();
        $this->perm('inventory.products.view');
        $this->perm('inventory.products.delete');
        $actor = $this->companyAdmin($companyId, ['iam.roles.create', 'inventory.products.view']);
        $this->actingAsUnprivileged($actor);

        $this->expectException(PermissionGrantCeilingExceededException::class);
        app(RoleAuthoringService::class)->create([
            'name' => 'Escalated Role',
            'permissions' => ['inventory.products.view', 'inventory.products.delete'],
        ], $companyId, $actor->getKey());
    }

    public function test_role_authoring_service_clone_role_is_subject_to_the_same_ceiling(): void
    {
        $companyId = (string) Str::uuid();
        $this->perm('crm.leads.view');
        $this->perm('crm.leads.delete');

        $source = app(RoleAuthoringService::class)->create([
            'name' => 'Source Role',
            'permissions' => ['crm.leads.view', 'crm.leads.delete'],
        ], $companyId, null);

        $actor = $this->companyAdmin($companyId, ['iam.roles.create', 'crm.leads.view']);
        $this->actingAsUnprivileged($actor);

        $this->expectException(PermissionGrantCeilingExceededException::class);
        app(RoleAuthoringService::class)->cloneRole($source, 'Cloned Role', $companyId, $actor->getKey());
    }

    public function test_adopting_a_legacy_role_into_a_template_is_never_blocked_by_the_ceiling(): void
    {
        // adoptIntoTemplate() mirrors a role's OWN current grants — net-added is always empty
        // by construction, so it must never trip the ceiling regardless of who performs it.
        $companyId = (string) Str::uuid();
        $this->perm('legacy.module.action');
        $legacyRole = Role::create(['name' => 'Legacy', 'slug' => 'legacy-role-'.Str::random(6), 'is_system' => false]);
        $legacyRole->permissions()->attach($this->perm('legacy.module.action')->id, ['effect' => 'allow']);

        // Actor holds NONE of the legacy role's permissions — adoption must still succeed.
        $actor = $this->companyAdmin($companyId, ['iam.roles.update']);
        $this->actingAsUnprivileged($actor);

        $template = app(RoleAuthoringService::class)->adoptIntoTemplate($legacyRole, $companyId, $actor->getKey());

        $this->assertSame(['legacy.module.action'], $template->definition['permissions'] ?? []);
    }

    public function test_system_authority_actor_bypasses_the_ceiling(): void
    {
        $companyId = (string) Str::uuid();
        $this->perm('finance.ledger.post');
        $this->systemActor($companyId);

        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'system-actor-tpl', 'name' => 'System Actor', 'category' => 'custom',
            'definition' => ['permissions' => ['finance.ledger.post']],
            'company_id' => $companyId,
        ]);

        $this->assertSame(['finance.ledger.post'], $template->definition['permissions']);
    }

    public function test_ceiling_report_lists_every_unauthorized_permission_not_just_the_first(): void
    {
        $companyId = (string) Str::uuid();
        $this->perm('sales.orders.view');
        $this->perm('sales.orders.delete');
        $this->perm('sales.orders.export');
        $actor = $this->companyAdmin($companyId, ['iam.role-templates.create', 'sales.orders.view']);
        $this->actingAsUnprivileged($actor);

        try {
            app(RoleTemplateRepositoryInterface::class)->createCustom([
                'key' => 'multi-unauthorized-tpl', 'name' => 'Multi', 'category' => 'custom',
                'definition' => ['permissions' => ['sales.orders.view', 'sales.orders.delete', 'sales.orders.export']],
                'company_id' => $companyId,
            ]);
            $this->fail('Expected PermissionGrantCeilingExceededException.');
        } catch (PermissionGrantCeilingExceededException $e) {
            sort($e->unauthorized);
            $this->assertSame(['sales.orders.delete', 'sales.orders.export'], $e->unauthorized);
        }
    }
}
