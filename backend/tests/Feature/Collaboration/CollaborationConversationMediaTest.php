<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-INTERNAL-COLLABORATION-CHAT-FINAL-IMPLEMENTATION-002 — WhatsApp-
 * style conversation Media/Links/Documents aggregation (architecture report
 * §20). A read-only view over the SAME `collaboration_messages` table every
 * other Collaboration read already queries — no second media index, no new
 * storage authority.
 */
final class CollaborationConversationMediaTest extends TestCase
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
        return $this->userWithGrants($company, 'test-collab-media-employee', ['collaboration.conversations.create']);
    }

    public function test_image_type_returns_only_image_messages_with_no_storage_path_leak(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($target)
            ->postJson("/api/collaboration/conversations/{$conversation->id}/messages", [
                'type' => 'image',
                'file' => UploadedFile::fake()->image('photo.jpg', 200, 200)->size(500),
            ])
            ->assertCreated();

        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $target->id, 'type' => 'text', 'body' => 'just text']);

        $response = $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/conversations/{$conversation->id}/media?type=image")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        self::assertSame('photo.jpg', $response->json('data.0.attachment.name'));
        self::assertStringNotContainsString('documents/', $response->getContent());
        self::assertStringNotContainsString('file_path', $response->getContent());
    }

    public function test_link_type_returns_only_text_messages_containing_a_url(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $target->id, 'body' => 'check https://example.com/report please']);
        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $target->id, 'body' => 'no link in here']);

        $response = $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/conversations/{$conversation->id}/media?type=link")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        self::assertSame('https://example.com/report', $response->json('data.0.url'));
    }

    public function test_a_non_participant_is_forbidden_from_the_media_endpoint(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->employee($company);

        $this->actingAsUnprivileged($outsider)
            ->getJson("/api/collaboration/conversations/{$conversation->id}/media?type=image")
            ->assertForbidden();
    }

    public function test_an_invalid_type_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->getJson("/api/collaboration/conversations/{$conversation->id}/media?type=bogus")
            ->assertUnprocessable();
    }
}
