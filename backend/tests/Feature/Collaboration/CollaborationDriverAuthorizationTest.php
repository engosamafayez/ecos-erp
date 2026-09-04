<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\IAM\Domain\Contracts\ScopeResolverInterface;
use Modules\IAM\Domain\Enums\DataScope;
use Modules\IAM\Domain\ValueObjects\ScopeConstraint;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-CORE-FOUNDATION-002 — ADR-044 §1.6 / architecture
 * report §14: employee -> driver messaging needs BOTH the
 * `collaboration.conversations.message_drivers` permission AND IAM's
 * canonical Data Scope Engine (ScopeResolverInterface), never a permission
 * alone. Covers brief scenarios 3, 4, 5, 18.
 */
final class CollaborationDriverAuthorizationTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    // 3. Employee -> Driver conversation with valid permission/scope.
    //
    // Deliberately exercises the REAL, unmocked ScopeResolverInterface
    // binding: IAM's own scope engine currently resolves ALL for every
    // logistics.drivers permission holder (no role has ever been given a
    // narrower data_scope for that resource — see report §14's honest
    // finding), so this proves genuine end-to-end integration, not a stub.
    public function test_employee_with_permission_can_message_a_linked_driver(): void
    {
        $company = Company::factory()->create();
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $dispatcher = $this->userWithGrants($company, 'test-collab-dispatcher', [
            'collaboration.conversations.create',
            'collaboration.conversations.message_drivers',
        ]);

        $this->actingAsUnprivileged($dispatcher)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $driverUser->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'direct');
    }

    // 4. Employee -> Driver denied without the message_drivers permission.
    public function test_employee_without_the_message_drivers_permission_is_denied(): void
    {
        $company = Company::factory()->create();
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        // Holds the general conversation-create permission but not the
        // driver-specific one — permission alone for a different resource
        // must not imply driver access.
        $actor = $this->userWithGrants($company, 'test-collab-no-driver-perm', [
            'collaboration.conversations.create',
        ]);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $driverUser->id])
            ->assertForbidden();
    }

    // 5. Employee -> Driver denied outside authorized scope.
    //
    // Substitutes a resolver returning a genuine "impossible" ScopeConstraint
    // (ScopeConstraint::none(), the same value IAM's own ScopeResolver
    // produces when a descriptor-based scope fails to resolve — see report
    // §14) to prove Collaboration's integration honours a scope denial. This
    // does not (and today, against the real seeded catalogue, cannot) prove
    // that any *currently configured* role is scope-limited — it proves the
    // wiring is correct for when one is.
    public function test_employee_with_permission_but_outside_scope_is_denied(): void
    {
        $company = Company::factory()->create();
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $actor = $this->userWithGrants($company, 'test-collab-scope-limited', [
            'collaboration.conversations.create',
            'collaboration.conversations.message_drivers',
        ]);

        $this->app->bind(ScopeResolverInterface::class, fn () => new class implements ScopeResolverInterface
        {
            public function resolve(User $user, string $resource, ?string $ownerColumn = null): ScopeConstraint
            {
                return ScopeConstraint::none(DataScope::CUSTOM);
            }
        });

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/conversations/direct', ['target_user_id' => $driverUser->id])
            ->assertForbidden();
    }

    // 18. Operational-context reference validation.
    public function test_a_participant_can_attach_an_operational_context_link(): void
    {
        $company = Company::factory()->create();
        $actor = $this->userWithGrants($company, 'test-collab-employee', ['collaboration.conversations.create']);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/context-links', [
                'attached_to_type' => 'conversation',
                'attached_to_id' => $conversation->id,
                'context_type' => 'trip',
                'context_id' => 'TRIP-123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.context_type', 'trip');
    }

    public function test_a_non_participant_cannot_attach_an_operational_context_link(): void
    {
        $company = Company::factory()->create();
        $actor = $this->userWithGrants($company, 'test-collab-employee', ['collaboration.conversations.create']);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);
        $outsider = $this->userWithGrants($company, 'test-collab-outsider', ['collaboration.conversations.create']);

        $this->actingAsUnprivileged($outsider)
            ->postJson('/api/collaboration/context-links', [
                'attached_to_type' => 'conversation',
                'attached_to_id' => $conversation->id,
                'context_type' => 'trip',
                'context_id' => 'TRIP-123',
            ])
            ->assertForbidden();
    }

    public function test_an_unsupported_context_type_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = $this->userWithGrants($company, 'test-collab-employee', ['collaboration.conversations.create']);
        $target = User::factory()->create(['company_id' => $company->id]);
        $conversation = $this->directConversation($company, $actor, $target);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/context-links', [
                'attached_to_type' => 'conversation',
                'attached_to_id' => $conversation->id,
                'context_type' => 'customer', // deferred per ADR-044 §1.7 — not a V1 type
                'context_id' => 'CUST-1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context_type']);
    }
}
