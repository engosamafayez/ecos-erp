<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Modules\Collaboration\Application\Events\ConversationReadStateBroadcast;
use Modules\Collaboration\Application\Events\MessageBroadcast;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-MEDIA-VOICE-REALTIME-NOTIFICATIONS-SEARCH-003.
 * Covers brief scenarios 18-22 (near-realtime + polling fallback).
 *
 * These test the SOURCE contract (event is dispatched with the right,
 * minimal payload; the channel-auth callback enforces participation) using
 * Laravel's own Event::fake() and the auto-registered /broadcasting/auth
 * endpoint — neither requires an actual Reverb server or connection, so
 * they run identically whichever broadcast driver is configured. They do
 * NOT prove a real Reverb server delivers frames over a socket — that is
 * exactly the runtime verification this task defers (see the engineering
 * report's Reverb capability findings).
 */
final class CollaborationRealtimeTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-employee', ['collaboration.conversations.create']);
    }

    // 18. Message-created broadcast event emitted.
    public function test_sending_a_message_dispatches_the_broadcast_event(): void
    {
        Event::fake([MessageBroadcast::class]);

        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'hello'])
            ->assertCreated()
            ->json('data.id');

        Event::assertDispatched(MessageBroadcast::class, fn (MessageBroadcast $event): bool => $event->message->id === $messageId);
    }

    // 19. Unauthorized user cannot subscribe/access the conversation channel.
    public function test_a_non_participant_is_refused_the_conversation_broadcast_channel(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->actingAsUnprivileged($outsider)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-collaboration.conversation.{$conversation->id}",
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden();
    }

    public function test_a_participant_is_authorized_for_the_conversation_broadcast_channel(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-collaboration.conversation.{$conversation->id}",
                'socket_id' => '1234.5678',
            ])
            ->assertOk();
    }

    // 20. Broadcast payload contains no raw private storage path.
    public function test_the_message_broadcast_payload_never_carries_a_storage_path(): void
    {
        $message = Message::factory()->make(['type' => \Modules\Collaboration\Domain\Enums\MessageType::Image, 'body' => null]);
        $message->id = (string) \Illuminate\Support\Str::uuid();

        $payload = (new MessageBroadcast($message))->broadcastWith();

        self::assertArrayNotHasKey('file_path', $payload);
        self::assertArrayNotHasKey('document_id', $payload);
        $encoded = json_encode($payload);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('documents/', $encoded);
        self::assertTrue($payload['has_attachment']);
    }

    // 21. Read/unread state event behaves correctly.
    public function test_marking_a_conversation_read_dispatches_the_read_state_event(): void
    {
        Event::fake([ConversationReadStateBroadcast::class]);

        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/conversations/{$conversation->id}/read")
            ->assertOk();

        Event::assertDispatched(
            ConversationReadStateBroadcast::class,
            fn (ConversationReadStateBroadcast $event): bool => $event->conversationId === $conversation->id && $event->userId === $actor->id,
        );
    }

    // 22. Polling fallback retrieves canonical incremental updates.
    public function test_polling_with_after_message_id_returns_only_newer_messages_in_order(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $first = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'first'])
            ->assertCreated()
            ->json('data.id');

        $second = $this->actingAsUnprivileged($target)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'second'])
            ->assertCreated()
            ->json('data.id');

        $third = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'third'])
            ->assertCreated()
            ->json('data.id');

        $response = $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/conversations/{$conversation->id}/messages?after_message_id={$first}")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        self::assertSame([$second, $third], $ids, 'Polling must return only messages after the cursor, oldest-first.');
    }
}
