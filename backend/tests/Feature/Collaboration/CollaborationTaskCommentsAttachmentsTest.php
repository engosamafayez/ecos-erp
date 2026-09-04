<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-INTERNAL-TASKS-004.
 * Covers brief scenarios 21-25 (comments / attachments).
 */
final class CollaborationTaskCommentsAttachmentsTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-task-creator', ['collaboration.tasks.create']);
    }

    // 21. Authorized task comment.
    public function test_the_assignee_can_comment_on_their_task(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $assignee = User::factory()->create(['company_id' => $company->id, 'name' => 'Commenting Assignee']);

        $taskId = $this->actingAsUnprivileged($creator)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $assignee->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($assignee)
            ->postJson("/api/collaboration/tasks/{$taskId}/comments", ['body' => 'Working on it now'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Working on it now')
            ->assertJsonPath('data.author_user_id', $assignee->id)
            // Task 5 — comment author name resolves inline, not a bare id.
            ->assertJsonPath('data.author_name', 'Commenting Assignee');
    }

    // 22. Unauthorized comment rejected.
    public function test_a_non_owner_cannot_comment_on_a_task(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $outsider = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($creator)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($outsider)
            ->postJson("/api/collaboration/tasks/{$taskId}/comments", ['body' => 'nosy'])
            ->assertForbidden();
    }

    // 23. Authorized task attachment.
    public function test_the_creator_can_attach_a_file_to_their_task(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/tasks/{$taskId}/attachments", ['file' => UploadedFile::fake()->create('spec.pdf', 200, 'application/pdf')])
            ->assertCreated()
            ->assertJsonPath('data.name', 'spec.pdf');
    }

    // 24. Non-member/non-authorized attachment access rejected.
    public function test_a_non_owner_cannot_list_or_download_task_attachments(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $outsider = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');

        $documentId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/tasks/{$taskId}/attachments", ['file' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf')])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($outsider)
            ->getJson("/api/collaboration/tasks/{$taskId}/attachments")
            ->assertForbidden();

        $this->actingAsUnprivileged($outsider)
            ->get("/api/collaboration/tasks/{$taskId}/attachments/{$documentId}")
            ->assertForbidden();
    }

    // 25. Guessed document identifier cannot bypass task authorization —
    // even a participant-less outsider who somehow obtains a real document
    // id cannot use it against a task they have no access to.
    public function test_a_guessed_document_id_does_not_bypass_task_authorization(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $outsider = $this->employee($company);

        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');
        $documentId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/tasks/{$taskId}/attachments", ['file' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf')])
            ->assertCreated()->json('data.id');

        // The outsider is refused by TaskPolicy before the document lookup
        // is even reached — the document id being correct changes nothing.
        $this->actingAsUnprivileged($outsider)
            ->get("/api/collaboration/tasks/{$taskId}/attachments/{$documentId}")
            ->assertForbidden();
    }
}
