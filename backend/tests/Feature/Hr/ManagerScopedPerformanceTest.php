<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Core\Audit\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Hr\Performance\Domain\Models\ManagerReview;
use Modules\Hr\Workforce\Domain\Models\Employee;
use Modules\Hr\Workforce\Domain\Services\EmployeeService;
use Modules\Hr\Workforce\Domain\Services\ManagerScopeService;
use Modules\Hr\Workforce\Domain\Services\ReportingLineService;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-FIN-01-MANAGER-SCOPED-PERFORMANCE-046B.
 *
 * No DatabaseTransactions here, for the same reason HrAuditComplianceTest
 * omits it: the 045B-audit-regression proof needs a real commit for
 * DB::afterCommit() to have anything to fire after. Fixtures are created and
 * torn down explicitly per test via purgeCompany()/purgeRole().
 */
class ManagerScopedPerformanceTest extends TestCase
{
    /** @var array<int, string> */
    private array $companyIds = [];

    /** @var array<int, int> */
    private array $roleIds = [];

    protected function tearDown(): void
    {
        foreach ($this->roleIds as $roleId) {
            $this->purgeRole($roleId);
        }
        foreach ($this->companyIds as $companyId) {
            $this->purgeCompany($companyId);
        }

        parent::tearDown();
    }

    // ═══ A. Manager scoping — direct + indirect reports ═════════════════════════

    public function test_manager_can_view_direct_and_indirect_reports_but_not_unrelated_employee(): void
    {
        [$company, $manager, $direct, $indirect, $unrelated] = $this->hierarchy();
        $user = $this->userFor($company, $this->ordinaryPerformerRole($company), $manager);

        $this->actingAsUnprivileged($user)->getJson("/api/hr/performance/employees/{$direct->id}/dashboard")->assertOk();
        $this->actingAsUnprivileged($user)->getJson("/api/hr/performance/employees/{$indirect->id}/dashboard")->assertOk();
        $this->actingAsUnprivileged($user)->getJson("/api/hr/performance/employees/{$unrelated->id}/dashboard")->assertNotFound();
        $this->actingAsUnprivileged($user)->getJson("/api/hr/performance/employees/{$unrelated->id}/history")->assertNotFound();
    }

    public function test_cross_company_employee_is_never_visible_regardless_of_reporting_line_data(): void
    {
        [$company, $manager] = $this->hierarchy();
        $companyB = $this->company();
        $foreign = app(EmployeeService::class)->create((string) $companyB->id, ['first_name' => 'Foreign', 'last_name' => 'Person']);
        $user = $this->userFor($company, $this->ordinaryPerformerRole($company), $manager);

        $this->actingAsUnprivileged($user)->getJson("/api/hr/performance/employees/{$foreign->id}/dashboard")->assertNotFound();
    }

