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
 * Covers brief scenarios 32-37 (operational context — the 4 ADR-044-approved
 * V1 types: Order, Distribution Group, Trip, Driver).
 */
final class CollaborationTaskContextTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-task-creator', ['collaboration.tasks.create']);
    }

    private function taskId(User $actor): string
    {
        return $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x'])
            ->assertCreated()
            ->json('data.id');
    }

    /** @return array{0: Company, 1: User, 2: string} */
    private function taskWithOwner(): array
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        return [$company, $actor, $this->taskId($actor)];
    }

    // 32. Order context reference.
    public function test_an_order_context_link_can_be_attached_to_a_task(): void
    {
        [, $actor, $taskId] = $this->taskWithOwner();

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/context-links', ['attached_to_type' => 'task', 'attached_to_id' => $taskId, 'context_type' => 'order', 'context_id' => 'ORD-1001'])
            ->assertCreated()
            ->assertJsonPath('data.context_type', 'order')
            ->assertJsonPath('data.context_id', 'ORD-1001');
    }

    // 33. Distribution Group context reference.
    public function test_a_distribution_group_context_link_can_be_attached_to_a_task(): void
    {
        [, $actor, $taskId] = $this->taskWithOwner();

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/context-links', ['attached_to_type' => 'task', 'attached_to_id' => $taskId, 'context_type' => 'distribution_group', 'context_id' => 'DG-55'])
            ->assertCreated()
            ->assertJsonPath('data.context_type', 'distribution_group');
    }

    // 34. Trip context reference.
    public function test_a_trip_context_link_can_be_attached_to_a_task(): void
    {
        [, $actor, $taskId] = $this->taskWithOwner();

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/context-links', ['attached_to_type' => 'task', 'attached_to_id' => $taskId, 'context_type' => 'trip', 'context_id' => 'TRIP-9'])
            ->assertCreated()
            ->assertJsonPath('data.context_type', 'trip');
    }

    // 35. Driver context reference.
    public function test_a_driver_context_link_can_be_attached_to_a_task(): void
    {
        [, $actor, $taskId] = $this->taskWithOwner();

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/context-links', ['attached_to_type' => 'task', 'attached_to_id' => $taskId, 'context_type' => 'driver', 'context_id' => '123'])
            ->assertCreated()
            ->assertJsonPath('data.context_type', 'driver');
    }

    // 36. Foreign-company context rejected — an employee in another company
    // cannot attach a context link to a task they have no ownership of,
    // exactly the same ownership/tenant boundary as every other task action.
    public function test_a_user_in_another_company_cannot_attach_context_to_the_task(): void
    {
        [, , $taskId] = $this->taskWithOwner();

        $companyB = Company::factory()->create();
        $outsider = $this->employee($companyB);

        $this->actingAsUnprivileged($outsider)
            ->postJson('/api/collaboration/context-links', ['attached_to_type' => 'task', 'attached_to_id' => $taskId, 'context_type' => 'order', 'context_id' => 'ORD-1'])
            ->assertForbidden();
    }

    // 37. Unsupported context type rejected — only the ADR-044-approved V1
    // set is accepted; nothing else, without architecture approval (brief §19).
    public function test_an_unsupported_context_type_is_rejected_for_a_task(): void
    {
        [, $actor, $taskId] = $this->taskWithOwner();

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/context-links', ['attached_to_type' => 'task', 'attached_to_id' => $taskId, 'context_type' => 'purchase_order', 'context_id' => 'PO-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context_type']);
    }
}
