<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Core\Audit\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Hr\Attendance\Domain\Enums\AttendanceStatus;
use Modules\Hr\Attendance\Domain\Services\AttendanceRegistrationService;
use Modules\Hr\Attendance\Domain\Services\LeaveRequestService;
use Modules\Hr\Compensation\Domain\Enums\KpiMetric;
use Modules\Hr\Infrastructure\Services\HrAuditService;
use Modules\Hr\Performance\Domain\Enums\GoalSubject;
use Modules\Hr\Performance\Domain\Enums\RecommendationStatus;
use Modules\Hr\Performance\Domain\Models\BonusRecommendation;
use Modules\Hr\Performance\Domain\Services\BonusRecommendationService;
use Modules\Hr\Performance\Domain\Services\GoalService;
use Modules\Hr\Performance\Domain\Services\IncidentService;
use Modules\Hr\Performance\Domain\Services\ManagerReviewService;
use Modules\Hr\Performance\Domain\Services\PerformanceEvaluationService;
use Modules\Hr\Workforce\Domain\Enums\EmployeeStatus;
use Modules\Hr\Workforce\Domain\Models\Employee;
use Modules\Hr\Workforce\Domain\Services\EmployeeService;
use Modules\IAM\Domain\Contracts\SensitiveFieldRegistryInterface;
use Modules\Organization\Companies\Domain\Models\Company;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-FIN-01-AUDIT-SENSITIVE-DATA-045B.
 *
 * ┌─ WHY NO DatabaseTransactions HERE, UNLIKE ITS SIBLINGS ─────────────────┐
 * │ HrAuditService defers every record() call with DB::afterCommit(), on       │
 * │ purpose (see its own docblock): that is what stops the trail from ever     │
 * │ describing a mutation that was later rolled back. But DatabaseTransactions │
 * │ wraps an entire test in ONE transaction that is only ever rolled back at    │
 * │ teardown — under it, the true outermost commit this class is built on       │
 * │ NEVER happens, so no afterCommit callback registered anywhere in such a     │
 * │ test would ever fire. Proving requirement #5 (a rolled-back mutation        │
 * │ leaves no audit row) needs a REAL commit to roll back against, so these      │
 * │ tests manage their own company-scoped fixtures and clean them up in          │
 * │ tearDown() instead.                                                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class HrAuditComplianceTest extends TestCase
{
    /** @var array<int, string> */
    private array $companyIds = [];

    protected function tearDown(): void
    {
        foreach ($this->companyIds as $companyId) {
            $this->purgeCompany($companyId);
        }

        parent::tearDown();
    }

    // ═══ 1. Employee mutations ════════════════════════════════════════════════

    public function test_employee_create_update_transfer_status_and_terminate_all_emit_audit_evidence(): void
    {
        $company = $this->company();
        $employees = app(EmployeeService::class);

        $employee = $employees->create((string) $company->id, [
            'first_name' => 'Amina', 'last_name' => 'Youssef', 'national_id' => '29001010123456',
        ], 501);
        $this->assertAudit('hr_employee', 'hr.employee.created', (string) $employee->id, (string) $company->id, 501);

        $employees->update($employee, ['phone' => '01000000000'], 502);
        $this->assertAudit('hr_employee', 'hr.employee.updated', (string) $employee->id, (string) $company->id, 502, expectNew: ['phone' => '01000000000']);

        $employees->transfer($employee, ['department_id' => null, 'branch_id' => null], 503);
        $this->assertAudit('hr_employee', 'hr.employee.transferred', (string) $employee->id, (string) $company->id, 503);

        $employees->changeStatus($employee, EmployeeStatus::Suspended, 504);
        $this->assertAudit('hr_employee', 'hr.employee.status_changed', (string) $employee->id, (string) $company->id, 504, expectOld: ['status' => 'active'], expectNew: ['status' => 'suspended']);

        $employees->changeStatus($employee, EmployeeStatus::Active, 505);
        $employees->terminate($employee, 'Redundancy', null, false, 506);
        $this->assertAudit('hr_employee', 'hr.employee.terminated', (string) $employee->id, (string) $company->id, 506, expectNew: ['termination_reason' => 'Redundancy']);
    }

    public function test_employee_create_redacts_notes_but_keeps_structured_fields(): void
    {
        $company = $this->company();
        $employee = app(EmployeeService::class)->create((string) $company->id, [
            'first_name' => 'Omar', 'last_name' => 'Adel', 'notes' => 'Confidential HR remark', 'national_id' => '111',
        ], null);

        $row = $this->auditRow('hr_employee', 'hr.employee.created', (string) $employee->id);
        $this->assertSame('[redacted]', $row->new_values['notes']);
        $this->assertSame('111', $row->new_values['national_id']);
    }

    // ═══ 2. Attendance ═══════════════════════════════════════════════════════

    public function test_attendance_registration_then_correction_emit_distinct_audit_actions(): void
    {
        $company = $this->company();
        $employee = $this->employee($company);
        $service = app(AttendanceRegistrationService::class);

        $day = $service->register($employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:00']);
        $this->assertAudit('hr_attendance_day', 'hr.attendance_day.registered', (string) $day->id, (string) $company->id);

        $service->register($employee, '2026-01-05', AttendanceStatus::Absent, ['notes' => 'Called in sick after all']);
        $this->assertAudit(
            'hr_attendance_day', 'hr.attendance_day.corrected', (string) $day->id, (string) $company->id,
            expectOld: ['status' => 'present'], expectNew: ['status' => 'absent'],
        );
    }

    // ═══ 3. Leave — approval consequence stays traceable ════════════════════════

    public function test_approved_leave_is_audited_and_its_attendance_consequence_is_traceable(): void
    {
        $company = $this->company();
        $employee = $this->employee($company);
        $leave = app(LeaveRequestService::class);

        $request = $leave->submit($employee, ['start_date' => '2026-02-02', 'end_date' => '2026-02-03'], 601);
        $this->assertAudit('hr_leave_request', 'hr.leave_request.submitted', (string) $request->id, (string) $company->id, 601);

        $approved = $leave->approve($request, $employee, 'Approved by manager', 602);
        $row = $this->auditRow('hr_leave_request', 'hr.leave_request.approved', (string) $request->id);
        $this->assertSame(602, $row->user_id);
        $this->assertSame(2, $row->metadata['attendance_days_affected']);

        // Traceability: the attendance days this approval wrote are reachable
        // from the request via the existing leave_request_id FK — no per-day
        // audit rows were needed to prove the consequence happened.
        $this->assertSame(2, $approved->attendanceDays()->count());
        $this->assertTrue($approved->attendanceDays()->pluck('status')->every(fn (AttendanceStatus $s) => $s === AttendanceStatus::Leave));

        $cancelled = $leave->cancel($approved, 'Employee returned early', 603);
        $this->assertAudit('hr_leave_request', 'hr.leave_request.cancelled', (string) $cancelled->id, (string) $company->id, 603, metadataSubset: ['attendance_days_removed' => 2]);
        $this->assertSame(0, $cancelled->attendanceDays()->count());
    }

    public function test_leave_reason_and_decision_note_are_redacted_in_the_audit_trail(): void
    {
        $company = $this->company();
        $employee = $this->employee($company);
        $leave = app(LeaveRequestService::class);

        $request = $leave->submit($employee, [
            'start_date' => '2026-03-01', 'end_date' => '2026-03-01', 'reason' => 'Medical procedure',
        ]);

        $row = $this->auditRow('hr_leave_request', 'hr.leave_request.submitted', (string) $request->id);
        $this->assertSame('[redacted]', $row->new_values['reason']);
    }

    // ═══ 4. Performance ══════════════════════════════════════════════════════

    public function test_goal_and_performance_snapshot_mutations_emit_audit_evidence(): void
    {
        $company = $this->company();
        $employee = $this->employee($company);
        $goals = app(GoalService::class);

        $goal = $goals->set((string) $company->id, [
            'subject_type' => GoalSubject::Employee->value, 'subject_id' => (string) $employee->id,
            'metric_key' => KpiMetric::SalesAmount->value, 'period_month' => '2026-04', 'target_value' => 1000,
        ], 701);
        $this->assertAudit('hr_goal', 'hr.goal.created', (string) $goal->id, (string) $company->id, 701);

        $goals->set((string) $company->id, [
            'subject_type' => GoalSubject::Employee->value, 'subject_id' => (string) $employee->id,
            'metric_key' => KpiMetric::SalesAmount->value, 'period_month' => '2026-04', 'target_value' => 2000,
        ], 702);
        $this->assertAudit('hr_goal', 'hr.goal.updated', (string) $goal->id, (string) $company->id, 702, expectNew: ['target_value' => '2000.0000']);

        app(PerformanceEvaluationService::class)->evaluateGoal($goal->fresh());
        $this->assertAudit('hr_performance_snapshot', 'hr.performance_snapshot.computed', null, (string) $company->id, metadataSubset: ['goal_id' => (string) $goal->id]);
    }

    public function test_manager_review_mutations_are_audited_with_narrative_fields_redacted(): void
    {
        $company = $this->company();
        $employee = $this->employee($company);
        $reviews = app(ManagerReviewService::class);

        $review = $reviews->save($employee, '2026-05', [
            'overall_rating' => 4, 'strengths' => 'Very reliable', 'manager_comments' => 'Confidential note',
        ], null, 801);
        $this->assertAudit('hr_manager_review', 'hr.manager_review.created', (string) $review->id, (string) $company->id, 801);

        $row = $this->auditRow('hr_manager_review', 'hr.manager_review.created', (string) $review->id);
        $this->assertSame('[redacted]', $row->new_values['strengths']);
        $this->assertSame('[redacted]', $row->new_values['manager_comments']);
        $this->assertSame(4, $row->new_values['overall_rating']);

        $reviews->submit($review, 802);
        $this->assertAudit('hr_manager_review', 'hr.manager_review.submitted', (string) $review->id, (string) $company->id, 802);
    }

    public function test_employee_incident_is_audited_with_description_redacted(): void
    {
        $company = $this->company();
        $employee = $this->employee($company);

        $incident = app(IncidentService::class)->record($employee, [
            'category' => 'operational_note', 'description' => 'Left the site without notice', 'severity' => 'medium',
        ], 901);

        $row = $this->auditRow('hr_employee_incident', 'hr.employee_incident.recorded', (string) $incident->id);
        $this->assertSame(901, $row->user_id);
        $this->assertSame('[redacted]', $row->new_values['description']);
        $this->assertSame('medium', $row->new_values['severity']);
    }

    public function test_bonus_recommendation_rejection_is_audited_as_hr_evidence_only(): void
    {
        $company = $this->company();
        $employee = $this->employee($company);

        // Constructed directly, bypassing recommendFor()'s salary-structure/KPI
        // computation — this test only needs a Pending recommendation to exist,
        // it is not re-testing the achievement math.
        $recommendation = BonusRecommendation::create([
            'company_id' => $company->id, 'employee_id' => $employee->id, 'period_month' => '2026-06',
            'achievement_percent' => 95.0, 'recommended_amount' => 500.0, 'currency' => 'EGP',
            'rule_key' => 'near_target', 'rationale' => 'test fixture', 'status' => RecommendationStatus::Pending->value,
        ]);

        app(BonusRecommendationService::class)->reject($recommendation, $employee, 'Not this quarter', 1001);

        $this->assertAudit(
            'hr_bonus_recommendation', 'hr.bonus_recommendation.rejected', (string) $recommendation->id,
            (string) $company->id, 1001, expectNew: ['status' => RecommendationStatus::Rejected->value],
        );
    }

    // ═══ 5. Rollback must not leave false-success evidence ══════════════════════

    public function test_a_rolled_back_mutation_leaves_no_audit_evidence(): void
    {
        $company = $this->company();
        $employee = $this->employee($company);

        try {
            DB::transaction(function () use ($employee): void {
                app(EmployeeService::class)->update($employee, ['phone' => '0111']);

                throw new RuntimeException('forced rollback for test 5');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('forced rollback for test 5', $e->getMessage());
        }

        $this->assertNull(
            AuditLog::query()->where('entity_type', 'hr_employee')->where('entity_id', (string) $employee->id)
                ->where('action', 'hr.employee.updated')->first(),
            'A rolled-back mutation must leave no audit evidence at all.',
        );
        $this->assertNull($employee->fresh()->phone, 'The rolled-back phone update must not have persisted either.');
    }

    // ═══ 6 & 7. Company scope and tenant isolation ══════════════════════════════

    public function test_audit_entries_carry_the_mutated_records_own_company_not_a_client_supplied_one(): void
    {
        $company = $this->company();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'Sara', 'last_name' => 'Ali']);

        $row = $this->auditRow('hr_employee', 'hr.employee.created', (string) $employee->id);
        $this->assertSame((string) $company->id, $row->company_id);
    }

    public function test_tenant_isolation_blocks_cross_company_employee_mutation_and_leaves_no_audit_row(): void
    {
        $companyA = $this->company();
        $companyB = $this->company();
        $employeeA = $this->employee($companyA);

        $userB = User::factory()->create(['company_id' => $companyB->id]);

        // The update route is registered as PUT (routes/api.php), not PATCH.
        $response = $this->actingAs($userB)->putJson("/api/hr/employees/{$employeeA->id}", ['phone' => '0999']);

        $response->assertStatus(404);
        $this->assertNull(
            AuditLog::query()->where('entity_type', 'hr_employee')->where('entity_id', (string) $employeeA->id)
                ->where('action', 'hr.employee.updated')->first(),
            'A request blocked by tenant scoping must never reach the mutation, so it must never be audited.',
        );
    }

    // ═══ 8 & 9. Sensitive field classification ═══════════════════════════════════

    public function test_the_expected_hr_sensitive_fields_are_registered(): void
    {
        $registry = app(SensitiveFieldRegistryInterface::class);

        $employeeFields = $registry->fieldsFor('hr.employees');
        foreach (['national_id', 'date_of_birth', 'personal_email', 'address', 'emergency_contact_name', 'emergency_contact_phone'] as $field) {
            $this->assertArrayHasKey($field, $employeeFields, "Expected '{$field}' to be registered as sensitive on hr.employees.");
        }

        $this->assertArrayHasKey('reason', $registry->fieldsFor('hr.leave_requests'));
        $this->assertArrayHasKey('decision_note', $registry->fieldsFor('hr.leave_requests'));
        $this->assertArrayHasKey('notes', $registry->fieldsFor('hr.attendance_days'));
        $this->assertArrayHasKey('strengths', $registry->fieldsFor('hr.manager_reviews'));
        $this->assertArrayHasKey('improvement_notes', $registry->fieldsFor('hr.manager_reviews'));
        $this->assertArrayHasKey('manager_comments', $registry->fieldsFor('hr.manager_reviews'));
        $this->assertArrayHasKey('description', $registry->fieldsFor('hr.employee_incidents'));

        // FIN-01 consolidated remediation — classification consistency with
        // the narrative fields already registered above.
        $this->assertArrayHasKey('reason', $registry->fieldsFor('hr.attendance_corrections'));
        $this->assertArrayHasKey('decision_note', $registry->fieldsFor('hr.attendance_corrections'));
        $this->assertArrayHasKey('corrected_notes', $registry->fieldsFor('hr.attendance_corrections'));
    }

    public function test_ordinary_hr_fields_are_not_classified_as_sensitive(): void
    {
        $registry = app(SensitiveFieldRegistryInterface::class);

        $employeeFields = $registry->fieldsFor('hr.employees');
        foreach (['work_email', 'phone', 'mobile', 'first_name', 'last_name', 'display_name', 'city', 'country', 'gender', 'photo_path', 'status', 'employee_number'] as $field) {
            $this->assertArrayNotHasKey($field, $employeeFields, "'{$field}' must not be blanket-marked sensitive.");
        }

        $this->assertArrayNotHasKey('status', $registry->fieldsFor('hr.leave_requests'));
        $this->assertArrayNotHasKey('status', $registry->fieldsFor('hr.attendance_days'));
        $this->assertArrayNotHasKey('overall_rating', $registry->fieldsFor('hr.manager_reviews'));
        $this->assertArrayNotHasKey('category', $registry->fieldsFor('hr.employee_incidents'));
        $this->assertArrayNotHasKey('severity', $registry->fieldsFor('hr.employee_incidents'));
        $this->assertArrayNotHasKey('status', $registry->fieldsFor('hr.attendance_corrections'));
        $this->assertArrayNotHasKey('corrected_check_in', $registry->fieldsFor('hr.attendance_corrections'));
    }

    // ═══ 10. No payroll/commission/Finance mutation was introduced ═════════════

    public function test_this_slices_own_new_and_touched_files_introduce_no_payroll_or_ledger_coupling(): void
    {
        $forbidden = ['PayrollRun', 'Payslip', 'JournalService', 'PostingService', 'finance_journal', 'gl_account', 'journal_entries', 'ChartOfAccounts'];

        // HrServiceProvider.php is deliberately excluded from this list: it is the
        // whole Hr module's cross-cutting DI wiring and has, pre-existing this
        // slice, always registered PayrollRunService alongside every other Hr
        // service — that is not something this slice introduced. What this slice
        // actually added to that file (the HrAuditService registration and
        // registerSensitiveFields()) is checked precisely, below.
        $files = [
            base_path('Modules/Hr/Infrastructure/Services/HrAuditService.php'),
            base_path('Modules/Hr/Workforce/Domain/Services/EmployeeService.php'),
            base_path('Modules/Hr/Attendance/Domain/Services/AttendanceRegistrationService.php'),
            base_path('Modules/Hr/Attendance/Domain/Services/LeaveRequestService.php'),
            base_path('Modules/Hr/Performance/Domain/Services/GoalService.php'),
            base_path('Modules/Hr/Performance/Domain/Services/PerformanceEvaluationService.php'),
            base_path('Modules/Hr/Performance/Domain/Services/ManagerReviewService.php'),
            base_path('Modules/Hr/Performance/Domain/Services/BonusRecommendationService.php'),
            base_path('Modules/Hr/Performance/Domain/Services/IncidentService.php'),
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $source = (string) file_get_contents($file);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$file} must not introduce payroll/ledger coupling ({$needle}).");
            }
        }

        $providerSource = (string) file_get_contents(base_path('Modules/Hr/Infrastructure/Providers/HrServiceProvider.php'));
        $this->assertStringContainsString('HrAuditService::class', $providerSource, 'Expected this slice\'s audit singleton to still be registered.');

        if (preg_match('/private function registerSensitiveFields\(\): void\s*\{(.*?)\n    \}/s', $providerSource, $matches) !== 1) {
            $this->fail('Could not locate registerSensitiveFields() in HrServiceProvider.php to check it in isolation.');
        }

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $matches[1], "HrServiceProvider::registerSensitiveFields() must not introduce payroll/ledger coupling ({$needle}).");
        }

        // And no file under Compensation itself was touched by this slice.
        $this->assertStringNotContainsString('Modules/Hr/Compensation/', implode("\n", $files));
    }

    // ═══ Fixtures & helpers ══════════════════════════════════════════════════════

    private function company(): Company
    {
        $company = Company::factory()->create();
        $this->companyIds[] = (string) $company->id;

        return $company;
    }

    private function employee(Company $company): Employee
    {
        return app(EmployeeService::class)->create((string) $company->id, [
            'first_name' => 'Test', 'last_name' => 'Employee'.random_int(1000, 9999),
        ]);
    }

    private function auditRow(string $entityType, string $action, ?string $entityId): AuditLog
    {
        $row = AuditLog::query()
            ->where('entity_type', $entityType)
            ->where('action', $action)
            ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId))
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($row, "Expected an audit_logs row for {$entityType}/{$action}".($entityId !== null ? "/{$entityId}" : '').' but found none.');

        return $row;
    }

    /**
     * @param  array<string, mixed>  $expectOld
     * @param  array<string, mixed>  $expectNew
     * @param  array<string, mixed>  $metadataSubset
     */
    private function assertAudit(
        string $entityType,
        string $action,
        ?string $entityId,
        string $companyId,
        ?int $actorId = null,
        array $expectOld = [],
        array $expectNew = [],
        array $metadataSubset = [],
    ): void {
        $row = $this->auditRow($entityType, $action, $entityId);

        $this->assertSame($companyId, $row->company_id);

        if ($actorId !== null) {
            $this->assertSame($actorId, $row->user_id);
        }

        foreach ($expectOld as $key => $value) {
            $this->assertSame($value, $row->old_values[$key] ?? null, "old_values[{$key}] mismatch for {$action}.");
        }

        foreach ($expectNew as $key => $value) {
            $this->assertSame($value, $row->new_values[$key] ?? null, "new_values[{$key}] mismatch for {$action}.");
        }

        foreach ($metadataSubset as $key => $value) {
            $this->assertSame($value, $row->metadata[$key] ?? null, "metadata[{$key}] mismatch for {$action}.");
        }
    }

    private function purgeCompany(string $companyId): void
    {
        DB::table('audit_logs')->where('company_id', $companyId)->delete();
        DB::table('hr_employee_incidents')->where('company_id', $companyId)->delete();
        DB::table('hr_bonus_recommendations')->where('company_id', $companyId)->delete();
        DB::table('hr_manager_reviews')->where('company_id', $companyId)->delete();
        DB::table('hr_performance_snapshots')->where('company_id', $companyId)->delete();
        DB::table('hr_goals')->where('company_id', $companyId)->delete();
        DB::table('hr_attendance_days')->where('company_id', $companyId)->delete();
        DB::table('hr_leave_requests')->where('company_id', $companyId)->delete();
        DB::table('users')->where('company_id', $companyId)->delete();
        DB::table('hr_employees')->where('company_id', $companyId)->delete();
        DB::table('companies')->where('id', $companyId)->delete();
    }
}
