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
 * TASK-ECOS-COLLABORATION-MEDIA-VOICE-REALTIME-NOTIFICATIONS-SEARCH-003.
 * Covers brief scenarios 1-10 (media).
 */
final class CollaborationMediaTest extends TestCase
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
        return $this->userWithGrants($company, 'test-collab-employee', ['collaboration.conversations.create']);
    }

    // 1. Authorized image message send.
    public function test_a_participant_can_send_an_image_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'image',
                'file' => UploadedFile::fake()->image('photo.jpg', 200, 200)->size(500),
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.attachment.mime_type', 'image/jpeg');
    }

    // 2. Unauthorized/non-member image send rejected.
    public function test_a_non_member_cannot_send_an_image_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->actingAsUnprivileged($outsider)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'image',
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertForbidden();
    }

    // 3. Image MIME validation.
    public function test_an_unsupported_image_mime_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'image',
                'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    // 4. Image size validation.
    public function test_an_oversized_image_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'image',
                'file' => UploadedFile::fake()->image('huge.jpg')->size(11 * 1024), // > 10 MB limit
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    // 5. Authorized file send.
    public function test_a_participant_can_send_a_file_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'file',
                'file' => UploadedFile::fake()->create('manifest.pdf', 300, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'file')
            ->assertJsonPath('data.attachment.name', 'manifest.pdf');
    }

    // 6. Unauthorized file send rejected.
    public function test_a_non_member_cannot_send_a_file_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->actingAsUnprivileged($outsider)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'file',
                'file' => UploadedFile::fake()->create('manifest.pdf', 300, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    // 7. File metadata persisted safely (no raw path in the API response).
    public function test_file_metadata_is_persisted_and_no_raw_path_is_exposed(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $response = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'file',
                'file' => UploadedFile::fake()->create('manifest.pdf', 300, 'application/pdf'),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('documents', [
            'subject_type' => 'CollaborationMessage',
            'subject_id' => $response->json('data.id'),
            'name' => 'manifest.pdf',
        ]);

        $raw = $response->getContent();
        self::assertStringNotContainsString('documents/', $raw, 'API response must never expose a raw storage path.');
        self::assertStringNotContainsString('file_path', $raw);
    }

    // 8. Media retrieval requires conversation authorization.
    public function test_media_retrieval_requires_conversation_participation(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'file',
                'file' => UploadedFile::fake()->create('manifest.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('data.id');

        // Sender and recipient can both retrieve it.
        $this->actingAsUnprivileged($actor)
            ->get("/api/collaboration/messages/{$messageId}/attachment")
            ->assertOk();

        $this->actingAsUnprivileged($target)
            ->get("/api/collaboration/messages/{$messageId}/attachment")
            ->assertOk();

        // A non-participant, even in the same company, cannot.
        $outsider = $this->employee($company);
        $this->actingAsUnprivileged($outsider)
            ->get("/api/collaboration/messages/{$messageId}/attachment")
            ->assertForbidden();
    }

    // 9. Guessed document/media identifier does not bypass access.
    public function test_a_random_message_id_does_not_leak_attachment_access(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->get('/api/collaboration/messages/'.\Illuminate\Support\Str::uuid().'/attachment')
            ->assertNotFound();
    }

    // 10. Foreign-company media access rejected.
    public function test_a_user_in_another_company_cannot_retrieve_the_attachment(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actor = $this->employee($companyA);
        $target = User::factory()->create(['company_id' => $companyA->id]);
        $conversation = $this->directConversation($companyA, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'image',
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertCreated()
            ->json('data.id');

        $foreignUser = $this->employee($companyB);

        $this->actingAsUnprivileged($foreignUser)
            ->get("/api/collaboration/messages/{$messageId}/attachment")
            ->assertForbidden();
    }
}
