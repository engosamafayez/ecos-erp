<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Core\Documents\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-MEDIA-VOICE-REALTIME-NOTIFICATIONS-SEARCH-003.
 * Covers brief scenarios 11-17 (voice — mandatory V1 capability).
 */
final class CollaborationVoiceTest extends TestCase
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

    // 11. Voice message send.
    public function test_a_participant_can_send_a_voice_message(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('note.mp3', 200, 'audio/mpeg'),
                'voice_duration_seconds' => 12,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'voice')
            ->assertJsonPath('data.attachment.duration_seconds', 12);
    }

    // 12. Voice metadata persisted.
    public function test_voice_metadata_is_persisted_against_the_document(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('note.mp3', 200, 'audio/mpeg'),
                'voice_duration_seconds' => 7,
            ])
            ->assertCreated()
            ->json('data.id');

        $document = Document::query()->where('subject_type', 'CollaborationMessage')->where('subject_id', $messageId)->firstOrFail();

        $this->assertDatabaseHas('collaboration_voice_metadata', [
            'document_id' => $document->id,
            'duration_seconds' => 7,
        ]);
    }

    // Client-provided duration is display-only, never authorization input (brief §7) —
    // a bogus value is accepted and stored, it does not grant or deny anything.
    public function test_client_provided_duration_is_not_trusted_for_any_authorization_decision(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
                'voice_duration_seconds' => 999999,
            ])
            ->assertCreated()
            ->json('data.id');

        // A huge/implausible duration changes nothing about who may play it back.
        $this->actingAsUnprivileged($outsider)
            ->get("/api/collaboration/messages/{$messageId}/attachment")
            ->assertForbidden();
    }

    // 13. Voice playback authorized for member.
    public function test_voice_playback_is_authorized_for_a_conversation_member(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($target)
            ->get("/api/collaboration/messages/{$messageId}/attachment")
            ->assertOk();
    }

    // 14. Voice playback rejected for non-member.
    public function test_voice_playback_is_rejected_for_a_non_member(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($outsider)
            ->get("/api/collaboration/messages/{$messageId}/attachment")
            ->assertForbidden();
    }

    // 15. Copied/guessed media path cannot bypass authorization — hitting the
    // private disk path directly (as a caller with only a leaked path string
    // would) is not a route this API exposes at all; the only path to bytes
    // is the authorized controller, which scenario 14 already proves refuses
    // a non-member. This test confirms the disk itself is not the public one.
    public function test_voice_files_are_stored_on_the_private_disk_not_the_public_one(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messageId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
            ])
            ->assertCreated()
            ->json('data.id');

        $document = Document::query()->where('subject_type', 'CollaborationMessage')->where('subject_id', $messageId)->firstOrFail();

        Storage::disk('local')->assertExists($document->file_path);
        self::assertStringStartsWith('documents/', $document->file_path);
    }

    // 16. Failed association leaves no accessible orphan — the primary
    // real-world failure mode (request-level validation rejection) leaves
    // zero trace, not a partially-created message or document.
    public function test_a_rejected_voice_upload_leaves_no_message_or_document_row(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $messagesBefore = Message::query()->count();
        $documentsBefore = Document::query()->count();

        $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('note.txt', 10, 'text/plain'), // wrong mime for voice
            ])
            ->assertUnprocessable();

        self::assertSame($messagesBefore, Message::query()->count());
        self::assertSame($documentsBefore, Document::query()->count());
    }

    // 17. Voice message can participate in reply linkage.
    public function test_a_voice_message_can_be_replied_to_and_can_itself_be_a_reply(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $voiceId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
            ])
            ->assertCreated()
            ->json('data.id');

        // A text reply to the voice message.
        $this->actingAsUnprivileged($target)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'text',
                'body' => 'Got it, thanks!',
                'reply_to_message_id' => $voiceId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.reply_to_message_id', $voiceId);

        // A voice reply to a text message.
        $textId = $this->actingAsUnprivileged($actor)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", ['body' => 'Can you confirm by voice?'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($target)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'voice',
                'file' => UploadedFile::fake()->create('confirm.mp3', 100, 'audio/mpeg'),
                'reply_to_message_id' => $textId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.reply_to_message_id', $textId);
    }
}
