<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\IAM\Domain\Contracts\ScopeResolverInterface;
use Modules\IAM\Domain\Enums\DataScope;
use Modules\IAM\Domain\Enums\UserStatus;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\ValueObjects\ScopeConstraint;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-WORKSPACE-DRIVER-EXPOSURE-CLOSURE-005 — CTO ruling: the
 * minimal identity-lookup endpoint (GET /collaboration/search/users), backed by
 * SearchAddressableUsersAction. Covers exactly the four properties the ruling
 * required: tenant isolation, unauthorized identity non-disclosure, employee
 * lookup, and driver-scope behaviour — reusing the same fixtures/helpers and the
 * same real (unmocked) ScopeResolverInterface pattern as
 * CollaborationDriverAuthorizationTest.
 */
final class CollaborationUserSearchTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-search-employee', ['collaboration.conversations.create']);
    }

    // Employee lookup: a plain colleague in the same company is found by name.
    public function test_search_finds_a_matching_employee_in_the_same_company(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $target = User::factory()->create(['company_id' => $company->id, 'name' => 'Amina Khalil']);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/users?q=Amina')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $target->id, 'name' => 'Amina Khalil', 'is_driver' => false]);
    }

    // Tenant isolation: a same-name user in another company must never appear.
    public function test_search_excludes_users_from_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actor = $this->employee($companyA);
        User::factory()->create(['company_id' => $companyB->id, 'name' => 'Cross Tenant Zzz']);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/users?q=Cross+Tenant+Zzz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // Searching for yourself never returns yourself — this endpoint is for finding
    // somebody else to address.
    public function test_search_excludes_the_searcher_themselves(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $actor->forceFill(['name' => 'Self Searcher Unique'])->save();

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/users?q=Self+Searcher+Unique')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // TASK-ECOS-INTERNAL-COLLABORATION-CHAT-FINAL-IMPLEMENTATION-002 — architecture
    // report §8/§16: "active IAM users... as the primary source" means a non-active
    // account (draft/invited/inactive/suspended/locked/archived) must not surface as
    // an addressable candidate, even though it isn't soft-deleted.
    public function test_search_excludes_a_non_active_user_from_the_same_company(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Inactive Person Zzz',
            'status' => UserStatus::INACTIVE->value,
        ]);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/users?q=Inactive+Person+Zzz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_requires_a_query_param(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/users')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    // Unauthorized identity non-disclosure: a driver-linked user is not just
    // unreachable but genuinely invisible to a searcher without the permission.
    public function test_a_driver_linked_user_is_excluded_without_the_message_drivers_permission(): void
    {
        $company = Company::factory()->create();
        $driverUser = User::factory()->create(['company_id' => $company->id, 'name' => 'Hidden Driver Zzz']);
        $this->makeDriver($company, $driverUser);

        $actor = $this->employee($company); // holds conversations.create, not message_drivers

        $this->actingAsUnprivileged($actor)
            ->getJson('/api/collaboration/search/users?q=Hidden+Driver+Zzz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // Driver-scope behaviour (positive): permission + in-scope driver is found and
    // flagged is_driver so the UI can route it through the driver-aware flow.
    public function test_a_driver_linked_user_is_included_with_permission_and_scope_and_flagged(): void
    {
        $company = Company::factory()->create();
        $driverUser = User::factory()->create(['company_id' => $company->id, 'name' => 'Visible Driver Zzz']);
        $this->makeDriver($company, $driverUser);

        $dispatcher = $this->userWithGrants($company, 'test-collab-search-dispatcher', [
            'collaboration.conversations.create',
            'collaboration.conversations.message_drivers',
        ]);

        $this->actingAsUnprivileged($dispatcher)
            ->getJson('/api/collaboration/search/users?q=Visible+Driver+Zzz')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $driverUser->id, 'is_driver' => true]);
    }

    // Driver-scope behaviour (negative): permission alone is not enough — outside
    // the actor's IAM data scope, the driver stays invisible even to a permission
    // holder. Mirrors CollaborationDriverAuthorizationTest scenario 5's resolver
    // substitution — proves search-time filtering honours a scope denial too, not
    // only the mutation endpoint.
    public function test_a_driver_linked_user_is_excluded_when_outside_scope_even_with_permission(): void
    {
        $company = Company::factory()->create();
        $driverUser = User::factory()->create(['company_id' => $company->id, 'name' => 'OutOfScope Driver Zzz']);
        $this->makeDriver($company, $driverUser);

        $dispatcher = $this->userWithGrants($company, 'test-collab-search-scope-limited', [
            'collaboration.conversations.create',
            'collaboration.conversations.message_drivers',
        ]);

        $this->app->bind(ScopeResolverInterface::class, fn () => new class implements ScopeResolverInterface
        {
            public function resolve(User $user, string $resource, ?string $ownerColumn = null): ScopeConstraint
            {
                return ScopeConstraint::none(DataScope::CUSTOM);
            }

            // Cache invalidation is irrelevant to this test double's one job
            // (proving a scope denial) and orthogonal to resolve()'s return
            // value — a no-op is the semantically correct implementation,
            // not a placeholder that could broaden access.
            public function invalidateUserCache(int $userId): void {}

            public function invalidateRoleCache(Role $role): void {}
        });

        $this->actingAsUnprivileged($dispatcher)
            ->getJson('/api/collaboration/search/users?q=OutOfScope+Driver+Zzz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
