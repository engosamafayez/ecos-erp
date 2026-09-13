<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Hr\Attendance\Domain\Enums\AttendanceStatus;
use Modules\Hr\Attendance\Domain\Enums\CorrectionStatus;
use Modules\Hr\Attendance\Domain\Exceptions\AttendanceException;
use Modules\Hr\Attendance\Domain\Services\AttendanceCorrectionService;
use Modules\Hr\Attendance\Domain\Services\AttendanceRegistrationService;
use Modules\Hr\Workforce\Domain\Services\EmployeeService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * FIN-01 — the canonical Attendance correction lifecycle: a request never
 * mutates the target row by itself; only an approval applies it, atomically,
 * through the existing canonical registration path.
 */
class AttendanceCorrectionServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_pending_correction_does_not_mutate_attendance(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );

        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Forgot to log the real time'], requestedBy: 42,
        );

        $this->assertSame(CorrectionStatus::Pending, $correction->status);
        $day->refresh();
        $this->assertSame('09:15:00', $day->check_in, 'A pending correction must not touch the canonical row.');
    }

    public function test_approve_applies_exactly_the_corrected_values(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );

        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Forgot to log the real time'], requestedBy: 42,
        );
        app(AttendanceCorrectionService::class)->approve($correction, decidedBy: 7, note: 'Confirmed with the supervisor');

        $day->refresh();
        $correction->refresh();

        $this->assertSame('09:00:00', $day->check_in);
        $this->assertSame('17:00:00', $day->check_out, 'A field not part of the correction must stay exactly as it was.');
        $this->assertSame(CorrectionStatus::Approved, $correction->status);
        $this->assertSame(7, $correction->decided_by);
        $this->assertNotNull($correction->decided_at);
        $this->assertSame('Confirmed with the supervisor', $correction->decision_note);
    }

    public function test_reject_does_not_mutate_attendance(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );
        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Disputed'], requestedBy: 42,
        );

        app(AttendanceCorrectionService::class)->reject($correction, decidedBy: 7, note: 'Not supported by evidence');

        $day->refresh();
        $correction->refresh();
        $this->assertSame('09:15:00', $day->check_in);
        $this->assertSame(CorrectionStatus::Rejected, $correction->status);
    }

    public function test_cancel_does_not_mutate_attendance(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );
        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Changed my mind'], requestedBy: 42,
        );

        app(AttendanceCorrectionService::class)->cancel($correction, actorId: 42);

        $day->refresh();
        $correction->refresh();
        $this->assertSame('09:15:00', $day->check_in);
        $this->assertSame(CorrectionStatus::Cancelled, $correction->status);
    }

    public function test_a_decided_correction_cannot_be_decided_again(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );
        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Fix'], requestedBy: 42,
        );

        app(AttendanceCorrectionService::class)->approve($correction, decidedBy: 7);

        $this->expectException(AttendanceException::class);
        app(AttendanceCorrectionService::class)->approve($correction->fresh(), decidedBy: 7);
    }

    public function test_rejecting_an_already_approved_correction_is_refused(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );
        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Fix'], requestedBy: 42,
        );
        app(AttendanceCorrectionService::class)->approve($correction, decidedBy: 7);

        $this->expectException(AttendanceException::class);
        app(AttendanceCorrectionService::class)->reject($correction->fresh(), decidedBy: 7);
    }

    public function test_original_values_are_preserved_after_approval(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );
        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Fix'], requestedBy: 42,
        );
        app(AttendanceCorrectionService::class)->approve($correction, decidedBy: 7);

        $correction->refresh();
        $this->assertSame('09:15:00', $correction->original_check_in, 'The original evidence must survive even after the correction is applied.');
        $this->assertSame('09:00:00', $correction->corrected_check_in);
        $this->assertSame(AttendanceStatus::Present, $correction->original_status);
    }

    // ═══ Self-decision (FIN-01 consolidated remediation) ════════════════════════

    public function test_requester_cannot_approve_their_own_correction(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );
        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Fix'], requestedBy: 42,
        );

        $this->expectException(AttendanceException::class);
        app(AttendanceCorrectionService::class)->approve($correction, decidedBy: 42);
    }

    public function test_requester_cannot_reject_their_own_correction(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );
        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Fix'], requestedBy: 42,
        );

        $this->expectException(AttendanceException::class);
        app(AttendanceCorrectionService::class)->reject($correction, decidedBy: 42);
    }

    public function test_requester_may_still_cancel_their_own_pending_correction(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);
        $day = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00', 'check_out' => '17:00:00'],
        );
        $correction = app(AttendanceCorrectionService::class)->request(
            $day, ['check_in' => '09:00:00', 'reason' => 'Changed my mind'], requestedBy: 42,
        );

        $cancelled = app(AttendanceCorrectionService::class)->cancel($correction, actorId: 42);

        $this->assertSame(CorrectionStatus::Cancelled, $cancelled->status, 'Self-cancel of a still-pending request must remain allowed.');
    }

    public function test_a_different_authorized_actor_can_approve_and_reject(): void
    {
        $company = Company::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);

        $dayA = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00'],
        );
        $approved = app(AttendanceCorrectionService::class)->request($dayA, ['check_in' => '09:00:00', 'reason' => 'Fix'], requestedBy: 42);
        $approved = app(AttendanceCorrectionService::class)->approve($approved, decidedBy: 7);
        $this->assertSame(CorrectionStatus::Approved, $approved->status);

        $dayB = app(AttendanceRegistrationService::class)->register(
            $employee, '2026-01-06', AttendanceStatus::Present, ['check_in' => '09:15:00'],
        );
        $rejected = app(AttendanceCorrectionService::class)->request($dayB, ['check_in' => '09:00:00', 'reason' => 'Fix'], requestedBy: 42);
        $rejected = app(AttendanceCorrectionService::class)->reject($rejected, decidedBy: 7);
        $this->assertSame(CorrectionStatus::Rejected, $rejected->status);
    }

    public function test_decisions_lock_the_correction_row_before_checking_its_state(): void
    {
        $source = (string) file_get_contents(
            base_path('Modules/Hr/Attendance/Domain/Services/AttendanceCorrectionService.php'),
        );

        $this->assertStringContainsString(
            'lockForUpdate()',
            $source,
            'Every decision must re-read the correction row with a row lock inside its own transaction, never trust the pre-transaction object.',
        );
    }

    public function test_a_correction_never_crosses_company_boundaries(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $employeeA = app(EmployeeService::class)->create((string) $companyA->id, ['first_name' => 'A', 'last_name' => 'One']);
        $dayA = app(AttendanceRegistrationService::class)->register(
            $employeeA, '2026-01-05', AttendanceStatus::Present, ['check_in' => '09:15:00'],
        );

        $correction = app(AttendanceCorrectionService::class)->request(
            $dayA, ['check_in' => '09:00:00', 'reason' => 'Fix'], requestedBy: 1,
        );

        $this->assertSame((string) $companyA->id, (string) $correction->company_id);
        $this->assertNotSame((string) $companyB->id, (string) $correction->company_id);
    }
}
