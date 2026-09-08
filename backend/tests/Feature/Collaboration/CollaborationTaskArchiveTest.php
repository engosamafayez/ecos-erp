<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-INTERNAL-COLLABORATION-FINAL-USER-REVIEW-REMEDIATION-010 §5 —
 * Task archive/restore: creator-only, default-excluded from the normal
 * list, restore always lands at a valid position.
 */
final class CollaborationTaskArchiveTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-archive', ['collaboration.tasks.create']);
    }

    public function test_the_creator_can_archive_and_restore_a_task(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', fn ($v) => $v !== null);

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/restore")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);
    }

    public function test_a_non_creator_assignee_cannot_archive_the_task(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $assignee = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($creator)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $assignee->id])
            ->json('data.id');

        $this->actingAsUnprivileged($assignee)
            ->patchJson("/api/collaboration/tasks/{$taskId}/archive")
            ->assertForbidden();
    }

    public function test_archived_tasks_are_excluded_by_default_and_included_with_the_archived_filter(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'Archive me'])->json('data.id');
        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$taskId}/archive")->assertOk();

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/tasks?scope=mine')
            ->assertOk()
            ->assertJsonMissing(['id' => $taskId]);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/tasks?scope=mine&archived=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $taskId);
    }

    public function test_restoring_a_task_whose_list_was_archived_re_homes_it_to_a_default_list(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');
        $originalListId = $this->actingAsUnprivileged($actor)->getJson("/api/collaboration/tasks/{$taskId}")->json('data.task_list_id');

        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$taskId}/archive")->assertOk();
        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/task-lists/{$originalListId}/archive")->assertOk();

        $restored = $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/restore")
            ->assertOk()
            ->json('data');

        self::assertNull($restored['archived_at']);
        self::assertNotSame($originalListId, $restored['task_list_id'], 'restore must re-home the task off an archived list');
    }
}
