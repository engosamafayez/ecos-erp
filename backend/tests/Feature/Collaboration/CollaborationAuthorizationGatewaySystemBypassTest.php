<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-INTERNAL-COLLABORATION-DEV-RUNTIME-CLOSURE-007-R1 — authorization
 * remediation. Five call sites (GetOrCreateDirectConversationAction,
 * CreateGroupConversationAction, CreateTaskAction, DriverMessagingAuthorizer,
 * SearchAddressableUsersAction) called AuthorizationGatewayInterface::can()/
 * authorize() — which perform a byte-for-byte PermissionService check only —
 * where the platform's is_system bypass (config('permissions.roles'), applied
 * via userHasSystemRole()) is meant to apply to every user-facing authorization
 * gate. That bypass lives only in inspect()/decision(), never in can()/
 * authorize() (see AuthorizationGatewayInterface's own docblocks). Each site
 * now calls inspect() — the same "authorization-only" scope can() already had,
 * plus the missing bypass — never decision(), which would additionally compose
 * policy/visibility/scope this call sites never exercised before and were not
 * authorized to start exercising now.
 *
 * Tests\TestCase::actingAs() is this codebase's own established is_system
 * fixture (see CollaborationTestHelpers's docblock): a persisted user with no
 * roles yet is auto-granted the role config/permissions.php marks is_system.
 * Every positive test below deliberately uses plain actingAs() with ZERO
 * explicit grants — proving the bypass is reached through the gateway itself,
 * not through an incidental permission the fixture happened to hold. Negative
 * tests use actingAsUnprivileged() with an explicit, empty-grant role, exactly
 * like every other Collaboration authorization test in this suite.
 */
final class CollaborationAuthorizationGatewaySystemBypassTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    // --- Positive: is_system bypass now reached through the gateway --------

    public function test_is_system_actor_creates_a_direct_conversation_without_any_explicit_grant(): void
    {
        $company = Company::factory()->create();
        $sysActor = User::factory()->create(['company_id' => $company->id]);
        $target = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($sysActor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $target->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'direct');
    }

    public function test_is_system_actor_creates_a_group_conversation_without_any_explicit_grant(): void
    {
        $company = Company::factory()->create();
        $sysActor = User::factory()->create(['company_id' => $company->id]);
        $member = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($sysActor)
            ->postJson('/api/collaboration/conversations/groups', [
                'title' => 'System Rollout Group',
                'participant_user_ids' => [$member->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'group');
    }

    public function test_is_system_actor_creates_a_task_without_any_explicit_grant(): void
    {
        $company = Company::factory()->create();
        $sysActor = User::factory()->create(['company_id' => $company->id]);

        // No assignee_user_id: defaults to self-assign, isolating
        // CreateTaskAction's own 'collaboration.tasks.create' gate from
        // DriverMessagingAuthorizer's separate assign-permission gate.
        //
        // TASK-ECOS-INTERNAL-COLLABORATION-FINAL-DEFECT-CLOSURE-009 fixed the
        // sibling TaskResource null-status defect this test used to have to
        // route around — a plain assertCreated() is now the correct, honest
        // assertion again.
        $this->actingAs($sysActor)
            ->postJson('/api/collaboration/tasks', ['title' => 'System-created task'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'System-created task')
            ->assertJsonPath('data.status', 'todo');
    }

    public function test_is_system_actor_messages_a_linked_driver_without_any_explicit_grant(): void
    {
        $company = Company::factory()->create();
        $sysActor = User::factory()->create(['company_id' => $company->id]);
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $this->actingAs($sysActor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $driverUser->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'direct');
    }

    public function test_is_system_actor_assigns_a_task_to_a_linked_driver_without_any_explicit_grant(): void
    {
        $company = Company::factory()->create();
        $sysActor = User::factory()->create(['company_id' => $company->id]);
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $this->actingAs($sysActor)
            ->postJson('/api/collaboration/tasks', [
                'title' => 'Deliver system-assigned package',
                'assignee_user_id' => $driverUser->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assignee_user_id', $driverUser->id)
            ->assertJsonPath('data.status', 'todo');
    }

    public function test_is_system_actor_sees_a_driver_as_addressable_in_search_without_any_explicit_grant(): void
    {
        $company = Company::factory()->create();
        $sysActor = User::factory()->create(['company_id' => $company->id]);
        $driverUser = User::factory()->create(['company_id' => $company->id, 'name' => 'System Visible Driver Zzz']);
        $this->makeDriver($company, $driverUser);

        $this->actingAs($sysActor)
            ->getJson('/api/collaboration/search/users?q=System+Visible+Driver+Zzz')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $driverUser->id, 'is_driver' => true]);
    }

    // --- Negative: an ordinary, explicitly unprivileged actor is still denied.
    // Proves the fix adds the is_system bypass without loosening the plain
    // permission check inspect() still performs for everyone else.

    public function test_ordinary_actor_without_any_grant_cannot_create_a_direct_conversation(): void
    {
        $company = Company::factory()->create();
        $actor = $this->userWithGrants($company, 'test-collab-no-grants-direct', []);
        $target = User::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $target->id])
            ->assertForbidden();
    }

    public function test_ordinary_actor_without_any_grant_cannot_create_a_group_conversation(): void
    {
        $company = Company::factory()->create();
        $actor = $this->userWithGrants($company, 'test-collab-no-grants-group', []);
        $member = User::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/groups', [
                'title' => 'Should Not Be Created',
                'participant_user_ids' => [$member->id],
            ])
            ->assertForbidden();
    }

    public function test_ordinary_actor_without_any_grant_cannot_create_a_task(): void
    {
        $company = Company::factory()->create();
        $actor = $this->userWithGrants($company, 'test-collab-no-grants-task', []);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'Should Not Be Created'])
            ->assertForbidden();
    }

    // --- Cross-tenant: unaffected by this fix (separate company-scoped lookup
    // in GetOrCreateDirectConversationAction), verified here as a regression
    // guard since it sits immediately next to the line that changed.

    public function test_is_system_actor_cannot_start_a_direct_conversation_with_a_cross_tenant_user(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $sysActor = User::factory()->create(['company_id' => $companyA->id]);
        $target = User::factory()->create(['company_id' => $companyB->id]);

        $this->actingAs($sysActor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $target->id])
            ->assertNotFound();
    }
}
