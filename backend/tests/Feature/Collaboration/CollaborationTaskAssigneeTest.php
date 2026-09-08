<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-INTERNAL-COLLABORATION-FINAL-USER-REVIEW-REMEDIATION-010 §7 —
 * additional assignees: add/remove, no duplicate-with-primary, no
 * cross-company assignee, additional assignees gain view access and are
 * surfaced in "assigned to me".
 */
final class CollaborationTaskAssigneeTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-assignee', ['collaboration.tasks.create']);
    }

    public function test_the_actor_can_self_assign_and_self_unassign(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $actor = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($creator)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        // Not viewable before being assigned at all.
        $this->actingAsUnprivileged($actor)->getJson("/api/collaboration/tasks/{$taskId}")->assertForbidden();

        // Self-assign mirrors TaskPolicy::manageFollower's self-tier exactly
        // (§7 — "identical trust tier to manageFollower"): the actor must
        // already have SOME standing on the task (creator/assignee/follower)
        // before they can add themselves — a total stranger cannot, by
        // design, insert themselves into an arbitrary company task. The
        // creator grants that standing here via follower first.
        $this->actingAsUnprivileged($creator)->postJson("/api/collaboration/tasks/{$taskId}/followers", ['user_id' => $actor->id])->assertOk();

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/tasks/{$taskId}/assignees", ['user_id' => $actor->id])
            ->assertOk()
            ->assertJsonPath('data.additional_assignees.0.id', $actor->id);

        $this->actingAsUnprivileged($actor)->getJson("/api/collaboration/tasks/{$taskId}")->assertOk();

        $this->actingAsUnprivileged($actor)
            ->deleteJson("/api/collaboration/tasks/{$taskId}/assignees/{$actor->id}")
            ->assertOk();
    }

    public function test_the_creator_can_add_a_third_party_as_additional_assignee_who_then_gains_view_access(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $newAssignee = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($creator)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($newAssignee)->getJson("/api/collaboration/tasks/{$taskId}")->assertForbidden();

        $this->actingAsUnprivileged($creator)
            ->postJson("/api/collaboration/tasks/{$taskId}/assignees", ['user_id' => $newAssignee->id])
            ->assertOk();

        $this->actingAsUnprivileged($newAssignee)->getJson("/api/collaboration/tasks/{$taskId}")->assertOk();
    }

    public function test_a_non_creator_additional_assignee_cannot_add_someone_else(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $additionalAssignee = $this->employee($company);
        $thirdParty = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($creator)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($creator)->postJson("/api/collaboration/tasks/{$taskId}/assignees", ['user_id' => $additionalAssignee->id])->assertOk();

        $this->actingAsUnprivileged($additionalAssignee)
            ->postJson("/api/collaboration/tasks/{$taskId}/assignees", ['user_id' => $thirdParty->id])
            ->assertForbidden();
    }

    public function test_an_assignee_from_another_company_cannot_be_added(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $creator = $this->employee($companyA);
        $foreignUser = $this->employee($companyB);
        $taskId = $this->actingAsUnprivileged($creator)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($creator)
            ->postJson("/api/collaboration/tasks/{$taskId}/assignees", ['user_id' => $foreignUser->id])
            ->assertForbidden();
    }

    public function test_the_primary_assignee_cannot_also_be_added_as_an_additional_assignee(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($creator)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $creator->id])
            ->json('data.id');

        // Creator is already the primary assignee (self-assign default) — adding
        // them again as "additional" must be rejected, never silently duplicated.
        $this->actingAsUnprivileged($creator)
            ->postJson("/api/collaboration/tasks/{$taskId}/assignees", ['user_id' => $creator->id])
            ->assertStatus(422);
    }

    public function test_an_additional_assignee_sees_the_task_under_the_assigned_scope(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $otherPrimaryAssignee = $this->employee($company);
        $additionalAssignee = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($creator)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $otherPrimaryAssignee->id])
            ->json('data.id');

        $this->actingAsUnprivileged($creator)->postJson("/api/collaboration/tasks/{$taskId}/assignees", ['user_id' => $additionalAssignee->id])->assertOk();

        $this->actingAsUnprivileged($additionalAssignee)
            ->getJson('/api/collaboration/tasks?scope=assigned')
            ->assertOk()
            ->assertJsonPath('data.0.id', $taskId);
    }
}
