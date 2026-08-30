<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-IMPLEMENTATION-001 — security certification.
 *
 * Authored as a recording probe by TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-001, which proved
 * a Company A administrator was authorized against Company B users on all ten `iam.users.*`
 * abilities — including resetting a Company B Super Administrator's password. It is now
 * converted into hard assertions of the enforced contract:
 *
 *     authorized = permission granted AND tenant ownership satisfied
 *
 * Every probe runs the REAL path — Gate (including the Gate::before system-role bypass) →
 * UserPolicy → BasePolicy::can() → PermissionService, with TenantOwnershipResolver deciding
 * ownership. Nothing is stubbed.
 *
 * `$grantsBaselineAuthorization = false` is mandatory: TestCase::actingAs() grants an
 * is_system role to a role-less user, and is_system is exactly the flag that bypasses every
 * ability check. With it enabled these subjects would be handed the global authority under
 * test and every assertion would pass vacuously.
 *
 * Actors are authenticated via actingAs() rather than Gate::forUser(), because
 * TenantOwnershipResolver resolves the authenticated actor — which is what the standard Gate
 * path supplies in production. The fail-closed behaviour without an authenticated actor is
 * asserted separately.
 */
final class UserManagementTenantBoundaryProbeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    /** Every ability on UserPolicy that names a target User. */
    private const TARGET_ABILITIES = [
        'view' => 'iam.users.view',
        'update' => 'iam.users.update',
        'delete' => 'iam.users.delete',
        'activate' => 'iam.users.activate',
        'suspend' => 'iam.users.suspend',
        'assignRole' => 'iam.users.assign-role',
        'assignOrganization' => 'iam.users.assign-org',
        'invite' => 'iam.users.invite',
        'resetPassword' => 'iam.users.reset-password',
        'manageSessions' => 'iam.users.manage-sessions',
    ];

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
    }

    /**
     * A company administrator: a NON-system role carrying the given iam.users.* permissions.
     *
     * @param  list<string>|null  $permissions
     */
    private function companyAdmin(?Company $company, string $slug, ?array $permissions = null): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);

        $role = Role::firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Probe '.$slug, 'is_system' => false],
        );

        foreach ($permissions ?? array_values(self::TARGET_ABILITIES) as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );
            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission->id);
            }
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user->refresh();
    }

    /** A holder of a system role — the architecture's existing global actor (Gate::before). */
    private function systemActor(?Company $company, string $slug): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);

        $role = Role::firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Probe System '.$slug, 'is_system' => true],
        );

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user->refresh();
    }

    private function plainUser(?Company $company): User
    {
        return User::factory()->create(['company_id' => $company?->id]);
    }

    /** The real authorization decision, with the actor authenticated as in production. */
    private function decide(User $actor, string $ability, User $target): bool
    {
        $this->actingAs($actor);

        return Gate::allows($ability, $target);
    }

    // ── PART 11 — the certified security matrix ───────────────────────────────

    public function test_case_1_same_company_target_is_allowed(): void
    {
        $admin = $this->companyAdmin($this->companyA, 'cert-admin-a');
        $target = $this->plainUser($this->companyA);

        self::assertTrue(
            $this->decide($admin, 'resetPassword', $target),
            'CASE 1: a company administrator must retain authority inside their own company.',
        );
    }

    public function test_case_2_cross_company_target_is_denied(): void
    {
        $admin = $this->companyAdmin($this->companyA, 'cert-admin-a2');
        $target = $this->plainUser($this->companyB);

        self::assertFalse(
            $this->decide($admin, 'resetPassword', $target),
            'CASE 2: company A must NOT reach a company B user.',
        );
    }

    public function test_case_3_reverse_cross_company_target_is_denied(): void
    {
        $admin = $this->companyAdmin($this->companyB, 'cert-admin-b');
        $target = $this->plainUser($this->companyA);

        self::assertFalse(
            $this->decide($admin, 'resetPassword', $target),
            'CASE 3: the boundary is symmetric.',
        );
    }

    // ── PART 13 — privilege escalation ────────────────────────────────────────

    public function test_case_4_cross_company_super_administrator_is_denied(): void
    {
        $admin = $this->companyAdmin($this->companyA, 'cert-admin-a3');
        $superAdminB = $this->systemActor($this->companyB, 'cert-super-b');

        self::assertTrue(
            $superAdminB->roles()->where('is_system', true)->exists(),
            'Precondition: the target really is a system-role holder.',
        );

        self::assertFalse(
            $this->decide($admin, 'resetPassword', $superAdminB),
            'CASE 4: the platform-takeover path must be closed.',
        );
    }

    // ── PART 5 / CASE 5 — the existing global actor is preserved ──────────────

    public function test_case_5_system_administrator_retains_cross_company_authority(): void
    {
        $systemActor = $this->systemActor(null, 'cert-system-global');
        $targetA = $this->plainUser($this->companyA);
        $targetB = $this->plainUser($this->companyB);

        self::assertTrue(
            $this->decide($systemActor, 'resetPassword', $targetA),
            'CASE 5: is_system crosses companies — the documented Gate::before contract.',
        );
        self::assertTrue(
            $this->decide($systemActor, 'resetPassword', $targetB),
            'CASE 5: and into any other company too.',
        );
    }

    // ── CASE 6 — a null-company target is not a loophole ──────────────────────

    public function test_case_6_null_company_target_is_denied_for_a_company_actor(): void
    {
        $admin = $this->companyAdmin($this->companyA, 'cert-admin-a4');
        $orphan = $this->plainUser(null);

        self::assertNull($orphan->company_id, 'Precondition: the target has no company.');

        self::assertFalse(
            $this->decide($admin, 'resetPassword', $orphan),
            'CASE 6: a company-less target must not be reachable by a company-scoped actor.',
        );
    }

    /** A company-less, non-system actor is unprivileged — never unrestricted. */
    public function test_case_6b_null_company_actor_without_system_role_is_denied(): void
    {
        $orphanAdmin = $this->companyAdmin(null, 'cert-orphan-admin');
        $target = $this->plainUser($this->companyA);

        self::assertNull($orphanAdmin->company_id);
        self::assertFalse(
            $orphanAdmin->roles()->where('is_system', true)->exists(),
            'Precondition: no system role.',
        );

        self::assertFalse(
            $this->decide($orphanAdmin, 'resetPassword', $target),
            'CASE 6b: null company must never be read as global privilege.',
        );
    }

    // ── PART 12 — every target-taking ability is bounded ──────────────────────

    public function test_all_target_abilities_are_denied_across_the_company_boundary(): void
    {
        $admin = $this->companyAdmin($this->companyA, 'cert-admin-all');
        $foreign = $this->plainUser($this->companyB);
        $own = $this->plainUser($this->companyA);

        foreach (self::TARGET_ABILITIES as $ability => $permission) {
            self::assertFalse(
                $this->decide($admin, $ability, $foreign),
                "Cross-company [{$ability}] must be denied.",
            );
            self::assertTrue(
                $this->decide($admin, $ability, $own),
                "Same-company [{$ability}] must remain allowed — the boundary must not over-block.",
            );
        }
    }

    // ── PART 14 — permission granularity is preserved ─────────────────────────

    public function test_permission_granularity_survives_the_tenant_boundary(): void
    {
        // Holds assign-role ONLY.
        $admin = $this->companyAdmin($this->companyA, 'cert-assign-only', ['iam.users.assign-role']);
        $sameCompany = $this->plainUser($this->companyA);
        $foreign = $this->plainUser($this->companyB);

        self::assertTrue(
            $this->decide($admin, 'assignRole', $sameCompany),
            'The granted ability still works inside the actor\'s own company.',
        );
        self::assertFalse(
            $this->decide($admin, 'resetPassword', $sameCompany),
            'A permission that was never granted stays denied — tenancy does not replace permission.',
        );
        self::assertFalse(
            $this->decide($admin, 'assignRole', $foreign),
            'PART 10: role assignment is bounded by company too.',
        );
    }

    // ── PART 28 — bypass resistance ───────────────────────────────────────────

    /**
     * The mandatory bypass case: a controller resolves a Company B user by id — with no
     * tenant filtering whatsoever — and hands the object straight to the policy.
     */
    public function test_policy_rejects_a_directly_loaded_foreign_user_object(): void
    {
        $admin = $this->companyAdmin($this->companyA, 'cert-admin-bypass');
        $foreignId = $this->plainUser($this->companyB)->getKey();

        // Deliberately unscoped lookup, exactly as a naive controller would write it.
        $loaded = User::query()->findOrFail($foreignId);
        self::assertSame($this->companyB->id, $loaded->company_id);

        foreach (self::TARGET_ABILITIES as $ability => $_) {
            self::assertFalse(
                $this->decide($admin, $ability, $loaded),
                "PART 28: the policy itself must reject a directly-loaded foreign user for [{$ability}].",
            );
        }
    }

    /** With no authenticated actor the ownership authority denies — the boundary fails closed. */
    public function test_boundary_fails_closed_without_an_authenticated_actor(): void
    {
        $admin = $this->companyAdmin($this->companyA, 'cert-admin-closed');
        $ownCompanyTarget = $this->plainUser($this->companyA);

        // Gate::forUser() supplies the actor for the permission check but leaves the request
        // unauthenticated, so the ownership authority has no company context to trust.
        self::assertFalse(
            Gate::forUser($admin)->allows('resetPassword', $ownCompanyTarget),
            'No authenticated actor ⇒ deny, never allow.',
        );
    }
}
