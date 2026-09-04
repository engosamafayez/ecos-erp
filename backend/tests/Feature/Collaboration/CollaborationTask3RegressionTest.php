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
 * TASK-ECOS-COLLABORATION-MEDIA-VOICE-REALTIME-NOTIFICATIONS-SEARCH-003.
 * Covers brief scenarios 33-38 (regression). The full Task 2 suites
 * (CollaborationConversationTest, CollaborationMessageTest,
 * CollaborationDriverAuthorizationTest, CollaborationPermissionCatalogTest)
 * are unmodified and remain the primary regression evidence — these add
 * targeted cases at the exact points Task 3 actually touched Task 2 code
 * (SendMessageAction, SendMessageRequest, GetConversationMessagesAction),
 * not a restatement of Task 2's own tests.
 */
final class CollaborationTask3RegressionTest extends TestCase
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

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-employee', ['collaboration.conversations.create']);
    }

    // 33. Text messages remain functional — specifically, omitting `type`
    // entirely (exactly what a pre-Task-3 client always did) still works.
    public function test_a_plain_text_message_without_a_type_field_still_works(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'still works'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.attachment', null);
    }

    // 34. Replies remain functional through the now-branching send path.
    public function test_reply_linkage_still_works_when_no_file_is_involved(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $original = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'original'])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($target)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'reply', 'reply_to_message_id' => $original])
            ->assertCreated()
            ->assertJsonPath('data.reply_to_message_id', $original);
    }

    // 35. Task 2 unread behavior remains compatible — a media message
    // advances the unread counter exactly like a text one.
    public function test_an_image_message_counts_toward_the_recipients_unread_total(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'image',
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertCreated();

        $this->actingAsUnprivileged($target)
            ->getJson('/api/collaboration/conversations')
            ->assertOk()
            ->assertJsonFragment(['id' => $conversation->id, 'unread_count' => 1]);
    }

    // 36. Task 2 participant authorization remains authoritative on the
    // (now-extended) message-listing action.
    public function test_a_non_participant_still_cannot_list_messages_after_the_polling_extension(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->actingAsUnprivileged($outsider)
            ->getJson("/api/collaboration/conversations/{$conversation->id}/messages?after_message_id=".\Illuminate\Support\Str::uuid())
            ->assertForbidden();
    }

    // 37. Task 2 permission-catalog behavior remains unchanged by Task 3.
    public function test_task_2_permission_tokens_are_unaffected_by_task_3(): void
    {
        foreach (self::TASK2_PERMISSIONS as $name) {
            self::assertTrue(Permission::query()->where('name', $name)->exists(), "'{$name}' must still exist unchanged.");
        }

        self::assertSame(3, Permission::query()->where('module', 'collaboration')->count(), 'Task 3 introduces no new collaboration.* permission tokens (brief §26).');
    }

    // 38. No automatic role_permissions grants from Task 3 migrations —
    // Task 3 added no permission tokens at all (media/voice send and search
    // are participation-gated, exactly like text send/read in Task 2), so
    // the grant count for the collaboration module is unchanged from Task 2's
    // own remediation (zero).
    public function test_task_3_migrations_introduce_no_role_permission_grants(): void
    {
        $collaborationPermissionIds = Permission::query()->where('module', 'collaboration')->pluck('id');

        $grantCount = DB::table('role_permissions')
            ->whereIn('permission_id', $collaborationPermissionIds)
            ->count();

        self::assertSame(0, $grantCount);
    }
}
