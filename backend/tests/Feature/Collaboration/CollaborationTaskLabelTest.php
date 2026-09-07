<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/** §29 — Labels: CRUD/attach/detach, company isolation. */
final class CollaborationTaskLabelTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-label-manager', ['collaboration.tasks.create']);
    }

    public function test_an_authorized_user_can_create_a_label(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/task-labels', ['name' => 'Urgent', 'color' => 'red'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Urgent')
            ->assertJsonPath('data.color', 'red');
    }

    public function test_an_invalid_color_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/task-labels', ['name' => 'Weird', 'color' => 'chartreuse'])
            ->assertUnprocessable();
    }

    public function test_labels_are_company_scoped(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->actingAsUnprivileged($this->employee($companyA))
            ->postJson('/api/collaboration/task-labels', ['name' => 'A-only', 'color' => 'blue'])
            ->assertCreated();

        $this->actingAsUnprivileged($this->employee($companyB))
            ->getJson('/api/collaboration/task-labels')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_label_can_be_attached_to_and_detached_from_a_task(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $labelId = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/task-labels', ['name' => 'Blocked', 'color' => 'orange'])
            ->json('data.id');
        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/tasks/{$taskId}/labels/{$labelId}")
            ->assertOk()
            ->assertJsonPath('data.labels.0.id', $labelId);

        $this->actingAsUnprivileged($actor)
            ->deleteJson("/api/collaboration/tasks/{$taskId}/labels/{$labelId}")
            ->assertOk();

        $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.labels');
    }

    public function test_attaching_another_companys_label_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actorA = $this->employee($companyA);
        $actorB = $this->employee($companyB);

        $foreignLabelId = $this->actingAsUnprivileged($actorB)
            ->postJson('/api/collaboration/task-labels', ['name' => 'Foreign', 'color' => 'green'])
            ->json('data.id');
        $taskId = $this->actingAsUnprivileged($actorA)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($actorA)
            ->postJson("/api/collaboration/tasks/{$taskId}/labels/{$foreignLabelId}")
            ->assertForbidden();
    }
}
