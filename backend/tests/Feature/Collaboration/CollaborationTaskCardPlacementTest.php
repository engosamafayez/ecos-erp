<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * §29 — Card Placement: move list, reorder, invalid cross-company move
 * rejected. Also proves moving a card never touches canonical TaskStatus
 * (brief §1/§8).
 */
final class CollaborationTaskCardPlacementTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-card-move', ['collaboration.tasks.create']);
    }

    /** @return array{0: string, 1: array<int, string>} task id, ordered list ids */
    private function createTaskAndLists(User $actor): array
    {
        $taskId = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'Movable card'])
            ->json('data.id');

        $listIds = array_column($this->actingAsUnprivileged($actor)->getJson('/api/collaboration/task-lists')->json('data'), 'id');

        return [$taskId, $listIds];
    }

    public function test_a_new_task_lands_in_the_first_board_list(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        [$taskId, $listIds] = $this->createTaskAndLists($actor);

        $task = InternalTask::query()->find($taskId);
        self::assertSame($listIds[0], $task->task_list_id);
        self::assertSame('todo', $task->status->value);
    }

    public function test_moving_a_card_to_another_list_does_not_change_canonical_status(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        [$taskId, $listIds] = $this->createTaskAndLists($actor);

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/move", ['task_list_id' => $listIds[2], 'position' => 0])
            ->assertOk()
            ->assertJsonPath('data.task_list_id', $listIds[2])
            ->assertJsonPath('data.status', 'todo');
    }

    public function test_moving_a_card_into_a_list_places_it_at_the_requested_position(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        [, $listIds] = $this->createTaskAndLists($actor);

        $second = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'B'])->json('data.id');
        $third = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'C'])->json('data.id');

        // B and C both auto-land in listIds[0]; move C to position 0, ahead of B.
        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$third}/move", ['task_list_id' => $listIds[0], 'position' => 0])
            ->assertOk();

        $reloadedC = InternalTask::query()->find($third);
        $reloadedB = InternalTask::query()->find($second);
        self::assertLessThan($reloadedB->board_position, $reloadedC->board_position);
    }

    public function test_moving_a_card_into_another_companys_list_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actorA = $this->employee($companyA);
        $actorB = $this->employee($companyB);

        $taskId = $this->actingAsUnprivileged($actorA)->postJson('/api/collaboration/tasks', ['title' => 'A task'])->json('data.id');
        $foreignListId = $this->actingAsUnprivileged($actorB)->getJson('/api/collaboration/task-lists')->json('data.0.id');

        $this->actingAsUnprivileged($actorA)
            ->patchJson("/api/collaboration/tasks/{$taskId}/move", ['task_list_id' => $foreignListId, 'position' => 0])
            ->assertForbidden();
    }

    public function test_a_user_with_no_relationship_to_the_task_cannot_move_it(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $stranger = $this->employee($company);
        [$taskId, $listIds] = $this->createTaskAndLists($creator);

        $this->actingAsUnprivileged($stranger)
            ->patchJson("/api/collaboration/tasks/{$taskId}/move", ['task_list_id' => $listIds[1], 'position' => 0])
            ->assertForbidden();
    }
}
