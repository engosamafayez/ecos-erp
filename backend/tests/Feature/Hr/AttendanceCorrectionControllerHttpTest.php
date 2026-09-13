<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Hr\Attendance\Domain\Enums\AttendanceStatus;
use Modules\Hr\Attendance\Domain\Services\AttendanceRegistrationService;
use Modules\Hr\Workforce\Domain\Services\EmployeeService;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * FIN-01 — permission boundary and cross-company denial for the Attendance
 * correction endpoints, over real HTTP requests.
 */
class AttendanceCorrectionControllerHttpTest extends TestCase
{
    use DatabaseTransactions;

    private function role(Company $company, array $permissionNames): Role
    {
        $role = Role::create(['name' => 'Test Role '.uniqid(), 'slug' => 'test-role-'.uniqid(), 'is_system' => false]);

        foreach ($permissionNames as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(['name' => $name], ['module' => $module, 'resource' => $resource, 'action' => $action]);
            $role->permissions()->attach($permission->id);
        }

        return $role;
    }

    private function userFor(Company $company, Role $role): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_a_viewer_without_register_permission_cannot_request_a_correction(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00'],
        );
        $viewer = $this->userFor($company, $this->role($company, ['hr.attendance.view']));

        $this->actingAsUnprivileged($viewer)
            ->postJson("/api/hr/attendance/days/{$day->id}/corrections", ['reason' => 'Fix'])
            ->assertForbidden();
    }

    public function test_a_registrar_can_request_and_approve_a_correction(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );
        $registrar = $this->userFor($company, $this->role($company, ['hr.attendance.view', 'hr.attendance.register']));

        $created = $this->actingAsUnprivileged($registrar)
            ->postJson("/api/hr/attendance/days/{$day->id}/corrections", ['check_in' => '09:00:00', 'reason' => 'Fix'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        $this->actingAsUnprivileged($registrar)
            ->patchJson("/api/hr/attendance/corrections/{$created['id']}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.corrected.check_in', '09:00:00');
    }

    public function test_a_cross_company_attendance_day_id_is_never_found(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $otherEmployee = app(EmployeeService::class)->create((string) $otherCompany->id, ['first_name' => 'O', 'last_name' => 'X']);
        $otherDay = app(AttendanceRegistrationService::class)->register(
            $otherEmployee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00'],
        );
        $registrar = $this->userFor($company, $this->role($company, ['hr.attendance.view', 'hr.attendance.register']));

        $this->actingAsUnprivileged($registrar)
            ->postJson("/api/hr/attendance/days/{$otherDay->id}/corrections", ['reason' => 'Fix'])
            ->assertNotFound();
    }

    public function test_the_corrections_list_is_scoped_to_the_caller_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register($employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00']);
        $registrar = $this->userFor($company, $this->role($company, ['hr.attendance.view', 'hr.attendance.register']));
        $this->actingAsUnprivileged($registrar)->postJson("/api/hr/attendance/days/{$day->id}/corrections", ['reason' => 'Fix']);

        $otherEmployee = app(EmployeeService::class)->create((string) $otherCompany->id, ['first_name' => 'O', 'last_name' => 'X']);
        $otherDay = app(AttendanceRegistrationService::class)->register($otherEmployee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00']);
        $otherRegistrar = $this->userFor($otherCompany, $this->role($otherCompany, ['hr.attendance.view', 'hr.attendance.register']));
        $this->actingAsUnprivileged($otherRegistrar)->postJson("/api/hr/attendance/days/{$otherDay->id}/corrections", ['reason' => 'Fix']);

        $viewer = $this->userFor($company, $this->role($company, ['hr.attendance.view']));
        $response = $this->actingAsUnprivileged($viewer)->getJson('/api/hr/attendance/corrections')->assertOk();

        $this->assertCount(1, $response->json('data'), 'A company must never see another company\'s correction requests.');
    }
}
