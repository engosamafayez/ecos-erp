<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Collaboration\Presentation\Http\Controllers\MessageController;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-CORE-FOUNDATION-002.
 *
 * Covers brief scenarios 9, 10, 11, 12, 13, 14, 15, 16, 19.
 */
final class CollaborationMessageTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-employee', [
            'collaboration.conversations.create',
            'collaboration.groups.create',
        ]);
    }

    // 9. Text message send.
    public function test_a_participant_can_send_a_text_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $actor->forceFill(['name' => 'Sending Employee'])->save();
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'Hello there'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Hello there')
            ->assertJsonPath('data.type', 'text')
            // Task 5 — the sender's name is resolved inline, not left as a bare id.
            ->assertJsonPath('data.sender_name', 'Sending Employee');
    }

    // 10. Non-member cannot read a conversation.
    public function test_a_non_member_cannot_list_messages(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->actingAsUnprivileged($outsider)
            ->getJson("/api/collaboration/conversations/{$conversation->id}/messages")
            ->assertForbidden();
    }

    // 11. Non-member cannot send a message.
    public function test_a_non_member_cannot_send_a_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->actingAsUnprivileged($outsider)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'sneaky'])
            ->assertForbidden();
    }

    // 12. Reply inside the same conversation succeeds.
    public function test_a_reply_within_the_same_conversation_succeeds(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $original = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'Original'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($target)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'body' => 'Replying',
                'reply_to_message_id' => $original,
            ])
            ->assertCreated()
            ->assertJsonPath('data.reply_to_message_id', $original);
    }

    // 13. Cross-conversation reply rejected.
    public function test_a_reply_to_a_message_in_a_different_conversation_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $other = User::factory()->create(['company_id' => $company->id]);

        $conversationA = $this->directConversation($company, $actor, $target);
        $conversationB = $this->directConversation($company, $actor, $other);

        $messageInA = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversationA->id}/messages", ['body' => 'In A'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversationB->id}/messages", [
                'body' => 'In B, replying to A',
                'reply_to_message_id' => $messageInA,
            ])
            ->assertStatus(422);
    }

    // 14. Mention validation.
    public function test_mentioning_a_non_participant_is_rejected(): void
    {
        $company = Company::factory()->create();
        $owner = $this->employee($company);
        $member = User::factory()->create(['company_id' => $company->id]);
        $outsider = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->groupConversation($company, $owner, [$member]);

        $this->actingAsUnprivileged($owner)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'body' => 'Hey @outsider',
                'mentioned_user_ids' => [$outsider->id],
            ])
            ->assertStatus(422);
    }

    public function test_mentioning_an_active_participant_succeeds(): void
    {
        $company = Company::factory()->create();
        $owner = $this->employee($company);
        $member = User::factory()->create(['company_id' => $company->id, 'name' => 'Mentioned Member']);
        $conversation = $this->groupConversation($company, $owner, [$member]);

        $this->actingAsUnprivileged($owner)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'body' => 'Hey @member',
                'mentioned_user_ids' => [$member->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.mentioned_user_ids.0', $member->id)
            // Task 5 — mentions resolve to a name too (brief: mentions render from the
            // conversation's own authorized participants, never a global user search).
            ->assertJsonPath('data.mentioned_users.0.name', 'Mentioned Member');
    }

    // 15. Read/unread update.
    public function test_marking_a_conversation_read_updates_the_cursor(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($target)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'Ping'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/conversations/{$conversation->id}/read", ['last_read_message_id' => $messageId])
            ->assertOk()
            ->assertJsonPath('data.last_read_message_id', $messageId);

        $this->assertDatabaseHas('collaboration_conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $actor->id,
            'last_read_message_id' => $messageId,
        ]);
    }

    // 16. Unread count derivation.
    public function test_unread_count_reflects_messages_sent_since_last_read(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        foreach (range(1, 3) as $i) {
            $this->actingAsUnprivileged($target)
                ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => "msg {$i}"])
                ->assertCreated();
        }

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/conversations')
            ->assertOk()
            ->assertJsonFragment(['id' => $conversation->id, 'unread_count' => 3]);

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/conversations/{$conversation->id}/read")
            ->assertOk();

        $this->actingAsUnprivileged($target)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'one more'])
            ->assertCreated();

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/conversations')
            ->assertOk()
            ->assertJsonFragment(['id' => $conversation->id, 'unread_count' => 1]);
    }

    // 19. Message edit/delete unavailable (ADR-044 §1.5 — deferred from V1, not merely unrouted).
    public function test_message_controller_exposes_no_edit_or_delete_capability(): void
    {
        self::assertFalse(method_exists(MessageController::class, 'update'), 'Message editing must not exist in V1.');
        self::assertFalse(method_exists(MessageController::class, 'destroy'), 'Message deletion must not exist in V1.');
    }
}
