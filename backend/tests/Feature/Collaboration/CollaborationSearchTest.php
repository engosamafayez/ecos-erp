<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-MEDIA-VOICE-REALTIME-NOTIFICATIONS-SEARCH-003.
 * Covers brief scenarios 27-32 (PostgreSQL full-text search). Messages are
 * created via the factory directly (not the send endpoint) — `body_tsv` is
 * a Postgres STORED generated column, computed by the database on every
 * insert regardless of which code path performed it, so this is a valid way
 * to set up fixtures, not a shortcut around the feature being tested.
 */
final class CollaborationSearchTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-employee', ['collaboration.conversations.create']);
    }

    // 27. Authorized text message found.
    public function test_a_participant_finds_a_matching_message_in_their_own_conversation(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $target->id,
            'body' => 'the warehouse shipment is delayed until tomorrow',
        ]);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/messages?q=shipment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['body' => 'the warehouse shipment is delayed until tomorrow']);
    }

    // 28. Unauthorized conversation message excluded.
    public function test_a_message_in_a_conversation_the_searcher_is_not_in_is_excluded(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $searcher = $this->employee($company);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $actor->id,
            'body' => 'confidential shipment details here',
        ]);

        $this->actingAsUnprivileged($searcher)
            ->getJson('/api/collaboration/search/messages?q=confidential')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // 29. Foreign-company message excluded.
    public function test_a_message_in_another_companys_conversation_is_excluded(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actorA = $this->employee($companyA);
        $targetA = User::factory()->create(['company_id' => $companyA->id]);
        $conversationA = $this->directConversation($companyA, $actorA, $targetA);

        Message::factory()->create([
            'conversation_id' => $conversationA->id,
            'sender_user_id' => $actorA->id,
            'body' => 'unique-crossborder-keyword-zzz shipment',
        ]);

        $searcherB = $this->employee($companyB);

        $this->actingAsUnprivileged($searcherB)
            ->getJson('/api/collaboration/search/messages?q=unique-crossborder-keyword-zzz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // 30. Search works across authorized direct AND group conversations.
    public function test_search_covers_both_direct_and_group_conversations(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $direct = $this->directConversation($company, $actor, $target);
        $group = $this->groupConversation($company, $actor, [$target]);

        Message::factory()->create(['conversation_id' => $direct->id, 'sender_user_id' => $target->id, 'body' => 'invoice reconciliation needed']);
        Message::factory()->create(['conversation_id' => $group->id, 'sender_user_id' => $target->id, 'body' => 'invoice reconciliation for group']);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/messages?q=reconciliation')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // In-conversation search (TASK-ECOS-INTERNAL-COLLABORATION-CHAT-FINAL-
    // IMPLEMENTATION-002, architecture report §19): an optional `conversation_id`
    // narrows to just that one conversation instead of every conversation the
    // caller participates in — the exact inverse of scenario 30 above.
    public function test_conversation_id_scopes_search_to_just_that_conversation(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversationA = $this->directConversation($company, $actor, $target);
        $conversationB = $this->groupConversation($company, $actor, [$target]);

        Message::factory()->create(['conversation_id' => $conversationA->id, 'sender_user_id' => $target->id, 'body' => 'budget review alpha']);
        Message::factory()->create(['conversation_id' => $conversationB->id, 'sender_user_id' => $target->id, 'body' => 'budget review beta']);

        $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/search/messages?q=budget&conversation_id={$conversationA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['body' => 'budget review alpha']);
    }

    // A caller who isn't a participant of the specifically-named conversation is
    // refused outright, never silently given zero results indistinguishable from
    // "no matches" (mirrors GetConversationMessagesAction's own refusal shape).
    public function test_conversation_id_search_is_forbidden_for_a_non_participant(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $actor->id, 'body' => 'budget review outsider test']);

        $this->actingAsUnprivileged($outsider)
            ->getJson("/api/collaboration/search/messages?q=budget&conversation_id={$conversation->id}")
            ->assertForbidden();
    }

    // 31. PostgreSQL FTS query/index path is actually used (not a LIKE scan) —
    // proven by exercising websearch_to_tsquery's real behavior: stemming
    // ("shipments" query matches a stored "shipment") and a non-match for an
    // unrelated word, which a naive LIKE '%shipments%' would get wrong.
    public function test_the_tsvector_query_stems_and_does_not_substring_match_unrelated_text(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $target->id, 'body' => 'the shipment arrived safely']);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/messages?q=shipments') // plural — stems to "shipment"
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/messages?q=unrelatedxyz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // 32. Media binary contents are not incorrectly searched — an image/file/
    // voice message has a null body, so it can only ever surface via a
    // caption-less text query, never via its (never-extracted) file bytes.
    public function test_a_media_message_with_no_text_body_is_not_returned_by_a_content_search(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $target->id,
            'type' => \Modules\Collaboration\Domain\Enums\MessageType::Image,
            'body' => null,
        ]);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/messages?q=photo')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
