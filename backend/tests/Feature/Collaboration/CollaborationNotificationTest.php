<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-MEDIA-VOICE-REALTIME-NOTIFICATIONS-SEARCH-003.
 * Covers brief scenarios 23-26 (notifications) — stock Laravel
 * Notification classes on the `database` channel, the vanilla
 * `notifications` table. No "Enterprise Notification Platform" is invoked;
 * none exists (verified directly against source, see engineering report §17).
 */
final class CollaborationNotificationTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-employee', ['collaboration.conversations.create']);
    }

    // 23. Direct-message notification produced for the authorized recipient.
    public function test_the_recipient_gets_a_new_message_notification(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'hello there'])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $target->id,
            'type' => \Modules\Collaboration\Application\Notifications\NewMessageNotification::class,
        ]);

        // The sender never gets a notification about their own message.
        self::assertSame(
            0,
            DatabaseNotification::query()->where('notifiable_id', $actor->id)->count(),
        );
    }

    // 24. Mention notification produced.
    public function test_a_mentioned_participant_gets_a_mention_notification_not_a_generic_one(): void
    {
        $company = Company::factory()->create();
        $owner = $this->employee($company);
        $memberA = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->groupConversation($company, $owner, [$memberA]);

        $this->actingAsUnprivileged($owner)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'body' => 'Hey @memberA',
                'mentioned_user_ids' => [$memberA->id],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $memberA->id,
            'type' => \Modules\Collaboration\Application\Notifications\MentionedNotification::class,
        ]);

        // Exactly one notification for this event — not also the generic one (brief §16).
        self::assertSame(1, DatabaseNotification::query()->where('notifiable_id', $memberA->id)->count());
    }

    // 25. Unauthorized/foreign notification not produced.
    public function test_a_non_participant_receives_no_notification_for_a_conversation_they_are_not_in(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'private stuff'])
            ->assertCreated();

        self::assertSame(0, DatabaseNotification::query()->where('notifiable_id', $outsider->id)->count());
    }

    // 26. Broadcast path does not accidentally duplicate notification persistence.
    public function test_one_message_produces_exactly_one_notification_row_per_recipient(): void
    {
        $company = Company::factory()->create();
        $owner = $this->employee($company);
        $memberA = User::factory()->create(['company_id' => $company->id]);
        $memberB = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->groupConversation($company, $owner, [$memberA, $memberB]);

        $this->actingAsUnprivileged($owner)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'team update'])
            ->assertCreated();

        // Broadcasting (MessageBroadcast) writes nothing to the notifications
        // table at all — it is a separate Laravel subsystem from
        // Notification::send(); this asserts the persisted count directly
        // rather than only reasoning about it from the source.
        self::assertSame(1, DatabaseNotification::query()->where('notifiable_id', $memberA->id)->count());
        self::assertSame(1, DatabaseNotification::query()->where('notifiable_id', $memberB->id)->count());
        self::assertSame(0, DatabaseNotification::query()->where('notifiable_id', $owner->id)->count());
    }
}
