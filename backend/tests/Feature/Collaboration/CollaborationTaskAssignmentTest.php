<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Collaboration\Domain\Services\DriverMessagingAuthorizer;
use Modules\IAM\Domain\Contracts\ScopeResolverInterface;
use Modules\IAM\Domain\Enums\DataScope;
use Modules\IAM\Domain\ValueObjects\ScopeConstraint;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\Feature\Collaboration\Concerns\CollaborationTestHelpers;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-INTERNAL-TASKS-004.
 * Covers brief scenarios 11-15 (assignment, incl. employee->driver).
 */
final class CollaborationTaskAssignmentTest extends TestCase
{
    use CollaborationTestHelpers;
    use DatabaseTransactions;

    private function employee(Company $company): User
    {
        return $this->userWithGrants($company, 'test-collab-task-creator', ['collaboration.tasks.create']);
    }

    // 11. Authorized assignment succeeds.
    public function test_the_creator_can_reassign_their_own_task(): void
    {
        $company = Company::factory()->create();
        $actor = $this->employee($company);
        $newAssignee = User::factory()->create(['company_id' => $company->id]);

        $taskId = $this->actingAsUnprivileged($actor)->postJson('/api/collaboration/tasks', ['title' => 'x'])->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($actor)
            ->patchJson("/api/collaboration/tasks/{$taskId}/assignee", ['assignee_user_id' => $newAssignee->id])
            ->assertOk()
            ->assertJsonPath('data.assignee_user_id', $newAssignee->id);
    }

    // 12. Unauthorized reassignment rejected — the assignee (not the creator) may not reassign.
    public function test_the_assignee_cannot_reassign_a_task_they_do_not_own(): void
    {
        $company = Company::factory()->create();
        $creator = $this->employee($company);
        $assignee = User::factory()->create(['company_id' => $company->id]);
        $someoneElse = User::factory()->create(['company_id' => $company->id]);

        $taskId = $this->actingAsUnprivileged($creator)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $assignee->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($assignee)
            ->patchJson("/api/collaboration/tasks/{$taskId}/assignee", ['assignee_user_id' => $someoneElse->id])
            ->assertForbidden();
    }

    // 13. Employee -> driver assignment resolves the canonical linked identity.
    public function test_employee_with_permission_can_assign_a_task_to_a_linked_driver(): void
    {
        $company = Company::factory()->create();
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $dispatcher = $this->userWithGrants($company, 'test-collab-task-dispatcher', [
            'collaboration.tasks.create',
            'collaboration.tasks.assign_drivers',
        ]);

        $this->actingAsUnprivileged($dispatcher)
            ->postJson('/api/collaboration/tasks', ['title' => 'Deliver package', 'assignee_user_id' => $driverUser->id])
            ->assertCreated()
            ->assertJsonPath('data.assignee_user_id', $driverUser->id);
    }

    public function test_employee_without_assign_drivers_permission_cannot_assign_to_a_driver(): void
    {
        $company = Company::factory()->create();
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $actor = $this->userWithGrants($company, 'test-collab-task-no-driver-perm', ['collaboration.tasks.create']);

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $driverUser->id])
            ->assertForbidden();
    }

    // 14. Driver assignment outside authorized scope denied by a substituted
    // narrow resolver — same reasoning as Task 2's equivalent messaging test:
    // no real role in the current catalogue can produce this denial (IAM's
    // scope engine resolves ALL by default, see architecture report §14),
    // so this proves Collaboration's integration honours a real denial.
    public function test_employee_with_permission_but_outside_scope_cannot_assign_to_the_driver(): void
    {
        $company = Company::factory()->create();
        $driverUser = User::factory()->create(['company_id' => $company->id]);
        $this->makeDriver($company, $driverUser);

        $actor = $this->userWithGrants($company, 'test-collab-task-scope-limited', [
            'collaboration.tasks.create',
            'collaboration.tasks.assign_drivers',
        ]);

        $this->app->bind(ScopeResolverInterface::class, fn () => new class implements ScopeResolverInterface
        {
            public function resolve(User $user, string $resource, ?string $ownerColumn = null): ScopeConstraint
            {
                return ScopeConstraint::none(DataScope::CUSTOM);
            }
        });

        $this->actingAsUnprivileged($actor)
            ->postJson('/api/collaboration/tasks', ['title' => 'x', 'assignee_user_id' => $driverUser->id])
            ->assertForbidden();
    }

    // 15. The known external driver-scope limitation does not cause a
    // duplicate Collaboration security engine — one class, two methods
    // (messaging and task-assignment), sharing one private scope check.
    public function test_driver_authorization_for_messaging_and_task_assignment_share_one_class(): void
    {
        self::assertTrue(method_exists(DriverMessagingAuthorizer::class, 'assertCanAddress'));
        self::assertTrue(method_exists(DriverMessagingAuthorizer::class, 'assertCanAssign'));

        // No sibling/second driver-authorization class exists anywhere in the module.
        self::assertFalse(class_exists(\Modules\Collaboration\Domain\Services\DriverTaskAuthorizer::class));
        self::assertFalse(class_exists(\Modules\Collaboration\Domain\Services\DriverAssignmentAuthorizer::class));
    }
}
