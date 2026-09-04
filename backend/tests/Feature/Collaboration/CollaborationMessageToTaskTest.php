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
 * TASK-ECOS-COLLABORATION-INTERNAL-TASKS-004.
 * Covers brief scenarios 16-20 (Message -> Create Task).
 */
final class CollaborationMessageToTaskTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-task-creator', ['collaboration.tasks.create']);
    }

    // 16. Authorized message creates task.
    public function test_a_participant_can_create_a_task_from_a_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'Please restock shelf 4 by Friday'])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', [
                'title' => 'Restock shelf 4',
                'source_message_id' => $messageId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.source_message_id', $messageId)
            ->assertJsonPath('data.source_conversation_id', $conversation->id);
    }

    // 17. Durable source conversation/message link persisted (survives as a
    // snapshot, per architecture report §12 — verified against the DB row
    // directly, not only the API response).
    public function test_the_source_message_snapshot_is_persisted_durably(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'the exact original wording'])
            ->assertCreated()->json('data.id');

        $taskId = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'source_message_id' => $messageId])
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('collaboration_internal_tasks', [
            'id' => $taskId,
            'source_message_id' => $messageId,
            'source_conversation_id' => $conversation->id,
            'source_message_snapshot' => 'the exact original wording',
        ]);
    }

    // 18. Source message remains immutable — task creation touches nothing on it.
    public function test_creating_a_task_from_a_message_does_not_alter_the_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'original'])
            ->assertCreated()->json('data.id');

        $before = Message::query()->findOrFail($messageId)->getAttributes();

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'source_message_id' => $messageId])
            ->assertCreated();

        $after = Message::query()->findOrFail($messageId)->getAttributes();

        self::assertSame($before['body'], $after['body']);
        self::assertSame($before['type'], $after['type']);
    }

    // 19. Cross-conversation/unauthorized source message rejected.
    public function test_creating_a_task_from_a_message_you_cannot_access_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'private'])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($outsider)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'source_message_id' => $messageId])
            ->assertForbidden();
    }

    // 20. Task access alone does not leak unauthorized source-message content —
    // an assignee who has access to the TASK but never had access to the
    // source CONVERSATION cannot use the task to reach the original message.
    public function test_a_task_assignee_without_source_conversation_access_cannot_read_the_original_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $participant = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $participant);
        $taskAssignee = $this->employee($company); // never a participant of $conversation

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'sensitive context'])
            ->assertCreated()->json('data.id');

        $taskId = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', [
                'title' => 'Follow up',
                'assignee_user_id' => $taskAssignee->id,
                'source_message_id' => $messageId,
            ])
            ->assertCreated()->json('data.id');

        // The assignee can see the task itself, and that it has a source
        // (the pointer), but NOT the source message's content — a second,
        // independent authorization check (brief §14), not merely having
        // task access.
        $this->actingAsUnprivileged($taskAssignee)
            ->getJson("/api/collaboration/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('data.source_message_id', $messageId)
            ->assertJsonPath('data.source_message_snapshot', null);

        // The task creator, who IS a source-conversation participant, sees it.
        $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('data.source_message_snapshot', 'sensitive context');

        // ...and the assignee cannot reach the live conversation/message either.
        $this->actingAsUnprivileged($taskAssignee)
            ->getJson("/api/collaboration/conversations/{$conversation->id}/messages")
            ->assertForbidden();

        $this->actingAsUnprivileged($taskAssignee)
            ->getJson("/api/collaboration/conversations/{$conversation->id}")
            ->assertForbidden();
    }
}
