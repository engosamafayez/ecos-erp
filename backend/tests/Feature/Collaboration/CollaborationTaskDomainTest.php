<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-INTERNAL-TASKS-004.
 * Covers brief scenarios 1-10 (task domain / lifecycle).
 */
final class CollaborationTaskDomainTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-task-creator', ['collaboration.tasks.create']);
    }

    // 1. Authorized employee creates task.
    public function test_an_authorized_employee_can_create_a_task(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $actor->forceFill(['name' => 'Task Creator'])->save();
        $assignee = User::factory()->create(['company_id' => $company->id, 'name' => 'Task Assignee']);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', [
                'title' => 'Restock warehouse A',
                'assignee_user_id' => $assignee->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Restock warehouse A')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.assignee_user_id', $assignee->id)
            // Task 5 — creator/assignee names resolve inline, not bare ids.
            ->assertJsonPath('data.creator_name', 'Task Creator')
            ->assertJsonPath('data.assignee_name', 'Task Assignee');
    }

    // 2. Unauthorized task create rejected.
    public function test_an_employee_without_the_create_permission_is_denied(): void
    {
        $company = Company::factory()->create();
        $actor = $this->userWithGrants($company, 'test-collab-no-task-perm', []);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'Should fail'])
            ->assertForbidden();
    }

    // 3. Canonical assignee is an existing User (defaults to self-assign, architecture report §12).
    public function test_omitting_assignee_defaults_to_self_assignment(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'My own reminder'])
            ->assertCreated()
            ->assertJsonPath('data.assignee_user_id', $actor->id)
            ->assertJsonPath('data.creator_user_id', $actor->id);
    }

    // 4. Foreign-company assignee rejected.
    public function test_a_cross_company_assignee_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actor = $this->employee($companyA);
        $foreignUser = User::factory()->create(['company_id' => $companyB->id]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $foreignUser->id])
            ->assertNotFound();
    }

    // 5. Valid priority stored.
    public function test_priority_is_stored_as_submitted(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'Urgent fix', 'priority' => 'urgent'])
            ->assertCreated()
            ->assertJsonPath('data.priority', 'urgent');
    }

    public function test_an_invalid_priority_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'priority' => 'sky-high'])
            ->assertUnprocessable();
    }

    // 6. due_at stored correctly.
    public function test_due_at_is_stored_and_returned(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $dueAt = now()->addDays(3)->startOfMinute();

        $response = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'Deliver report', 'due_at' => $dueAt->toIso8601String()])
            ->assertCreated();

        self::assertSame($dueAt->toIso8601String(), $response->json('data.due_at'));
        self::assertFalse($response->json('data.is_overdue'));
    }

    public function test_a_task_with_no_due_date_has_no_deadline(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'Whenever'])
            ->assertCreated()
            ->assertJsonPath('data.due_at', null)
            ->assertJsonPath('data.is_overdue', false);
    }

    // 7. Invalid status transition rejected.
    public function test_todo_cannot_transition_directly_to_done(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x'])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'done'])
            ->assertStatus(422);
    }

    // 8. TODO -> IN_PROGRESS succeeds.
    public function test_todo_to_in_progress_succeeds(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x'])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    // 9. IN_PROGRESS -> DONE succeeds.
    public function test_in_progress_to_done_succeeds_and_stamps_completed_at(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x'])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'in_progress'])->assertOk();

        $response = $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        self::assertNotNull($response->json('data.completed_at'));
    }

    // Reopen (DONE -> IN_PROGRESS) is approved V1 behaviour — architecture
    // report §11 — exercised explicitly since the brief asked for the exact
    // decision to be reported, not assumed either way.
    public function test_a_done_task_can_be_reopened_to_in_progress(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');
        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'in_progress'])->assertOk();
        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'done'])->assertOk();

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.completed_at', null);
    }

    // 10. Cancellation follows the approved lifecycle (from TODO or IN_PROGRESS; not from DONE).
    public function test_cancellation_is_allowed_from_todo_and_in_progress_but_not_from_done(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $fromTodo = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'a'])->assertCreated()->json('data.id');
        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$fromTodo}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $fromInProgress = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'b'])->assertCreated()->json('data.id');
        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$fromInProgress}/status", ['status' => 'in_progress'])->assertOk();
        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$fromInProgress}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $fromDone = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'c'])->assertCreated()->json('data.id');
        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$fromDone}/status", ['status' => 'in_progress'])->assertOk();
        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$fromDone}/status", ['status' => 'done'])->assertOk();
        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$fromDone}/status", ['status' => 'cancelled'])
            ->assertStatus(422);
    }
}
