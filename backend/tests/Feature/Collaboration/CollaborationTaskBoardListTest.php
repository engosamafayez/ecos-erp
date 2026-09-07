<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Collaboration\Domain\Models\TaskBoardList;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-INTERNAL-COLLABORATION-TASKS-TRELLO-FINAL-CLOSURE-002 §29 —
 * Board Lists: create, rename, reorder, archive, company isolation.
 */
final class CollaborationTaskBoardListTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function boardManager(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-board-manager', ['collaboration.tasks.create']);
    }

    public function test_loading_the_board_for_the_first_time_seeds_four_default_lists(): void
    {
        $company = Company::factory()->create();
        $actor = $this->boardManager($company);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/task-lists')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.name', 'To Do')
            ->assertJsonPath('data.1.name', 'In Progress')
            ->assertJsonPath('data.2.name', 'Done')
            ->assertJsonPath('data.3.name', 'Cancelled');
    }

    public function test_loading_the_board_a_second_time_does_not_duplicate_lists(): void
    {
        $company = Company::factory()->create();
        $actor = $this->boardManager($company);

        $this->actingAsUnprivileged($actor)->getJson('/api/collaboration/task-lists')->assertOk();
        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/task-lists')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_an_authorized_user_can_create_a_custom_list(): void
    {
        $company = Company::factory()->create();
        $actor = $this->boardManager($company);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/task-lists', ['name' => 'Waiting on Client'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Waiting on Client');
    }

    public function test_a_user_without_the_create_permission_cannot_create_a_list(): void
    {
        $company = Company::factory()->create();
        $actor = $this->userWithGrants($company, 'test-collab-no-board-perm', []);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/task-lists', ['name' => 'Should fail'])
            ->assertForbidden();
    }

    public function test_a_list_can_be_renamed(): void
    {
        $company = Company::factory()->create();
        $actor = $this->boardManager($company);
        $listId = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/task-lists', ['name' => 'Original'])
            ->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/task-lists/{$listId}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_lists_can_be_reordered(): void
    {
        $company = Company::factory()->create();
        $actor = $this->boardManager($company);
        $lists = $this->actingAsUnprivileged($actor)->getJson('/api/collaboration/task-lists')->json('data');
        $reversed = array_reverse(array_column($lists, 'id'));

        $this->actingAsUnprivileged($actor)
            ->patchJson('/api/collaboration/task-lists/reorder', ['list_ids' => $reversed])
            ->assertOk();

        $reloaded = $this->actingAsUnprivileged($actor)->getJson('/api/collaboration/task-lists')->json('data');
        self::assertSame($reversed, array_column($reloaded, 'id'));
    }

    public function test_a_list_can_be_archived_and_restored(): void
    {
        $company = Company::factory()->create();
        $actor = $this->boardManager($company);
        $listId = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/task-lists', ['name' => 'Temp'])
            ->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/task-lists/{$listId}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', fn ($v) => $v !== null);

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/task-lists/{$listId}/restore")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);
    }

    public function test_renaming_another_companys_list_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actorA = $this->boardManager($companyA);
        $actorB = $this->boardManager($companyB);

        $foreignListId = $this->actingAsUnprivileged($actorB)
            ->postJson('/api/collaboration/task-lists', ['name' => 'Company B List'])
            ->json('data.id');

        $this->actingAsUnprivileged($actorA)
            ->patchJson("/api/collaboration/task-lists/{$foreignListId}", ['name' => 'Hijacked'])
            ->assertForbidden();

        self::assertSame('Company B List', TaskBoardList::query()->find($foreignListId)->name);
    }
}
