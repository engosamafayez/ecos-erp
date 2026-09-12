<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Broadcast;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-V1-PRIVATE-CONVERSATION-BROADCAST-SECURITY-038.
 *
 * Task 037 found that /broadcasting/auth authorized every authenticated user
 * for every channel unconditionally: BROADCAST_CONNECTION defaults to 'log',
 * and the stock Illuminate LogBroadcaster::auth() (and NullBroadcaster's) is
 * an unconditional no-op that never invokes routes/channels.php at all. This
 * suite proves the fix (App\Broadcasting\FailClosedLogBroadcaster /
 * FailClosedNullBroadcaster, registered in AppServiceProvider::boot()) makes
 * the same canonical participant-authorization callback in
 * routes/channels.php actually run, fail-closed, using the real
 * /broadcasting/auth endpoint under this application's actual configured
 * driver — not a hand-rolled second membership check.
 *
 * Defensive verification of the application's own authorization boundary —
 * no exploit tooling, no offensive probes, real canonical HTTP + auth path
 * throughout.
 */
final class CollaborationBroadcastAuthorizationSecurityTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-security-employee', ['collaboration.conversations.create']);
    }

    private function subscribe(User $as, string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUnprivileged($as)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-{$channel}",
                'socket_id' => '1234.5678',
            ]);
    }

    // 6. The whole suite runs under this application's actual effective
    // driver — proving the fix, not merely a driver this environment
    // doesn't use. If this ever fails, everything below is not proving what
    // it claims to.
    public function test_the_effective_broadcast_driver_is_the_one_this_fix_targets(): void
    {
        self::assertSame('log', config('broadcasting.default'));
        self::assertInstanceOf(
            \App\Broadcasting\FailClosedLogBroadcaster::class,
            Broadcast::driver('log'),
        );
    }

    // 1. Unauthenticated — rejected before ever reaching channel authorization.
    public function test_unauthenticated_request_is_denied(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-collaboration.conversation.{$conversation->id}",
            'socket_id' => '1234.5678',
        ])->assertUnauthorized();
    }

    // 2. Authenticated, but never a participant of this conversation.
    public function test_authenticated_non_participant_is_denied(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->subscribe($outsider, "collaboration.conversation.{$conversation->id}")
            ->assertForbidden();
    }

    // 3. A genuine, active participant is allowed.
    public function test_an_active_participant_is_allowed(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->subscribe($actor, "collaboration.conversation.{$conversation->id}")
            ->assertOk();
    }

    // 4. A user in a different company entirely — never a participant, and
    // structurally cannot become one (AddGroupParticipantAction enforces
    // company match at add-time) — is denied for this conversation.
    public function test_a_user_in_a_different_company_is_denied(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actor = $this->employee($companyA);
        $target = User::factory()->create(['company_id' => $companyA->id]);
        $conversation = $this->directConversation($companyA, $actor, $target);
        $outsider = $this->employee($companyB);

        $this->subscribe($outsider, "collaboration.conversation.{$conversation->id}")
            ->assertForbidden();
    }

    // 5. A real participant of a DIFFERENT conversation is denied for this
    // one — proves the check is scoped to the exact conversation id, not
    // "is a participant of something in this company."
    public function test_a_participant_of_a_different_conversation_is_denied(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $otherA = $this->employee($company);
        $otherB = User::factory()->create(['company_id' => $company->id]);
        $otherConversation = $this->directConversation($company, $otherA, $otherB);

        $this->subscribe($otherA, "collaboration.conversation.{$conversation->id}")
            ->assertForbidden();
        self::assertNotSame($conversation->id, $otherConversation->id);
    }

    // A departed participant (left_at set) must not still be authorized —
    // exercises the same whereNull('left_at') boundary the REST endpoints
    // already enforce (routes/channels.php's own docblock), through the now
    // actually-enforced broadcast path.
    public function test_a_participant_who_has_left_the_conversation_is_denied(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        \Modules\Collaboration\Domain\Models\ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $target->id)
            ->update(['left_at' => now()]);

        $this->subscribe($target, "collaboration.conversation.{$conversation->id}")
            ->assertForbidden();
    }

    // Same defect, second registered channel (routes/channels.php's
    // collaboration.user.{userId}) — proves the fix is generic (it enforces
    // whatever routes/channels.php actually registers), not a special case
    // hand-written for the conversation channel alone.
    public function test_a_user_cannot_subscribe_to_another_users_private_task_channel(): void
    {
        $company = Company::factory()->create();
        $me = $this->employee($company);
        $someoneElse = $this->employee($company);

        $this->subscribe($me, "collaboration.user.{$someoneElse->id}")
            ->assertForbidden();

        $this->subscribe($me, "collaboration.user.{$me->id}")
            ->assertOk();
    }
}