    public function test_my_team_lists_exactly_self_direct_and_indirect_reports(): void
    {
        [$company, $manager, $direct, $indirect, $unrelated] = $this->hierarchy();
        $user = $this->userFor($company, $this->ordinaryPerformerRole($company), $manager);

        $response = $this->actingAsUnprivileged($user)->getJson('/api/hr/performance/my-team')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains((string) $manager->id, $ids);
        $this->assertContains((string) $direct->id, $ids);
        $this->assertContains((string) $indirect->id, $ids);
        $this->assertNotContains((string) $unrelated->id, $ids);
        $this->assertCount(3, $ids);

        // §15: no narrative/review field is ever exposed through this new endpoint.
        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('strengths', $row);
            $this->assertArrayNotHasKey('manager_comments', $row);
        }
    }

    // ═══ B. Direct-ID bypass protection on the mutation path ════════════════════

    public function test_manager_review_save_is_denied_for_an_out_of_scope_employee_and_creates_nothing(): void
    {
        [$company, $manager, , , $unrelated] = $this->hierarchy();
        $user = $this->userFor($company, $this->reviewerRole($company), $manager);

        $response = $this->actingAsUnprivileged($user)->postJson("/api/hr/performance/employees/{$unrelated->id}/review", [
            'period_month' => '2026-07',
            'overall_rating' => 4,
            'status' => 'submitted',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, ManagerReview::where('employee_id', $unrelated->id)->count());
        $this->assertSame(0, AuditLog::where('entity_type', 'hr_manager_review')->where('company_id', (string) $company->id)->count());
    }

    public function test_manager_review_save_and_submit_succeeds_for_a_direct_report_and_preserves_045b_audit_evidence(): void
    {
        [$company, $manager, $direct] = $this->hierarchy();
        $user = $this->userFor($company, $this->reviewerRole($company), $manager);

        $response = $this->actingAsUnprivileged($user)->postJson("/api/hr/performance/employees/{$direct->id}/review", [
            'period_month' => '2026-07',
            'overall_rating' => 5,
            'strengths' => 'Excellent delivery',
            'status' => 'submitted',
        ]);

        $response->assertCreated();

        $review = ManagerReview::where('employee_id', $direct->id)->where('period_month', '2026-07')->first();
        $this->assertNotNull($review);
        $this->assertSame('submitted', $review->status);

        // 045B regression: both audit actions still fire, redacted correctly.
        $created = AuditLog::where('entity_type', 'hr_manager_review')->where('entity_id', (string) $review->id)
            ->where('action', 'hr.manager_review.created')->first();
        $submitted = AuditLog::where('entity_type', 'hr_manager_review')->where('entity_id', (string) $review->id)
            ->where('action', 'hr.manager_review.submitted')->first();

        $this->assertNotNull($created, '045B audit evidence for review creation must still be produced.');
        $this->assertNotNull($submitted, '045B audit evidence for review submission must still be produced.');
        $this->assertSame('[redacted]', $created->new_values['strengths']);
    }

    // ═══ C. Aggregate/department leak protection ════════════════════════════════

    public function test_department_dashboard_rollup_excludes_employees_outside_the_managers_scope(): void
    {
        [$company, $manager, $direct, $indirect, $unrelated] = $this->hierarchy();
        $department = $this->sameDepartmentFor([$direct, $indirect, $unrelated]);
        $user = $this->userFor($company, $this->ordinaryPerformerRole($company), $manager);

        $response = $this->actingAsUnprivileged($user)
            ->getJson("/api/hr/performance/departments/{$department}/dashboard")
            ->assertOk();

        // Only $direct and $indirect are in the manager's scope; $unrelated is not,
        // so the team rollup's headcount must reflect 2, never all 3 — this proves
        // the aggregate itself was computed over the restricted population, not
        // computed over everyone and merely trimmed for display.
        $this->assertSame(2, $response->json('data.team.headcount'));

        $ranked = collect($response->json('data.rankings'))->pluck('employee_id');
        $this->assertTrue($ranked->contains((string) $direct->id));
        $this->assertTrue($ranked->contains((string) $indirect->id));
        $this->assertFalse($ranked->contains((string) $unrelated->id));
    }

    // ═══ D. Broader IAM authority is preserved exactly, not narrowed ════════════

    public function test_a_role_with_a_deliberate_company_data_scope_grant_sees_the_whole_company_not_just_its_own_reports(): void
    {
        [$company, , , , $unrelated] = $this->hierarchy();
        $wideRole = $this->companyWideRole($company);
        // This user manages nobody at all — a plain employee with no reports —
        // yet the deliberately-configured COMPANY scope must still grant full
        // company visibility, proving subtree-narrowing does not override an
        // explicit, broader IAM grant.
        $loneEmployee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'Wide', 'last_name' => 'Viewer']);
        $user = $this->userFor($company, $wideRole, $loneEmployee);

        $this->actingAsUnprivileged($user)->getJson("/api/hr/performance/employees/{$unrelated->id}/dashboard")->assertOk();

        $ids = collect($this->actingAsUnprivileged($user)->getJson('/api/hr/performance/my-team')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains((string) $unrelated->id));
    }

    // ═══ E. Bounded-query evidence — no per-employee query in the subtree walk ══

    public function test_manager_subtree_computation_uses_a_bounded_number_of_queries_regardless_of_team_size(): void
    {
        [$company, $manager] = $this->hierarchy();

        // Add several more direct reports on top of the two the hierarchy already has.
        $extra = [];
        for ($i = 0; $i < 5; $i++) {
            $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => "Extra{$i}", 'last_name' => 'Report']);
            app(ReportingLineService::class)->assignManager($employee, $manager);
            $extra[] = $employee;
        }

        DB::enableQueryLog();
        $ids = app(ManagerScopeService::class)->visibleEmployeeIds($manager);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(1 + count($extra), count($ids)); // self + direct + the pre-existing indirect chain
        $this->assertLessThanOrEqual(2, $queryCount, 'Subtree computation must not issue one query per employee.');
    }

    // ═══ Fixtures & helpers ══════════════════════════════════════════════════════

    private function company(): Company
    {
        $company = Company::factory()->create();
        $this->companyIds[] = (string) $company->id;

        return $company;
    }

    /**
     * Manager → Direct report → Indirect report, plus one Unrelated employee
     * with no reporting-line connection to the manager at all.
     *
     * @return array{0: Company, 1: Employee, 2: Employee, 3: Employee, 4: Employee}
     */
    private function hierarchy(): array
    {
        $company = $this->company();
        $employees = app(EmployeeService::class);
        $lines = app(ReportingLineService::class);

        $manager = $employees->create((string) $company->id, ['first_name' => 'Manager', 'last_name' => 'One']);
        $direct = $employees->create((string) $company->id, ['first_name' => 'Direct', 'last_name' => 'Report']);
        $indirect = $employees->create((string) $company->id, ['first_name' => 'Indirect', 'last_name' => 'Report']);
        $unrelated = $employees->create((string) $company->id, ['first_name' => 'Unrelated', 'last_name' => 'Employee']);

        $lines->assignManager($direct, $manager);
        $lines->assignManager($indirect, $direct);

        return [$company, $manager, $direct, $indirect, $unrelated];
    }

    /** Puts the given employees in the same (newly created) department; returns its id. */
    private function sameDepartmentFor(array $employees): string
    {
        $companyId = (string) $employees[0]->company_id;
        $departmentId = (string) DB::table('hr_departments')->insertGetId([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $companyId,
            'name' => 'Test Department',
            'code' => 'TESTDEPT-'.substr($companyId, 0, 8),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        foreach ($employees as $employee) {
            $employee->update(['department_id' => $departmentId]);
        }

        return $departmentId;
    }

    private function permission(string $name): Permission
    {
        [$module, $resource, $action] = explode('.', $name);

        return Permission::firstOrCreate(['name' => $name], ['module' => $module, 'resource' => $resource, 'action' => $action]);
    }

    /** A role granting hr.performance.view/manage/review at the DEFAULT (unconfigured) data_scope — the "ordinary, un-widened" case. */
    private function ordinaryPerformerRole(Company $company): Role
    {
        return $this->roleGranting($company, null);
    }

    /** Same permissions, but view/review carry saveReview's required permission too. */
    private function reviewerRole(Company $company): Role
    {
        return $this->roleGranting($company, null);
    }

    /** A role with an EXPLICIT, deliberate data_scope='company' grant. */
    private function companyWideRole(Company $company): Role
    {
        return $this->roleGranting($company, 'company');
    }

    private function roleGranting(Company $company, ?string $dataScope): Role
    {
        $role = Role::create(['name' => 'Test Role '.uniqid(), 'slug' => 'test-role-'.uniqid(), 'is_system' => false]);
        $this->roleIds[] = (int) $role->id;

        foreach (['hr.performance.view', 'hr.performance.manage', 'hr.performance.review'] as $name) {
            $permission = $this->permission($name);
            $role->permissions()->attach($permission->id);

            if ($dataScope !== null) {
                DB::table('role_permissions')
                    ->where('role_id', $role->id)->where('permission_id', $permission->id)
                    ->update(['data_scope' => $dataScope]);
            }
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

    private function purgeRole(int $roleId): void
    {
        DB::table('role_permissions')->where('role_id', $roleId)->delete();
        DB::table('user_roles')->where('role_id', $roleId)->delete();
        DB::table('roles')->where('id', $roleId)->delete();
    }

    private function purgeCompany(string $companyId): void
    {
        DB::table('audit_logs')->where('company_id', $companyId)->delete();
        DB::table('hr_employee_incidents')->where('company_id', $companyId)->delete();
        DB::table('hr_bonus_recommendations')->where('company_id', $companyId)->delete();
        DB::table('hr_manager_reviews')->where('company_id', $companyId)->delete();
        DB::table('hr_performance_snapshots')->where('company_id', $companyId)->delete();
        DB::table('hr_goals')->where('company_id', $companyId)->delete();
        DB::table('hr_reporting_lines')->where('company_id', $companyId)->delete();
        DB::table('hr_departments')->where('company_id', $companyId)->delete();
        DB::table('users')->where('company_id', $companyId)->delete();
        DB::table('hr_employees')->where('company_id', $companyId)->delete();
        DB::table('companies')->where('id', $companyId)->delete();
    }
}
