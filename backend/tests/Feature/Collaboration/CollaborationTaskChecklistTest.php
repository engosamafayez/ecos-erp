<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/** §29 — Checklist: create item, edit, complete, progress calculation, authorization. */
final class CollaborationTaskChecklistTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-checklist', ['collaboration.tasks.create']);
    }

    private function taskWithChecklist(User $actor): array
    {
        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');
        $checklistId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/tasks/{$taskId}/checklists", ['title' => 'Steps'])
            ->json('data.id');

        return [$taskId, $checklistId];
    }

    public function test_items_can_be_added_edited_and_completed(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        [$taskId, $checklistId] = $this->taskWithChecklist($actor);

        $itemId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/tasks/{$taskId}/checklists/{$checklistId}/items", ['title' => 'Step one'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/checklists/{$checklistId}/items/{$itemId}", ['title' => 'Step one (edited)'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Step one (edited)');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/checklists/{$checklistId}/items/{$itemId}", ['is_completed' => true])
            ->assertOk()
            ->assertJsonPath('data.is_completed', true);
    }

    public function test_checklist_progress_is_reflected_on_the_task(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        [$taskId, $checklistId] = $this->taskWithChecklist($actor);

        $item1 = $this->actingAsUnprivileged($actor)->postJson("/api/collaboration/tasks/{$taskId}/checklists/{$checklistId}/items", ['title' => '1'])->json('data.id');
        $this->actingAsUnprivileged($actor)->postJson("/api/collaboration/tasks/{$taskId}/checklists/{$checklistId}/items", ['title' => '2'])->assertCreated();

        $this->actingAsUnprivileged($actor)->patchJson("/api/collaboration/tasks/{$taskId}/checklists/{$checklistId}/items/{$item1}", ['is_completed' => true])->assertOk();

        $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('data.checklist_progress.completed', 1)
            ->assertJsonPath('data.checklist_progress.total', 2);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/tasks?scope=mine')
            ->assertOk()
            ->assertJsonPath('data.0.checklist_progress.completed', 1)
            ->assertJsonPath('data.0.checklist_progress.total', 2);
    }

    public function test_an_item_can_be_deleted(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        [$taskId, $checklistId] = $this->taskWithChecklist($actor);
        $itemId = $this->actingAsUnprivileged($actor)->postJson("/api/collaboration/tasks/{$taskId}/checklists/{$checklistId}/items", ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->deleteJson("/api/collaboration/tasks/{$taskId}/checklists/{$checklistId}/items/{$itemId}")
            ->assertOk();

        $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/tasks/{$taskId}")
            ->assertJsonPath('data.checklist_progress.total', 0);
    }

    public function test_a_stranger_cannot_manage_the_checklist(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $stranger = $this->employee($company);
        [$taskId, $checklistId] = $this->taskWithChecklist($creator);

        $this->actingAsUnprivileged($stranger)
            ->postJson("/api/collaboration/tasks/{$taskId}/checklists/{$checklistId}/items", ['title' => 'nope'])
            ->assertForbidden();
    }
}
