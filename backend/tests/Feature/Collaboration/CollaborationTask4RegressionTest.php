<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\IAM\Domain\Models\Permission;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-INTERNAL-TASKS-004.
 * Covers brief scenarios 44-48 (regression). Tasks 1-3's full suites
 * (13 files) are unmodified — see each task's own report — these add
 * targeted cases at exactly the points Task 4 touched shared code
 * (AttachOperationalContextAction, DriverMessagingAuthorizer, the
 * permission catalog), not a restatement of prior suites.
 */
final class CollaborationTask4RegressionTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private const TASK2_PERMISSIONS = [
        'collaboration.conversations.create',
        'collaboration.conversations.message_drivers',
        'collaboration.groups.create',
    ];

    private const TASK4_PERMISSIONS = [
        'collaboration.tasks.create',
        'collaboration.tasks.assign_drivers',
    ];

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-employee', [
            'collaboration.conversations.create',
            'collaboration.tasks.create',
        ]);
    }

    // 44. Existing Collaboration conversations remain unaffected.
    public function test_direct_conversation_creation_still_works_unchanged(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $target->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'direct');
    }

    // 45. Message media/voice behavior remains unaffected.
    public function test_voice_message_send_still_works_unchanged(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'voice');
    }

    // 46. Source Message->Task linkage does not alter message search/read state.
    public function test_converting_a_message_to_a_task_does_not_change_its_read_state_or_searchability(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'searchable-task-source-keyword'])
            ->assertCreated()->json('data.id');

        // Unread for target before the task is created...
        $this->actingAsUnprivileged($target)
            ->getJson('/api/collaboration/conversations')
            ->assertOk()
            ->assertJsonFragment(['id' => $conversation->id, 'unread_count' => 1]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'source_message_id' => $messageId])
            ->assertCreated();

        // ...and unchanged after — task creation is not a read event.
        $this->actingAsUnprivileged($target)
            ->getJson('/api/collaboration/conversations')
            ->assertOk()
            ->assertJsonFragment(['id' => $conversation->id, 'unread_count' => 1]);

        // The message remains searchable by its own content, unaffected.
        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/messages?q=searchable-task-source-keyword')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // 47. No automatic role grant from the Task 4 permission migration.
    public function test_task_4_permission_migration_introduces_no_role_permission_grants(): void
    {
        foreach (self::TASK4_PERMISSIONS as $name) {
            self::assertTrue(Permission::query()->where('name', $name)->exists(), "'{$name}' must be persisted by the migration.");
        }

        self::assertSame(5, Permission::query()->where('module', 'collaboration')->count(), '3 from Task 2 + 2 new from Task 4, no more.');

        $collaborationPermissionIds = Permission::query()->where('module', 'collaboration')->pluck('id');
        $grantCount = DB::table('role_permissions')->whereIn('permission_id', $collaborationPermissionIds)->count();

        self::assertSame(0, $grantCount);
    }

    public function test_task_2_permission_tokens_are_still_unaffected(): void
    {
        foreach (self::TASK2_PERMISSIONS as $name) {
            self::assertTrue(Permission::query()->where('name', $name)->exists());
        }
    }

    // 48. Tenant isolation remains enforced (task-specific instance of the
    // same rule already proven for conversations/messages in Tasks 2-3).
    public function test_a_task_in_another_company_is_not_reachable(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actorA = $this->employee($companyA);

        $taskId = $this->actingAsUnprivileged($actorA)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');

        $actorB = $this->employee($companyB);

        $this->actingAsUnprivileged($actorB)
            ->getJson("/api/collaboration/tasks/{$taskId}")
            ->assertForbidden();
    }
}
