<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-CORE-FOUNDATION-002.
 *
 * Covers brief scenarios 1, 2, 6, 7, 8, 17, 20. Driver-specific
 * authorization is in CollaborationDriverAuthorizationTest; message send/
 * reply/mention/read is in CollaborationMessageTest.
 */
final class CollaborationConversationTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-employee', [
            'collaboration.conversations.create',
            'collaboration.groups.create',
        ]);
    }

    // 1. Employee <-> Employee direct conversation creation.
    public function test_employee_can_create_a_direct_conversation_with_another_employee(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $target->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'direct');

        $this->assertDatabaseHas('collaboration_conversation_participants', ['user_id' => $actor->id]);
        $this->assertDatabaseHas('collaboration_conversation_participants', ['user_id' => $target->id]);
    }

    // 2. Duplicate direct-conversation prevention (ADR-044 §8: unique per unordered pair).
    public function test_getting_a_direct_conversation_twice_returns_the_same_conversation(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);

        $first = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $target->id])
            ->assertCreated()
            ->json('data.id');

        // Same pair, requested from the other direction — must resolve to the same row.
        $second = $this->actingAsUnprivileged($target)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $actor->id])
            ->assertCreated()
            ->json('data.id');

        self::assertSame($first, $second);
        self::assertSame(
            1,
            Conversation::query()->where('company_id', $company->id)->where('type', 'direct')->count(),
        );
    }

    // 6. Group creation.
    public function test_employee_can_create_a_collaboration_group(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $memberA = User::factory()->create(['company_id' => $company->id, 'name' => 'Member A']);
        $memberB = User::factory()->create(['company_id' => $company->id, 'name' => 'Member B']);

        $response = $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/groups', [
                'title' => 'Warehouse Ops',
                'participant_user_ids' => [$memberA->id, $memberB->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'group')
            ->assertJsonPath('data.title', 'Warehouse Ops')
            ->assertJsonPath('data.my_role', 'owner');

        // Task 5 — a group's participants come back with resolved names, not just
        // ids: the frontend has no other way to render "who's in this group".
        $names = collect($response->json('data.participants'))->pluck('name');
        self::assertTrue($names->contains('Member A'));
        self::assertTrue($names->contains('Member B'));
    }

    // 7. Group membership addition/removal.
    public function test_group_owner_can_add_and_remove_a_member(): void
    {
        $company = Company::factory()->create();
        $owner = $this->employee($company);
        $newMember = User::factory()->create(['company_id' => $company->id]);

        $conversationId = $this->actingAsUnprivileged($owner)
            ->postJson('/api/collaboration/conversations/groups', ['title' => 'Ops', 'participant_user_ids' => []])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($owner)
            ->postJson("/api/collaboration/conversations/{$conversationId}/participants", ['user_id' => $newMember->id])
            ->assertCreated();

        $this->assertDatabaseHas('collaboration_conversation_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $newMember->id,
            'left_at' => null,
        ]);

        $this->actingAsUnprivileged($owner)
            ->deleteJson("/api/collaboration/conversations/{$conversationId}/participants/{$newMember->id}")
            ->assertOk();

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $newMember->id)
            ->first();

        self::assertNotNull($participant);
        self::assertNotNull($participant->left_at, 'Removal must soft-leave, not hard-delete, the membership row.');
    }

    // 8. Unauthorized membership mutation rejected.
    public function test_a_non_owner_member_cannot_add_a_participant(): void
    {
        $company = Company::factory()->create();
        $owner = $this->employee($company);
        $member = $this->employee($company);
        $outsider = User::factory()->create(['company_id' => $company->id]);

        $conversationId = $this->actingAsUnprivileged($owner)
            ->postJson('/api/collaboration/conversations/groups', ['title' => 'Ops', 'participant_user_ids' => [$member->id]])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($member)
            ->postJson("/api/collaboration/conversations/{$conversationId}/participants", ['user_id' => $outsider->id])
            ->assertForbidden();
    }

    // 17. Tenant/company isolation.
    public function test_an_employee_cannot_start_a_direct_conversation_with_a_user_in_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actor = $this->employee($companyA);
        $foreignUser = User::factory()->create(['company_id' => $companyB->id]);

        // Fails closed as "not found", not merely "forbidden" — a foreign-tenant
        // id must behave identically to a nonexistent one (architecture report §15).
        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $foreignUser->id])
            ->assertNotFound();
    }

    public function test_a_non_participant_cannot_view_another_companys_conversation(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $ownerA = $this->employee($companyA);
        $memberA = User::factory()->create(['company_id' => $companyA->id]);
        $outsiderB = $this->employee($companyB);

        $conversationId = $this->actingAsUnprivileged($ownerA)
            ->postJson('/api/collaboration/conversations/groups', ['title' => 'Company A Ops', 'participant_user_ids' => [$memberA->id]])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUnprivileged($outsiderB)
            ->getJson("/api/collaboration/conversations/{$conversationId}")
            ->assertForbidden();
    }

    // 20. Existing IAM authorities remain unaffected by the new catalogue entries.
    public function test_existing_and_new_permission_resources_coexist_in_the_catalogue(): void
    {
        self::assertContains('update', config('permissions.modules.organization.companies'), 'Pre-existing catalogue entries must be untouched.');
        self::assertContains('create', config('permissions.modules.collaboration.conversations'));
        self::assertContains('message_drivers', config('permissions.modules.collaboration.conversations'));
        self::assertContains('create', config('permissions.modules.collaboration.groups'));
    }

    // TASK-...-V1-REMEDIATION-COLLABORATION-INTEGRITY-035D §1 — storeDirect/storeGroup must return
    // the same hydrated my_participant/my_role/unread_count state show() already returns, via
    // GetConversationForUserAction. Before this fix, a freshly created conversation's own create
    // response carried my_role: null and unread_count: null (ConversationResource reads both off a
    // request-time-only attribute GetConversationForUserAction sets — never touched by the bare
    // ->load('activeParticipants.user') the create endpoints used instead).
    public function test_creating_a_direct_conversation_hydrates_my_participant_state(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $target->id])
            ->assertCreated()
            ->assertJsonPath('data.my_role', 'member')
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_creating_a_group_conversation_hydrates_unread_count(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $member = User::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/groups', [
                'title' => 'New Group',
                'participant_user_ids' => [$member->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.my_role', 'owner')
            ->assertJsonPath('data.unread_count', 0);
    }
}
