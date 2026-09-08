<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-INTERNAL-COLLABORATION-FINAL-USER-REVIEW-REMEDIATION-010 §13 —
 * reactions: add/switch/remove (one per user per message, upsert), no
 * cross-conversation access.
 */
final class CollaborationMessageReactionTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-reaction', ['collaboration.conversations.create']);
    }

    public function test_a_participant_can_react_and_the_reaction_is_visible_with_count_and_own_state(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $other = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $other);
        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'Hello'])
            ->json('data.id');

        $this->actingAsUnprivileged($other)
            ->postJson("/api/collaboration/messages/{$messageId}/reactions", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.emoji', '👍')
            ->assertJsonPath('data.reactions.0.count', 1)
            ->assertJsonPath('data.reactions.0.reacted_by_me', true);

        // The sender sees the same reaction, but "reacted_by_me" is false for them.
        $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.reactions.0.count', 1)
            ->assertJsonPath('data.0.reactions.0.reacted_by_me', false);
    }

    public function test_reacting_again_with_a_different_emoji_replaces_the_previous_one(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $other = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $other);
        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'Hello'])
            ->json('data.id');

        $this->actingAsUnprivileged($actor)->postJson("/api/collaboration/messages/{$messageId}/reactions", ['emoji' => '👍'])->assertOk();

        $response = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/messages/{$messageId}/reactions", ['emoji' => '❤️'])
            ->assertOk();

        // Exactly one reaction group remains — the switched-to emoji — never two.
        self::assertCount(1, $response->json('data.reactions'));
        $response->assertJsonPath('data.reactions.0.emoji', '❤️');
    }

    public function test_a_participant_can_remove_their_reaction(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $other = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $other);
        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'Hello'])
            ->json('data.id');

        $this->actingAsUnprivileged($actor)->postJson("/api/collaboration/messages/{$messageId}/reactions", ['emoji' => '👍'])->assertOk();

        $response = $this->actingAsUnprivileged($actor)
            ->deleteJson("/api/collaboration/messages/{$messageId}/reactions")
            ->assertOk();

        self::assertCount(0, $response->json('data.reactions'));
    }

    public function test_a_non_participant_cannot_react_to_a_message_in_a_conversation_they_do_not_belong_to(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $other = User::factory()->create(['company_id' => $company->id]);
        $outsider = $this->employee($company);
        $conversation = $this->directConversation($company, $actor, $other);
        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'Hello'])
            ->json('data.id');

        $this->actingAsUnprivileged($outsider)
            ->postJson("/api/collaboration/messages/{$messageId}/reactions", ['emoji' => '👍'])
            ->assertForbidden();
    }
}
