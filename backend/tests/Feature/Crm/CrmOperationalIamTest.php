<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Crm\Customers\Domain\Enums\CustomerType;
use Modules\Crm\Customers\Domain\Services\CustomerService;
use Modules\Crm\Engagement\Domain\Models\CustomerTask;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Infrastructure\Database\Seeders\RbacSeeder;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-CRM-OPERATIONAL-WIRING-IAM-AND-SOURCE-HARDENING-004.
 *
 * Before this task, config/permissions.php granted crm.engagement.* to no
 * default role at all, so every Sales/Customer Service user's follow-up UI
 * called endpoints nobody could reach outside the super-admin bypass that
 * Tests\TestCase::actingAs() grants automatically to role-less users. That
 * bypass is why CustomerPortfolioAndFollowUpTest's 22 cases never caught the
 * gap. These tests seed from the REAL config/permissions.php via the REAL
 * RbacSeeder — not a hand-built fixture role — and authenticate with
 * actingAsUnprivileged() so the baseline grant cannot mask a missing
 * permission again.
 */
final class CrmOperationalIamTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $grantsBaselineAuthorization = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Through the real `db:seed` console pathway, not a bare ->run() call:
        // RbacSeeder calls $this->command->info(...) unconditionally, which is
        // only ever set when a seeder runs under actual Artisan command
        // dispatch. assertExitCode(0) still fails the test loudly if seeding
        // throws internally, instead of a bare call silently reporting nothing.
        $this->artisan('db:seed', ['--class' => RbacSeeder::class, '--force' => true])
            ->assertExitCode(0);
    }

    private function customerService(): CustomerService
    {
        return app(CustomerService::class);
    }

    private function userWithRole(string $slug, Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $role = Role::where('slug', $slug)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    }

    // ═══ DEFAULT-ROLE-GRANT VERIFICATION ═════════════════════════════════════

    public function test_each_mapped_role_holds_all_three_crm_engagement_permissions_after_seeding(): void
    {
        foreach (['sales', 'sales-manager', 'sales-representative', 'customer-service'] as $slug) {
            $role = Role::where('slug', $slug)->firstOrFail();

            foreach (['crm.engagement.view', 'crm.engagement.log', 'crm.engagement.task.manage'] as $permission) {
                $this->assertTrue(
                    $role->permissions()->where('name', $permission)->exists(),
                    "Role [{$slug}] is missing [{$permission}] after RbacSeeder.",
                );
            }
        }
    }

    public function test_no_role_outside_the_four_mapped_roles_gained_crm_engagement_permissions(): void
    {
        $grantedSlugs = Role::whereHas('permissions', function ($query): void {
            $query->where('name', 'like', 'crm.engagement.%');
        })->pluck('slug')->sort()->values()->all();

        $this->assertSame(
            ['customer-service', 'sales', 'sales-manager', 'sales-representative'],
            $grantedSlugs,
        );
    }

    // ═══ AUTHORIZED / UNAUTHORIZED PORTFOLIO ACCESS ══════════════════════════

    public function test_a_mapped_role_can_read_the_portfolio_via_its_existing_crm_customers_view_grant(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithRole('sales-representative', $company);

        $this->actingAsUnprivileged($user)->getJson('/api/crm/portfolio')->assertOk();
    }

    public function test_a_role_with_no_crm_grants_at_all_is_refused_portfolio_access(): void
    {
        $company = Company::factory()->create();
        // 'driver' holds logistics.shipping + loading.driver only — zero crm.* grants.
        $user = $this->userWithRole('driver', $company);

        $this->actingAsUnprivileged($user)->getJson('/api/crm/portfolio')->assertForbidden();
    }

    // ═══ EXACT ACTION-PERMISSION BEHAVIOR ════════════════════════════════════
    // Proves routes/api.php gates each follow-up action on its own specific
    // permission string, not just "some crm.engagement.*" — via a
    // purpose-built role, independent of which production role gets what.

    public function test_view_only_can_list_tasks_but_cannot_create_complete_or_cancel_one(): void
    {
        $company = Company::factory()->create();
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Iam', 'phone' => '01099990001']);
        $task = CustomerTask::create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'task_type' => 'follow_up',
            'title' => 'Existing', 'status' => 'open', 'priority' => 'normal',
        ]);

        $role = Role::create(['name' => 'Test View Only', 'slug' => 'test-crm-engagement-view-only', 'is_system' => false]);
        $role->permissions()->attach(Permission::where('name', 'crm.engagement.view')->firstOrFail()->id);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->roles()->attach($role->id);

        $this->actingAsUnprivileged($user)->getJson("/api/crm/customers/{$customer->id}/tasks")->assertOk();
        $this->actingAsUnprivileged($user)->postJson("/api/crm/customers/{$customer->id}/tasks", ['title' => 'New'])->assertForbidden();
        $this->actingAsUnprivileged($user)->patchJson("/api/crm/customers/{$customer->id}/tasks/{$task->id}/complete")->assertForbidden();
        $this->actingAsUnprivileged($user)->patchJson("/api/crm/customers/{$customer->id}/tasks/{$task->id}/cancel")->assertForbidden();
    }

    public function test_task_manage_without_log_cannot_post_an_activity(): void
    {
        $company = Company::factory()->create();
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Iam2', 'phone' => '01099990002']);

        $role = Role::create(['name' => 'Test Task Manage Only', 'slug' => 'test-crm-engagement-task-manage-only', 'is_system' => false]);
        $role->permissions()->attach([
            Permission::where('name', 'crm.engagement.view')->firstOrFail()->id,
            Permission::where('name', 'crm.engagement.task.manage')->firstOrFail()->id,
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->roles()->attach($role->id);

        $this->actingAsUnprivileged($user)
            ->postJson("/api/crm/customers/{$customer->id}/tasks", ['task_type' => 'follow_up', 'title' => 'New'])
            ->assertCreated();
        $this->actingAsUnprivileged($user)
            ->postJson("/api/crm/customers/{$customer->id}/activities", ['type' => 'note', 'body' => 'x'])
            ->assertForbidden();
    }

    // ═══ ONE MAPPED PRODUCTION ROLE, END TO END ══════════════════════════════

    public function test_a_sales_representative_can_create_and_complete_a_follow_up_through_the_real_grant(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithRole('sales-representative', $company);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Real', 'phone' => '01099990003']);

        $create = $this->actingAsUnprivileged($user)->postJson("/api/crm/customers/{$customer->id}/tasks", [
            'task_type' => 'follow_up', 'title' => 'Call about renewal',
        ]);
        $create->assertCreated();

        $this->actingAsUnprivileged($user)
            ->patchJson("/api/crm/customers/{$customer->id}/tasks/{$create->json('data.id')}/complete")
            ->assertOk();
    }
}
