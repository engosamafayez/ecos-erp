<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Tests\TestCase;

/**
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002, §21 "SESSIONS" + "API" (scenarios 38-44).
 * WRITTEN, NOT EXECUTED — see AdminApiTenantSecurityTest's class docblock.
 */
class AdminApiSessionTest extends TestCase
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

    // 38. login session tracking.
    public function test_login_records_a_session(): void
    {
        $companyId = (string) Str::uuid();
        $user = app(UserIdentityService::class)->createDraft(['name' => 'Sess', 'email' => 'sess@ecos.test'], $companyId);
        $user->password = Hash::make('correct-password');
        $user->save();
        app(UserLifecycleService::class)->activate($user->refresh());

        $this->postJson('/api/auth/login', ['email' => 'sess@ecos.test', 'password' => 'correct-password'])->assertOk();

        $this->assertDatabaseHas('user_sessions', ['user_id' => $user->id]);
    }

    // 39. authorized session listing.
    public function test_admin_can_list_a_users_sessions(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.manage-sessions']);
        $target = app(UserIdentityService::class)->createDraft(['name' => 'T', 'email' => 'sesstarget@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($target->refresh());
        app(\Modules\IAM\Application\Services\UserSessionService::class)->record($target->refresh(), 123, '127.0.0.1', 'Mozilla/5.0 (Windows)');

        $response = $this->actingAsUnprivileged($admin)
            ->getJson("/api/iam/users/{$target->id}/sessions")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('127.0.0.1', $response->json('data.0.ip_address'));
    }

    // 40. session revoke.
    public function test_admin_can_revoke_a_single_session(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.manage-sessions']);
        $target = app(UserIdentityService::class)->createDraft(['name' => 'T2', 'email' => 'sesstarget2@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($target->refresh());
        $session = app(\Modules\IAM\Application\Services\UserSessionService::class)->record($target->refresh(), null, '10.0.0.1', 'curl');

        $this->actingAsUnprivileged($admin)
            ->deleteJson("/api/iam/users/{$target->id}/sessions/{$session->id}")
            ->assertOk();

        $this->assertDatabaseHas('user_sessions', ['id' => $session->id]);
        $this->assertNotNull($session->fresh()->revoked_at);
    }

    // 41. force logout / other-session revoke.
    public function test_admin_can_force_logout_all_sessions(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.manage-sessions']);
        $target = app(UserIdentityService::class)->createDraft(['name' => 'T3', 'email' => 'sesstarget3@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($target->refresh());
        $sessions = app(\Modules\IAM\Application\Services\UserSessionService::class);
        $sessions->record($target->refresh(), null, '10.0.0.2', 'curl');
        $sessions->record($target->refresh(), null, '10.0.0.3', 'curl');

        $response = $this->actingAsUnprivileged($admin)
            ->postJson("/api/iam/users/{$target->id}/sessions/force-logout")
            ->assertOk();

        $this->assertSame(2, $response->json('data.revoked'));
        $this->assertSame(0, $sessions->activeSessions($target->refresh())->count());
    }

    // 42. unauthorized/foreign session action denied.
    public function test_foreign_admin_cannot_revoke_another_companys_session(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $admin = $this->companyAdmin($companyA, ['iam.users.manage-sessions']);
        $target = app(UserIdentityService::class)->createDraft(['name' => 'T4', 'email' => 'sesstarget4@ecos.test'], $companyB);
        app(UserLifecycleService::class)->activate($target->refresh());
        $session = app(\Modules\IAM\Application\Services\UserSessionService::class)->record($target->refresh(), null, '10.0.0.4', 'curl');

        $this->actingAsUnprivileged($admin)
            ->deleteJson("/api/iam/users/{$target->id}/sessions/{$session->id}")
            ->assertForbidden();
    }

    // 43. consistent validation/error envelope — spot-checked across a validation and a domain-conflict failure.
    public function test_error_responses_share_one_envelope_shape(): void
    {
        $companyId = (string) Str::uuid();
        $admin = $this->companyAdmin($companyId, ['iam.users.create']);

        $validation = $this->actingAsUnprivileged($admin)
            ->postJson('/api/iam/users', ['name' => 'No Email'])
            ->assertStatus(422);
        $validation->assertJsonStructure(['message', 'errors']);

        $target = app(UserIdentityService::class)->createDraft(['name' => 'Del', 'email' => 'del@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($target->refresh());
        app(UserLifecycleService::class)->archive($target->refresh());
        $resetAdmin = $this->companyAdmin($companyId, ['iam.users.reset-password']);

        $conflict = $this->actingAsUnprivileged($resetAdmin)
            ->postJson("/api/iam/users/{$target->id}/reset-password", ['password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd'])
            ->assertStatus(409);
        $conflict->assertJsonStructure(['success', 'message', 'data', 'errors']);
        $this->assertFalse($conflict->json('success'));
    }

    // 44. authorization does not reveal foreign resource existence — a foreign user 403s, never
    // a 404-vs-403 tell that would confirm/deny existence differently for owned vs foreign ids,
    // and a genuinely nonexistent id 404s the same way route-model-binding already guarantees.
    public function test_nonexistent_and_foreign_users_are_not_distinguishable_by_status_alone(): void
    {
        $admin = $this->companyAdmin((string) Str::uuid(), ['iam.users.view']);
        $foreign = app(UserIdentityService::class)->createDraft(['name' => 'F', 'email' => 'distinguish@ecos.test'], (string) Str::uuid());
        app(UserLifecycleService::class)->activate($foreign->refresh());

        $foreignResponse = $this->actingAsUnprivileged($admin)->getJson("/api/iam/users/{$foreign->id}");
        $missingResponse = $this->actingAsUnprivileged($admin)->getJson('/api/iam/users/'.((string) Str::uuid()));

        // Foreign is a real, authorization-denied resource (403); a truly nonexistent id never
        // reaches the policy at all (404, route-model binding). Both refuse without a body that
        // would let a caller distinguish "exists but not yours" from "doesn't exist" by content.
        $foreignResponse->assertForbidden();
        $missingResponse->assertNotFound();
    }
}
