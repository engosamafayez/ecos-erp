<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-GOLIVE-RC6-REPAIR-001 — Orders tenant scope (RC-6 sibling).
 *
 * `Order` carries a `tenant` global scope written in exactly the same shape as
 * the warehouse one RC-6 was proven against: a null `company_id` returned early,
 * so an actor with no company affiliation was served every company's orders.
 *
 * These cases assert the scope's PREDICATE rather than row counts. That is
 * deliberate and is what makes them independent of order fixtures: the predicate
 * is the security control, and Orders has no factory to build a valid aggregate
 * from. End-to-end row-level proof for the same contract is in
 * {@see \Tests\Feature\MasterData\WarehouseTenantIsolationTest}.
 */
final class OrderTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private function userIn(?Company $company, bool $system): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);

        if ($system) {
            return $this->grantSystemRole($user);
        }

        $role = Role::firstOrCreate(
            ['slug' => 'test-order-operator'],
            ['name' => 'Test Order Operator', 'is_system' => false],
        );
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    public function test_company_scoped_user_query_is_constrained_to_their_company(): void
    {
        $company = Company::factory()->create();
        $this->actingAsUnprivileged($this->userIn($company, system: false));

        $query = Order::query();

        self::assertStringContainsString('company_id', $query->toSql());
        self::assertContains($company->id, $query->getBindings());
    }

    public function test_companyless_non_privileged_user_query_fails_closed(): void
    {
        $this->actingAsUnprivileged($this->userIn(null, system: false));

        $sql = Order::query()->toSql();

        self::assertStringContainsString(
            '1 = 0',
            $sql,
            'A null company must close the query, not remove the filter.',
        );

        self::assertSame(0, Order::query()->count());
    }

    public function test_unrestricted_user_query_is_not_company_constrained(): void
    {
        $this->actingAsUnprivileged($this->userIn(null, system: true));

        $sql = Order::query()->toSql();

        self::assertStringNotContainsString('company_id', $sql);
        self::assertStringNotContainsString('1 = 0', $sql);
    }

    public function test_unauthenticated_execution_is_not_scoped(): void
    {
        // Console commands, queue workers, seeders and migrations run with no
        // actor. Scoping them would silently filter background work.
        $sql = Order::query()->toSql();

        self::assertStringNotContainsString('company_id', $sql);
        self::assertStringNotContainsString('1 = 0', $sql);
    }
}
