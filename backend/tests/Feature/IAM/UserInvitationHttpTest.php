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
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\UserInvitation;
use Tests\TestCase;

/**
 * CORE-02 Task 1 — Invitation Closure, over HTTP. The underlying token/hash/expiry mechanics
 * are already covered at the service level (UserManagementTest, AdminPasswordResetTest); these
 * tests exercise the NEW HTTP surface: authorization, tenant boundary, resend/revoke/list, and
 * the "invitee cannot choose their own access" guarantee end-to-end.
 */
class UserInvitationHttpTest extends TestCase
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

    private function draftUser(string $companyId, string $email): User
    {
        return app(UserIdentityService::class)->createDraft(['name' => 'Invitee', 'email' => $email], $companyId);
    }

    public function test_authorized_admin_can_invite_a_draft_user_via_http(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.invite']);
        $invitee = $this->draftUser($companyId, 'invitee1@ecos.test');

        $response = $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations")
            ->assertOk();

        $this->assertNotEmpty($response->json('data.invitation_token'));
        $this->assertDatabaseHas('user_invitations', ['user_id' => $invitee->getKey(), 'status' => UserInvitation::STATUS_PENDING]);
    }

    public function test_unauthorized_actor_cannot_invite(): void
    {
        $companyId = (string) Str::uuid();
        $actor = $this->companyAdmin($companyId, []); // no iam.users.invite
        $invitee = $this->draftUser($companyId, 'invitee2@ecos.test');

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations")
            ->assertForbidden();
    }

    public function test_admin_cannot_invite_a_user_in_a_foreign_company(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $admin = $this->companyAdmin($companyA, ['iam.users.invite']);
        $foreignInvitee = $this->draftUser($companyB, 'foreign@ecos.test');

        $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$foreignInvitee->getKey()}/invitations")
            ->assertForbidden();

        $this->assertDatabaseMissing('user_invitations', ['user_id' => $foreignInvitee->getKey()]);
    }

    public function test_admin_can_list_a_users_invitation_history_without_leaking_the_token_hash(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.invite', 'iam.users.view']);
        $invitee = $this->draftUser($companyId, 'invitee3@ecos.test');

        $this->actingAsUnprivileged($admin)->postJson("/api/iam/users/{$invitee->getKey()}/invitations")->assertOk();

        $response = $this->actingAsUnprivileged($admin)
            ->getJson("/api/iam/users/{$invitee->getKey()}/invitations")
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertArrayNotHasKey('token_hash', $rows[0]);
    }

    public function test_resend_reissues_a_token_and_invalidates_the_previous_one(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.invite']);
        $invitee = $this->draftUser($companyId, 'invitee4@ecos.test');

        $first = $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations")
            ->assertOk()->json('data.invitation_token');

        $second = $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations/resend")
            ->assertOk()->json('data.invitation_token');

        $this->assertNotSame($first, $second);

        $this->postJson('/api/auth/invitations/accept', [
            'token' => $first,
            'password' => 'Str0ng!Passw0rd#1',
            'password_confirmation' => 'Str0ng!Passw0rd#1',
        ])->assertStatus(422);

        $this->postJson('/api/auth/invitations/accept', [
            'token' => $second,
            'password' => 'Str0ng!Passw0rd#1',
            'password_confirmation' => 'Str0ng!Passw0rd#1',
        ])->assertOk();
    }

    public function test_revoke_prevents_acceptance(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.invite']);
        $invitee = $this->draftUser($companyId, 'invitee5@ecos.test');

        $token = $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations")
            ->assertOk()->json('data.invitation_token');

        $invitation = UserInvitation::where('user_id', $invitee->getKey())->firstOrFail();

        $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations/{$invitation->getKey()}/revoke")
            ->assertOk();

        $this->assertDatabaseHas('user_invitations', ['id' => $invitation->getKey(), 'status' => UserInvitation::STATUS_REVOKED]);

        $this->postJson('/api/auth/invitations/accept', [
            'token' => $token,
            'password' => 'Str0ng!Passw0rd#1',
            'password_confirmation' => 'Str0ng!Passw0rd#1',
        ])->assertStatus(422);
    }

    public function test_accept_with_expired_token_is_rejected(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.invite']);
        $invitee = $this->draftUser($companyId, 'invitee6@ecos.test');

        $token = $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations", ['ttl_hours' => 1])
            ->assertOk()->json('data.invitation_token');

        $this->travel(2)->hours();

        $this->postJson('/api/auth/invitations/accept', [
            'token' => $token,
            'password' => 'Str0ng!Passw0rd#1',
            'password_confirmation' => 'Str0ng!Passw0rd#1',
        ])->assertStatus(422);
    }

    public function test_accept_is_rejected_on_replay(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.invite']);
        $invitee = $this->draftUser($companyId, 'invitee7@ecos.test');

        $token = $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations")
            ->assertOk()->json('data.invitation_token');

        $payload = ['token' => $token, 'password' => 'Str0ng!Passw0rd#1', 'password_confirmation' => 'Str0ng!Passw0rd#1'];

        $this->postJson('/api/auth/invitations/accept', $payload)->assertOk();
        $this->postJson('/api/auth/invitations/accept', $payload)->assertStatus(422);
    }

    /**
     * The accept payload has no role/company field at all (AcceptInvitationRequest), so even a
     * client that sends one has no path to influence it — activate() only ever sets a password
     * on the account invite() already provisioned with its role/company fixed in advance.
     */
    public function test_acceptance_ignores_extraneous_fields_and_preserves_the_preassigned_role_and_company(): void
    {
        $companyId = (string) Str::uuid();
        $foreignCompanyId = (string) Str::uuid();
        $this->perm('sales.orders.view');

        $template = app(RoleTemplateRepositoryInterface::class)->createCustom([
            'key' => 'preassigned-tpl', 'name' => 'Preassigned', 'category' => 'custom',
            'definition' => ['permissions' => ['sales.orders.view']], 'company_id' => $companyId,
        ]);
        app(RoleTemplateCompiler::class)->compile($template);

        $admin = $this->companyAdmin($companyId, ['iam.users.invite']);
        $invitee = $this->draftUser($companyId, 'invitee8@ecos.test');
        app(UserRoleAssignmentService::class)->assignTemplate($invitee, $template->fresh());

        $token = $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations")
            ->assertOk()->json('data.invitation_token');

        $this->postJson('/api/auth/invitations/accept', [
            'token' => $token,
            'password' => 'Str0ng!Passw0rd#1',
            'password_confirmation' => 'Str0ng!Passw0rd#1',
            // A malicious/confused client trying to smuggle in escalation — must be inert.
            'company_id' => $foreignCompanyId,
            'role_template' => 'super-admin',
            'is_system' => true,
        ])->assertOk();

        $invitee->refresh();
        $this->assertSame($companyId, $invitee->company_id);
        $this->assertTrue($invitee->roles()->where('id', $template->fresh()->role_id)->exists());
        $this->assertTrue($invitee->isActive());
    }

    public function test_public_show_endpoint_reveals_only_email_and_expiry(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.invite']);
        $invitee = $this->draftUser($companyId, 'invitee9@ecos.test');

        $token = $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$invitee->getKey()}/invitations")
            ->assertOk()->json('data.invitation_token');

        $response = $this->getJson('/api/auth/invitations/show?token='.$token)->assertOk();

        $this->assertSame('invitee9@ecos.test', $response->json('data.email'));
        $this->assertArrayNotHasKey('company_id', $response->json('data'));
        $this->assertArrayNotHasKey('role', $response->json('data'));
    }

    public function test_public_show_endpoint_404s_for_a_garbage_token(): void
    {
        $this->getJson('/api/auth/invitations/show?token=not-a-real-token')->assertNotFound();
    }
}
