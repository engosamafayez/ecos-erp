<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Collaboration\Application\Notifications\TaskAssignedNotification;
use Modules\Collaboration\Application\Notifications\TaskStatusChangedNotification;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-INTERNAL-TASKS-004.
 * Covers brief scenarios 38-43 (activity / notifications) — reuses Task 3's
 * stock-Laravel `database`-channel notification infrastructure, no new
 * persistence mechanism.
 */
final class CollaborationTaskActivityNotificationTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-task-creator', ['collaboration.tasks.create']);
    }

    // 38. Task creation activity recorded.
    public function test_creating_a_task_records_a_created_activity_entry(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('collaboration_internal_task_activity', [
            'task_id' => $taskId,
            'actor_user_id' => $actor->id,
            'event_type' => 'created',
        ]);
    }

    // 39. Assignment activity recorded.
    public function test_reassigning_a_task_records_an_assigned_activity_entry(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $newAssignee = User::factory()->create(['company_id' => $company->id]);

        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/assignee", ['assignee_user_id' => $newAssignee->id])
            ->assertOk();

        $this->assertDatabaseHas('collaboration_internal_task_activity', [
            'task_id' => $taskId,
            'event_type' => 'assigned',
            'to_value' => (string) $newAssignee->id,
        ]);
    }

    // 40. Status-transition activity recorded.
    public function test_a_status_transition_records_an_activity_entry(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');
        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'in_progress'])->assertOk();

        $this->assertDatabaseHas('collaboration_internal_task_activity', [
            'task_id' => $taskId,
            'event_type' => 'status_changed',
            'from_value' => 'todo',
            'to_value' => 'in_progress',
        ]);
    }

    // 41. Assignment notification generated.
    public function test_the_assignee_receives_a_task_assigned_notification(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $assignee = User::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'Deliver report', 'assignee_user_id' => $assignee->id])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $assignee->id,
            'type' => TaskAssignedNotification::class,
        ]);

        // Never a self-notification for self-assigned tasks.
        self::assertSame(0, DatabaseNotification::query()->where('notifiable_id', $actor->id)->count());
    }

    // 42. Relevant task change notification generated (status change notifies the other party).
    public function test_the_creator_is_notified_when_the_assignee_changes_status(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $assignee = User::factory()->create(['company_id' => $company->id]);

        $taskId = $this->actingAsUnprivileged($creator)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $assignee->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($assignee)
            ->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'in_progress'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $creator->id,
            'type' => TaskStatusChangedNotification::class,
        ]);

        // The actor who made the change is never notified about their own action.
        self::assertSame(0, DatabaseNotification::query()->where('notifiable_id', $assignee->id)->count());
    }

    // 43. Unauthorized recipient does not receive a task notification.
    public function test_an_uninvolved_employee_receives_no_notification_for_a_task_they_are_not_part_of(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $assignee = User::factory()->create(['company_id' => $company->id]);
        $uninvolved = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $assignee->id])
            ->assertCreated();

        self::assertSame(0, DatabaseNotification::query()->where('notifiable_id', $uninvolved->id)->count());
    }
}
