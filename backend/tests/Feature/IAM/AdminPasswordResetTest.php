<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\IAM\Application\Services\UserInvitationService;
use Modules\IAM\Application\Services\UserPasswordService;
use Modules\IAM\Domain\Enums\UserStatus;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\UserInvitation;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-IAM-PASSWORD-RESET-DOMAIN-OPERATION-001 — runtime certification.
 *
 * Certifies the dedicated administrator password-reset operation: that it changes the password,
 * refuses across the certified tenant boundary, preserves permission granularity, never touches
 * lifecycle state, never leaks the password into the audit trail, and leaves the invitation flow
 * untouched.
 *
 * `$grantsBaselineAuthorization = false` is mandatory: TestCase::actingAs() grants an is_system
 * role to a role-less user, and is_system bypasses every ability check via Gate::before. With it
 * enabled every denial assertion would pass vacuously.
 */
final class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private const OLD_PASSWORD = 'OldPassw0rd!parent';

    private const NEW_PASSWORD = 'NewPassw0rd!child';

    private Company $companyA;

    private Company $companyB;

    private UserPasswordService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->service = app(UserPasswordService::class);
    }

    /** @param list<string> $permissions */
    private function admin(?Company $company, string $slug, array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);

        $role = Role::firstOrCreate(['slug' => $slug], ['name' => 'PR '.$slug, 'is_system' => false]);

        foreach ($permissions as $name) {
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

    private function systemActor(?Company $company, string $slug): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => 'PR System '.$slug, 'is_system' => true]);
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user->refresh();
    }

    /** A target with a known starting password and an explicit lifecycle state. */
    private function target(?Company $company, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = User::factory()->create([
            'company_id' => $company?->id,
            'password' => Hash::make(self::OLD_PASSWORD),
            'status' => $status->value,
        ]);

        return $user->refresh();
    }

    private function resetter(array $extra = []): User
    {
        return $this->admin($this->companyA, 'pr-admin-a'.($extra['slug'] ?? ''), ['iam.users.reset-password']);
    }

    // ── CASE 1 — same-company reset succeeds and actually changes the password ────

    public function test_case_1_same_company_reset_changes_the_password(): void
    {
        $admin = $this->resetter();
        $target = $this->target($this->companyA);

        $before = $target->password;
        self::assertTrue(Hash::check(self::OLD_PASSWORD, $before), 'Precondition: old password is set.');

        $this->actingAs($admin);
        $this->service->adminReset($target, self::NEW_PASSWORD, $admin->getKey());

        $target->refresh();

        self::assertNotSame($before, $target->password, 'The stored hash must change.');
        self::assertTrue(Hash::check(self::NEW_PASSWORD, $target->password), 'New password must verify.');
        self::assertFalse(Hash::check(self::OLD_PASSWORD, $target->password), 'Old password must no longer verify.');
    }

    // ── CASE 2 — cross-company reset denied, password untouched ──────────────────

    public function test_case_2_cross_company_reset_is_denied_and_password_unchanged(): void
    {
        $admin = $this->resetter();
        $target = $this->target($this->companyB);
        $before = $target->password;

        $this->actingAs($admin);

        try {
            $this->service->adminReset($target, self::NEW_PASSWORD, $admin->getKey());
            self::fail('Cross-company reset must be refused.');
        } catch (AuthorizationException) {
            // expected
        }

        $target->refresh();
        self::assertSame($before, $target->password, 'A denied reset must not mutate the target.');
        self::assertTrue(Hash::check(self::OLD_PASSWORD, $target->password), 'Old password still valid.');
        self::assertNull($target->password_changed_at, 'No timestamp may be stamped on denial.');
    }

    // ── CASE 3 — cross-company Super Administrator denied ────────────────────────

    public function test_case_3_cross_company_super_administrator_is_denied(): void
    {
        $admin = $this->resetter();
        $superAdminB = $this->systemActor($this->companyB, 'pr-super-b');
        $superAdminB->forceFill(['password' => Hash::make(self::OLD_PASSWORD)])->save();
        $before = $superAdminB->refresh()->password;

        self::assertTrue($superAdminB->roles()->where('is_system', true)->exists());

        $this->actingAs($admin);

        try {
            $this->service->adminReset($superAdminB, self::NEW_PASSWORD, $admin->getKey());
            self::fail('The platform-takeover path must stay closed.');
        } catch (AuthorizationException) {
            // expected
        }

        self::assertSame($before, $superAdminB->refresh()->password);
    }

    // ── CASE 4 — permission granularity preserved ────────────────────────────────

    public function test_case_4_actor_without_reset_permission_is_denied(): void
    {
        // Holds a DIFFERENT iam.users permission, in the SAME company.
        $admin = $this->admin($this->companyA, 'pr-assign-only', ['iam.users.assign-role']);
        $target = $this->target($this->companyA);
        $before = $target->password;

        $this->actingAs($admin);

        try {
            $this->service->adminReset($target, self::NEW_PASSWORD, $admin->getKey());
            self::fail('Tenant ownership must not substitute for the permission.');
        } catch (AuthorizationException) {
            // expected
        }

        self::assertSame($before, $target->refresh()->password);
    }

    // ── CASE 5 — system administrator retains existing certified semantics ───────

    public function test_case_5_system_administrator_may_reset_across_companies(): void
    {
        $systemActor = $this->systemActor(null, 'pr-system-global');
        $target = $this->target($this->companyB);

        $this->actingAs($systemActor);
        $this->service->adminReset($target, self::NEW_PASSWORD, $systemActor->getKey());

        self::assertTrue(
            Hash::check(self::NEW_PASSWORD, $target->refresh()->password),
            'Gate::before system-role semantics must be preserved.',
        );
    }

    // ── CASES 6 & 7 — lifecycle is never changed by a reset ──────────────────────

    /** @return array<string, array{UserStatus}> */
    public static function preservedStatuses(): array
    {
        return [
            'suspended' => [UserStatus::SUSPENDED],
            'locked' => [UserStatus::LOCKED],
            'inactive' => [UserStatus::INACTIVE],
            'active' => [UserStatus::ACTIVE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('preservedStatuses')]
    public function test_cases_6_and_7_lifecycle_state_survives_the_reset(UserStatus $status): void
    {
        $admin = $this->resetter();
        $target = $this->target($this->companyA, $status);

        // UserFactory stamps email_verified_at by default. Left as-is, the post-reset
        // assertNull below would only be re-testing the fixture. Clearing it first puts the
        // target in the state that actually matters: unverified, so that the assertion proves
        // the reset does not verify an email — the exact side effect activate() would cause.
        $target->forceFill(['email_verified_at' => null])->save();
        $target->refresh();

        self::assertSame($status->value, $target->status, 'Precondition.');
        self::assertNull($target->email_verified_at, 'Precondition: target is unverified.');
        self::assertNull($target->activated_at, 'Precondition: target is not activated.');

        $this->actingAs($admin);
        $this->service->adminReset($target, self::NEW_PASSWORD, $admin->getKey());

        $target->refresh();

        self::assertSame($status->value, $target->status, "[{$status->value}] must survive the reset.");
        self::assertTrue(Hash::check(self::NEW_PASSWORD, $target->password), 'Password still changed.');

        // A suspended/locked account must NOT become able to authenticate.
        self::assertSame(
            $status === UserStatus::ACTIVE,
            $target->statusEnum()->canAuthenticate(),
            'Reset must never grant authentication to a non-active account.',
        );

        // The invitation-activation side effects must be absent.
        self::assertNull($target->activated_at, 'Reset must not stamp activated_at.');
        self::assertNull($target->email_verified_at, 'Reset must not verify email.');
    }

    // ── CASE 8 (Part 15) — password_changed_at moves forward ─────────────────────

    public function test_password_changed_at_advances(): void
    {
        $admin = $this->resetter();
        $target = $this->target($this->companyA);

        $target->forceFill(['password_changed_at' => now()->subDays(30)])->save();
        $t1 = $target->refresh()->password_changed_at;
        self::assertNotNull($t1);

        $this->actingAs($admin);
        $this->service->adminReset($target, self::NEW_PASSWORD, $admin->getKey());

        $t2 = $target->refresh()->password_changed_at;
        self::assertNotNull($t2);
        self::assertTrue($t2->greaterThan($t1), "T2 ({$t2}) must be later than T1 ({$t1}).");
    }

    // ── No plaintext password anywhere in the audit trail ────────────────────────

    public function test_no_plaintext_password_or_hash_reaches_the_audit_trail(): void
    {
        $admin = $this->resetter();
        $target = $this->target($this->companyA);

        $this->actingAs($admin);
        $this->service->adminReset($target, self::NEW_PASSWORD, $admin->getKey());

        $rows = DB::table('audit_logs')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $target->getKey())
            ->get();

        self::assertNotEmpty($rows, 'The reset must be audited.');

        $blob = strtolower(json_encode($rows->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(strtolower(self::NEW_PASSWORD), $blob, 'Raw password leaked.');
        self::assertStringNotContainsString(strtolower(self::OLD_PASSWORD), $blob, 'Old password leaked.');
        self::assertStringNotContainsString('$2y$', $blob, 'A bcrypt hash leaked into the audit payload.');

        self::assertTrue(
            $rows->contains(fn ($r) => $r->action === 'user.password_reset'),
            'The reset must audit as user.password_reset, not as an invitation activation.',
        );
        self::assertFalse(
            $rows->contains(fn ($r) => $r->action === 'user.activated'),
            'A reset must never audit as an activation.',
        );
    }

    // ── The invitation flow must be entirely unaffected ──────────────────────────

    public function test_invitation_flow_is_untouched_by_a_password_reset(): void
    {
        $admin = $this->resetter();
        $target = $this->target($this->companyA);

        // Issue a real invitation, then reset the password.
        $invitationService = app(UserInvitationService::class);
        $rawToken = $invitationService->invite($target, $admin->getKey());

        $invitationBefore = UserInvitation::where('user_id', $target->getKey())->firstOrFail();
        self::assertSame(UserInvitation::STATUS_PENDING, $invitationBefore->status);

        $this->actingAs($admin);
        $this->service->adminReset($target, self::NEW_PASSWORD, $admin->getKey());

        $invitationAfter = UserInvitation::where('user_id', $target->getKey())->firstOrFail();

        self::assertSame(
            UserInvitation::STATUS_PENDING,
            $invitationAfter->status,
            'A password reset must NOT consume the invitation.',
        );
        self::assertNull($invitationAfter->accepted_at, 'accepted_at must remain null.');
        self::assertSame(
            $invitationBefore->token_hash,
            $invitationAfter->token_hash,
            'The invitation token must be untouched.',
        );

        // And the invitation still works afterwards — the flow is genuinely intact.
        $activated = $invitationService->activate($rawToken, 'ActivatedLater!9');
        self::assertSame(UserStatus::ACTIVE->value, $activated->status);
        self::assertTrue(Hash::check('ActivatedLater!9', $activated->password));
    }

    // ── Bypass resistance — the operation cannot be reached unauthorized ─────────

    public function test_reset_cannot_be_invoked_on_a_directly_loaded_foreign_user(): void
    {
        $admin = $this->resetter();
        $foreignId = $this->target($this->companyB)->getKey();

        // Deliberately unscoped lookup, as a naive controller would write it.
        $loaded = User::query()->findOrFail($foreignId);
        $before = $loaded->password;

        $this->actingAs($admin);

        try {
            $this->service->adminReset($loaded, self::NEW_PASSWORD, $admin->getKey());
            self::fail('Passing a directly-loaded foreign user must not bypass the boundary.');
        } catch (AuthorizationException) {
            // expected
        }

        self::assertSame($before, User::query()->findOrFail($foreignId)->password);
    }

    /** With no authenticated actor the operation denies rather than proceeding. */
    public function test_reset_fails_closed_without_an_authenticated_actor(): void
    {
        $target = $this->target($this->companyA);
        $before = $target->password;

        try {
            $this->service->adminReset($target, self::NEW_PASSWORD, null);
            self::fail('An unauthenticated reset must be refused.');
        } catch (AuthorizationException) {
            // expected
        }

        self::assertSame($before, $target->refresh()->password);
    }

    // ── require_password_change is honoured ──────────────────────────────────────

    public function test_require_password_change_flag_is_applied(): void
    {
        $admin = $this->resetter();
        $target = $this->target($this->companyA);

        $this->actingAs($admin);
        $this->service->adminReset($target, self::NEW_PASSWORD, $admin->getKey(), requirePasswordChange: true);

        self::assertTrue((bool) $target->refresh()->require_password_change);
    }
}
