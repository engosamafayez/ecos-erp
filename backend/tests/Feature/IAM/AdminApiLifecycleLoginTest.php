<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Application\Services\UserPasswordService;
use Modules\IAM\Domain\Enums\UserStatus;
use Modules\IAM\Domain\Exceptions\UserSecurityRuleException;
use Tests\TestCase;

/**
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002, §21 "LIFECYCLE / LOGIN" (scenarios 10-16).
 * WRITTEN, NOT EXECUTED — see AdminApiTenantSecurityTest's class docblock.
 */
class AdminApiLifecycleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPassword(string $email, string $password): User
    {
        $user = app(UserIdentityService::class)->createDraft(['name' => 'Login Test', 'email' => $email], (string) Str::uuid());
        $user->password = Hash::make($password);
        $user->save();

        return $user;
    }

    private function login(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    // 10. active user may login.
    public function test_active_user_can_login(): void
    {
        $user = $this->userWithPassword('active@ecos.test', 'correct-password');
        app(UserLifecycleService::class)->activate($user->refresh());

        $this->login('active@ecos.test', 'correct-password')->assertOk();
        $this->assertDatabaseHas('user_sessions', ['user_id' => $user->id]);
    }

    // 11. suspended user cannot login.
    public function test_suspended_user_cannot_login(): void
    {
        $user = $this->userWithPassword('susp@ecos.test', 'correct-password');
        $svc = app(UserLifecycleService::class);
        $svc->activate($user->refresh());
        $svc->suspend($user->refresh());

        $this->login('susp@ecos.test', 'correct-password')->assertUnauthorized();
    }

    // 12. locked user cannot login.
    public function test_locked_user_cannot_login(): void
    {
        $user = $this->userWithPassword('lock@ecos.test', 'correct-password');
        $svc = app(UserLifecycleService::class);
        $svc->activate($user->refresh());
        $svc->lock($user->refresh());

        $this->login('lock@ecos.test', 'correct-password')->assertUnauthorized();
    }

    // 13. archived user cannot login.
    public function test_archived_user_cannot_login(): void
    {
        $user = $this->userWithPassword('arch@ecos.test', 'correct-password');
        $svc = app(UserLifecycleService::class);
        $svc->activate($user->refresh());
        $svc->archive($user->refresh());

        $this->login('arch@ecos.test', 'correct-password')->assertUnauthorized();
    }

    // (D7 corollary) wrong password and non-authenticating status must be indistinguishable.
    public function test_wrong_password_and_ineligible_status_return_identical_response(): void
    {
        $user = $this->userWithPassword('shape@ecos.test', 'correct-password');
        app(UserLifecycleService::class)->activate($user->refresh());
        app(UserLifecycleService::class)->suspend($user->refresh());

        $suspended = $this->login('shape@ecos.test', 'correct-password');
        $wrongPassword = $this->login('shape@ecos.test', 'totally-wrong');

        $suspended->assertUnauthorized();
        $wrongPassword->assertUnauthorized();
        $this->assertSame($wrongPassword->json('message'), $suspended->json('message'));
    }

    // 14. archived user reset rejected.
    public function test_password_reset_rejected_for_archived_user(): void
    {
        $user = $this->userWithPassword('reset-arch@ecos.test', 'x');
        $svc = app(UserLifecycleService::class);
        $svc->activate($user->refresh());
        $svc->archive($user->refresh());

        $this->expectException(UserSecurityRuleException::class);
        app(UserPasswordService::class)->adminReset($user->refresh(), 'NewStr0ng!Pass', null);
    }

    // 15. deleted user reset rejected.
    public function test_password_reset_rejected_for_deleted_user(): void
    {
        $user = $this->userWithPassword('reset-del@ecos.test', 'x');
        $svc = app(UserLifecycleService::class);
        $svc->activate($user->refresh());
        $svc->softDelete($user->refresh());

        $this->expectException(UserSecurityRuleException::class);
        app(UserPasswordService::class)->adminReset(User::withTrashed()->findOrFail($user->id), 'NewStr0ng!Pass', null);
    }

    // 16. suspended/locked reset does not reactivate/unlock.
    public function test_password_reset_for_suspended_user_does_not_reactivate(): void
    {
        $user = $this->userWithPassword('reset-susp@ecos.test', 'x');
        $svc = app(UserLifecycleService::class);
        $svc->activate($user->refresh());
        $svc->suspend($user->refresh());

        app(UserPasswordService::class)->adminReset($user->refresh(), 'NewStr0ng!Pass', null);

        $this->assertSame(UserStatus::SUSPENDED, $user->refresh()->statusEnum());
        $this->login('reset-susp@ecos.test', 'NewStr0ng!Pass')->assertUnauthorized();
    }

    // ARCHIVED restore works (STOP 5 fix, control case — must keep working).
    public function test_restore_from_archived_reaches_active(): void
    {
        $user = $this->userWithPassword('restore-arch@ecos.test', 'x');
        $svc = app(UserLifecycleService::class);
        $svc->activate($user->refresh());
        $svc->archive($user->refresh());

        $svc->restore($user->refresh());

        $this->assertSame(UserStatus::ACTIVE, $user->refresh()->statusEnum());
    }

    // DELETED restore now reaches ACTIVE cleanly (STOP 5 fix — this was the broken path).
    public function test_restore_from_deleted_reaches_active(): void
    {
        $user = $this->userWithPassword('restore-del@ecos.test', 'x');
        $svc = app(UserLifecycleService::class);
        $svc->activate($user->refresh());
        $svc->softDelete($user->refresh());

        $trashed = User::withTrashed()->findOrFail($user->id);
        $svc->restore($trashed);

        $fresh = User::findOrFail($user->id); // no longer trashed — default scope finds it again
        $this->assertSame(UserStatus::ACTIVE, $fresh->statusEnum());
        $this->assertNull($fresh->deleted_at);
    }
}
