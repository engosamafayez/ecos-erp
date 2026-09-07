<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-INTERNAL-COLLABORATION-CHAT-FINAL-IMPLEMENTATION-002 — per-
 * participant, per-conversation mute (architecture report §21). Purely a
 * notification preference: never touches unread-count derivation or message
 * history, only whether SendMessageAction::broadcastAndNotify() sends the
 * generic NewMessageNotification for the muting participant. Reuses the
 * existing Notifications authority (stock Laravel Notification classes on
 * the `database` channel) — no second receipt/notification engine.
 */
final class CollaborationMuteTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-mute-employee', ['collaboration.conversations.create']);
    }

    public function test_a_participant_can_mute_and_unmute_a_conversation(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/conversations/{$conversation->id}/mute", ['muted' => true])
            ->assertOk();

        self::assertNotNull(
            ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $actor->id)->first()?->muted_at,
        );

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/conversations/{$conversation->id}/mute", ['muted' => false])
            ->assertOk();

        self::assertNull(
            ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $actor->id)->first()?->muted_at,
        );
    }

    public function test_a_non_participant_cannot_mute_a_conversation(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->actingAsUnprivileged($outsider)
            ->patchJson("/api/collaboration/conversations/{$conversation->id}/mute", ['muted' => true])
            ->assertForbidden();
    }

    public function test_muting_suppresses_the_generic_new_message_notification_but_not_unread_count(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($target)
            ->patchJson("/api/collaboration/conversations/{$conversation->id}/mute", ['muted' => true])
            ->assertOk();

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'are you there'])
            ->assertCreated();

        self::assertSame(0, DatabaseNotification::query()->where('notifiable_id', $target->id)->count());

        // Muting is a notification preference only — unread count (derived purely
        // from the read cursor) is unaffected.
        $this->actingAsUnprivileged($target)
            ->getJson('/api/collaboration/conversations')
            ->assertOk()
            ->assertJsonFragment(['id' => $conversation->id, 'unread_count' => 1]);
    }

    public function test_an_explicit_mention_still_notifies_a_muted_participant(): void
    {
        $company = Company::factory()->create();
        $owner = $this->employee($company);
        $memberA = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->groupConversation($company, $owner, [$memberA]);

        $this->actingAsUnprivileged($memberA)
            ->patchJson("/api/collaboration/conversations/{$conversation->id}/mute", ['muted' => true])
            ->assertOk();

        $this->actingAsUnprivileged($owner)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'body' => 'Hey @memberA even though muted',
                'mentioned_user_ids' => [$memberA->id],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $memberA->id,
            'type' => \Modules\Collaboration\Application\Notifications\MentionedNotification::class,
        ]);
    }
}
