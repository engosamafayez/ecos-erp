<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Hr\Workforce\Domain\Models\Employee;
use Modules\Hr\Workforce\Domain\Services\EmployeeService;
use Modules\Hr\Workforce\Domain\Services\ReportingLineService;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * FIN-01 Slice 4 — HTTP-level drill-down/direct-ID security for the new
 * /hr/performance/drivers routes (044B §12): the same company/manager-scope
 * boundary the roster enforces must hold for a direct id guess too.
 */
class DriverPerformanceControllerHttpTest extends TestCase
{
    use DatabaseTransactions;

    private function makeDriver(string $code, ?int $userId, string $companyId, string $fullName, string $mobile, string $nationalId): Driver
    {
        return Driver::forceCreate([
            'driver_code' => $code,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $userId,
            'company_id' => $companyId,
            'full_name' => $fullName,
            'mobile' => $mobile,
            'national_id' => $nationalId,
            'status' => Driver::STATUS_ACTIVE,
        ]);
    }

    private function ordinaryPerformerRole(Company $company): Role
    {
        $role = Role::create(['name' => 'Test Role '.uniqid(), 'slug' => 'test-role-'.uniqid(), 'is_system' => false]);

        foreach (['hr.performance.view'] as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(['name' => $name], ['module' => $module, 'resource' => $resource, 'action' => $action]);
            $role->permissions()->attach($permission->id);
        }

        return $role;
    }

    private function userFor(Company $company, Role $role, Employee $employee): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->roles()->attach($role->id);
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_manager_sees_own_subtree_driver_but_not_an_unrelated_one(): void
    {
        $company = Company::factory()->create();
        $employees = app(EmployeeService::class);
        $manager = $employees->create((string) $company->id, ['first_name' => 'Mgr', 'last_name' => 'One']);
        $report = $employees->create((string) $company->id, ['first_name' => 'Rep', 'last_name' => 'One']);
        $unrelated = $employees->create((string) $company->id, ['first_name' => 'Unrelated', 'last_name' => 'One']);
        app(ReportingLineService::class)->assignManager($report, $manager);

        $reportUser = User::factory()->create(['company_id' => $company->id]);
        $report->update(['user_id' => $reportUser->id]);
        $unrelatedUser = User::factory()->create(['company_id' => $company->id]);
        $unrelated->update(['user_id' => $unrelatedUser->id]);

        $reportDriver = $this->makeDriver('DRV-H1', $reportUser->id, (string) $company->id, 'Report Driver', '0400000001', 'N-400001');
        $unrelatedDriver = $this->makeDriver('DRV-H2', $unrelatedUser->id, (string) $company->id, 'Unrelated Driver', '0400000002', 'N-400002');

        $managerUser = $this->userFor($company, $this->ordinaryPerformerRole($company), $manager);

        $this->actingAsUnprivileged($managerUser)
            ->getJson("/api/hr/performance/drivers/{$reportDriver->id}")
            ->assertOk()
            ->assertJsonPath('data.identity.status', 'matched');

        $this->actingAsUnprivileged($managerUser)
            ->getJson("/api/hr/performance/drivers/{$unrelatedDriver->id}")
            ->assertNotFound();
    }

    public function test_cross_company_direct_id_guess_fails_closed(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $employees = app(EmployeeService::class);
        $manager = $employees->create((string) $company->id, ['first_name' => 'Mgr', 'last_name' => 'Two']);

        $foreignDriver = $this->makeDriver('DRV-H3', null, (string) $otherCompany->id, 'Foreign Driver', '0400000003', 'N-400003');

        $managerUser = $this->userFor($company, $this->ordinaryPerformerRole($company), $manager);

        $this->actingAsUnprivileged($managerUser)
            ->getJson("/api/hr/performance/drivers/{$foreignDriver->id}")
            ->assertNotFound();
    }

    public function test_roster_endpoint_excludes_out_of_scope_drivers(): void
    {
        $company = Company::factory()->create();
        $employees = app(EmployeeService::class);
        $manager = $employees->create((string) $company->id, ['first_name' => 'Mgr', 'last_name' => 'Three']);
        $unrelated = $employees->create((string) $company->id, ['first_name' => 'Unrelated', 'last_name' => 'Two']);

        $unrelatedUser = User::factory()->create(['company_id' => $company->id]);
        $unrelated->update(['user_id' => $unrelatedUser->id]);
        $this->makeDriver('DRV-H4', $unrelatedUser->id, (string) $company->id, 'Unrelated Driver Two', '0400000004', 'N-400004');

        $managerUser = $this->userFor($company, $this->ordinaryPerformerRole($company), $manager);

        $response = $this->actingAsUnprivileged($managerUser)->getJson('/api/hr/performance/drivers')->assertOk();
        $ids = collect($response->json('data'))->pluck('driver_id')->all();

        $this->assertNotContains((string) $unrelatedUser->id, $ids);
        $this->assertSame([], $ids, 'The acting manager has no reports at all, so the roster must be empty, not the unrelated driver.');
    }
}
