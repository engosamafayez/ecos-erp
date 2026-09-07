<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/** §29 — Followers: follow/unfollow, no cross-company follower. */
final class CollaborationTaskFollowerTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-follower', ['collaboration.tasks.create']);
    }

    public function test_the_creator_can_self_follow_and_self_unfollow(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/tasks/{$taskId}/followers")
            ->assertOk()
            ->assertJsonPath('data.followers.0.user_id', $actor->id);

        $this->actingAsUnprivileged($actor)
            ->deleteJson("/api/collaboration/tasks/{$taskId}/followers/{$actor->id}")
            ->assertOk();
    }

    public function test_the_creator_can_add_a_third_party_as_a_follower_who_then_gains_view_access(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $watcher = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($creator)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        // Before being added, the watcher has no access at all.
        $this->actingAsUnprivileged($watcher)->getJson("/api/collaboration/tasks/{$taskId}")->assertForbidden();

        $this->actingAsUnprivileged($creator)
            ->postJson("/api/collaboration/tasks/{$taskId}/followers", ['user_id' => $watcher->id])
            ->assertOk();

        $this->actingAsUnprivileged($watcher)->getJson("/api/collaboration/tasks/{$taskId}")->assertOk();
    }

    public function test_a_non_creator_follower_cannot_add_someone_else_as_a_follower(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $follower = $this->employee($company);
        $thirdParty = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($creator)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($creator)->postJson("/api/collaboration/tasks/{$taskId}/followers", ['user_id' => $follower->id])->assertOk();

        $this->actingAsUnprivileged($follower)
            ->postJson("/api/collaboration/tasks/{$taskId}/followers", ['user_id' => $thirdParty->id])
            ->assertForbidden();
    }

    public function test_a_follower_from_another_company_cannot_be_added(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $creator = $this->employee($companyA);
        $foreignUser = $this->employee($companyB);
        $taskId = $this->actingAsUnprivileged($creator)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');

        $this->actingAsUnprivileged($creator)
            ->postJson("/api/collaboration/tasks/{$taskId}/followers", ['user_id' => $foreignUser->id])
            ->assertForbidden();
    }

    public function test_followers_count_is_visible_on_the_task(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->json('data.id');
        $this->actingAsUnprivileged($actor)->postJson("/api/collaboration/tasks/{$taskId}/followers")->assertOk();

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/tasks?scope=mine')
            ->assertOk()
            ->assertJsonPath('data.0.followers_count', 1);
    }
}
