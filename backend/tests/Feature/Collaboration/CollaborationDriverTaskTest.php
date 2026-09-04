<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-INTERNAL-TASKS-004 — ADR-044 §1.10 (CTO lock:
 * driver assigned-task capability is IN V1). Covers brief scenarios 26-31.
 * Every assertion here goes through the exact same TaskController/
 * TaskStatusController/OperationalContextLinkController routes an employee
 * assignee uses — nothing branches on participant type (brief §22 of the
 * Task 3 continuation's spirit, restated for tasks).
 */
final class CollaborationDriverTaskTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function dispatcher(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-task-dispatcher', [
            'collaboration.tasks.create',
            'collaboration.tasks.assign_drivers',
        ]);
    }

    // 26. Driver sees a task assigned to themselves.
    public function test_a_driver_can_see_a_task_assigned_to_them(): void
    {
        $company = Company::factory()->create();
        $dispatcher = $this->dispatcher($company);
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $taskId = $this->actingAsUnprivileged($dispatcher)
            ->postJson('/api/collaboration/tasks', ['title' => 'Deliver package #42', 'assignee_user_id' => $driverUser->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($driverUser)
            ->getJson("/api/collaboration/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Deliver package #42');

        $this->actingAsUnprivileged($driverUser)
            ->getJson('/api/collaboration/tasks?scope=assigned')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // 27. Driver cannot see another driver's unrelated task.
    public function test_a_driver_cannot_see_a_task_assigned_to_another_driver(): void
    {
        $company = Company::factory()->create();
        $dispatcher = $this->dispatcher($company);
        $driverA = User::factory()->create(['company_id' => $company->id]);
        $driverB = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverA);
        $this->makeDriver($company, $driverB);

        $taskId = $this->actingAsUnprivileged($dispatcher)
            ->postJson('/api/collaboration/tasks', ['title' => 'For driver A only', 'assignee_user_id' => $driverA->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($driverB)
            ->getJson("/api/collaboration/tasks/{$taskId}")
            ->assertForbidden();
    }

    // 28. Driver TODO -> IN_PROGRESS succeeds where allowed.
    public function test_a_driver_can_progress_their_own_task_to_in_progress(): void
    {
        $company = Company::factory()->create();
        $dispatcher = $this->dispatcher($company);
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $taskId = $this->actingAsUnprivileged($dispatcher)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $driverUser->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($driverUser)
            ->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    // 29. Driver IN_PROGRESS -> DONE succeeds where allowed.
    public function test_a_driver_can_complete_their_own_task(): void
    {
        $company = Company::factory()->create();
        $dispatcher = $this->dispatcher($company);
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $taskId = $this->actingAsUnprivileged($dispatcher)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $driverUser->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($driverUser)->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'in_progress'])->assertOk();

        $this->actingAsUnprivileged($driverUser)
            ->patchJson("/api/collaboration/tasks/{$taskId}/status", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    // 30. Driver cannot arbitrarily reassign a task (only the creator may).
    public function test_a_driver_assignee_cannot_reassign_their_own_task(): void
    {
        $company = Company::factory()->create();
        $dispatcher = $this->dispatcher($company);
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);
        $otherEmployee = User::factory()->create(['company_id' => $company->id]);

        $taskId = $this->actingAsUnprivileged($dispatcher)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $driverUser->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($driverUser)
            ->patchJson("/api/collaboration/tasks/{$taskId}/assignee", ['assignee_user_id' => $otherEmployee->id])
            ->assertForbidden();
    }

    // 31. Driver cannot access unauthorized linked operational context —
    // Collaboration itself never stores or exposes anything beyond the bare
    // type+id reference (brief §21), and even that reference is
    // ownership-gated the same as every other task sub-resource.
    public function test_a_driver_without_task_access_cannot_see_its_operational_context_links(): void
    {
        $company = Company::factory()->create();
        $dispatcher = $this->dispatcher($company);
        $driverA = User::factory()->create(['company_id' => $company->id]);
        $driverB = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverA);
        $this->makeDriver($company, $driverB);

        $taskId = $this->actingAsUnprivileged($dispatcher)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $driverA->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($dispatcher)
            ->postJson('/api/collaboration/context-links', [
                'attached_to_type' => 'task',
                'attached_to_id' => $taskId,
                'context_type' => 'trip',
                'context_id' => 'TRIP-77',
            ])
            ->assertCreated();

        // The rightful assignee sees only the bare reference — never any
        // denormalized Trip data (there is none to leak).
        $response = $this->actingAsUnprivileged($driverA)
            ->getJson("/api/collaboration/tasks/{$taskId}/context-links")
            ->assertOk();

        self::assertSame(['id', 'context_type', 'context_id', 'attached_to_type', 'attached_to_id'], array_keys($response->json('data.0')));

        // A driver with no relationship to this task is refused outright.
        $this->actingAsUnprivileged($driverB)
            ->getJson("/api/collaboration/tasks/{$taskId}/context-links")
            ->assertForbidden();
    }
}
