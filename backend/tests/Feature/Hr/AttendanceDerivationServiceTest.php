<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Hr\Attendance\Domain\Enums\AttendanceStatus;
use Modules\Hr\Attendance\Domain\Models\AttendanceDay;
use Modules\Hr\Attendance\Domain\Models\OfficialHoliday;
use Modules\Hr\Attendance\Domain\Models\Shift;
use Modules\Hr\Attendance\Domain\Models\WorkCalendar;
use Modules\Hr\Attendance\Domain\Services\AttendanceDerivationService;
use Modules\Hr\Attendance\Domain\Services\AttendanceRegistrationService;
use Modules\Hr\Attendance\Domain\Services\WorkScheduleService;
use Modules\Hr\Workforce\Domain\Models\Employee;
use Modules\Hr\Workforce\Domain\Services\EmployeeService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * FIN-01 — the one canonical Late / Early-Leave / Worked-Time / outcome
 * derivation path. Every case here is deterministic: no hard-coded hours, no
 * inference from historical check-ins, honest unavailable states throughout.
 */
class AttendanceDerivationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function company(): Company
    {
        return Company::factory()->create();
    }

    private function employeeWithShift(
        Company $company,
        string $start,
        string $end,
        int $graceMinutes = 0,
        bool $crossesMidnight = false,
        int $breakMinutes = 30,
    ): Employee {
        $employee = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);

        $calendar = WorkCalendar::create([
            'company_id' => $company->id,
            'code' => 'cal-'.uniqid(),
            'name' => 'Calendar',
            'working_days' => [1, 2, 3, 4, 5],
            'is_default' => true,
            'is_active' => true,
        ]);

        $shift = Shift::create([
            'company_id' => $company->id,
            'work_calendar_id' => $calendar->id,
            'code' => 'shift-'.uniqid(),
            'name' => 'Shift',
            'start_time' => $start,
            'end_time' => $end,
            'break_minutes' => $breakMinutes,
            'late_grace_minutes' => $graceMinutes,
            'crosses_midnight' => $crossesMidnight,
            'is_active' => true,
        ]);

        app(WorkScheduleService::class)->assignShift($employee, $shift, '2020-01-01');

        return $employee;
    }

    private function dayFor(Employee $employee, string $workDate, AttendanceStatus $status, ?string $checkIn = null, ?string $checkOut = null): AttendanceDay
    {
        return app(AttendanceRegistrationService::class)->register($employee, $workDate, $status, [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);
    }

    // ═══ LATE ═════════════════════════════════════════════════════════════════

    public function test_on_time_check_in_within_grace(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00', graceMinutes: 10);
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:07:00', '17:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::STATUS_ON_TIME, $result['late']['status']);
        $this->assertSame(7, $result['late']['minutes_late']);
    }

    public function test_late_after_grace(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00', graceMinutes: 10);
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:11:00', '17:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::LATE_LATE, $result['late']['status']);
        $this->assertSame(11, $result['late']['minutes_late']);
    }

    public function test_exact_grace_boundary_is_still_on_time(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00', graceMinutes: 10);
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:10:00', '17:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::STATUS_ON_TIME, $result['late']['status'], 'The grace boundary itself must still count as on time, not late.');
    }

    public function test_missing_schedule_makes_late_not_evaluated(): void
    {
        $employee = app(EmployeeService::class)->create((string) $this->company()->id, ['first_name' => 'No', 'last_name' => 'Shift']);
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:07:00', '17:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::STATUS_NOT_EVALUATED, $result['late']['status']);
    }

    public function test_no_check_in_makes_late_not_evaluated_even_with_a_schedule(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00');
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, null, '17:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::STATUS_NOT_EVALUATED, $result['late']['status']);
    }

    // ═══ WORKED TIME ══════════════════════════════════════════════════════════

    public function test_worked_time_without_a_schedule_still_computes_from_check_times(): void
    {
        $employee = app(EmployeeService::class)->create((string) $this->company()->id, ['first_name' => 'No', 'last_name' => 'Shift']);
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:00:00', '17:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::WORKED_TIME_AVAILABLE, $result['worked_time']['status']);
        $this->assertSame(480, $result['worked_time']['gross_minutes']);
        $this->assertNull($result['worked_time']['break_minutes_deducted'], 'No shift means no authoritative break to deduct — never invented.');
        $this->assertSame(480, $result['worked_time']['net_minutes']);
    }

    public function test_worked_time_with_a_shift_deducts_its_configured_break(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00', breakMinutes: 30);
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:00:00', '17:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(480, $result['worked_time']['gross_minutes']);
        $this->assertSame(30, $result['worked_time']['break_minutes_deducted']);
        $this->assertSame(450, $result['worked_time']['net_minutes']);
    }

    public function test_missing_check_out_makes_worked_time_not_evaluated(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00');
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:00:00', null);

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::STATUS_NOT_EVALUATED, $result['worked_time']['status']);
        $this->assertNull($result['worked_time']['gross_minutes']);
    }

    // ═══ EARLY LEAVE ══════════════════════════════════════════════════════════

    public function test_early_leave_before_scheduled_end(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00');
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:00:00', '16:30:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::EARLY_LEAVE_EARLY, $result['early_leave']['status']);
        $this->assertSame(30, $result['early_leave']['minutes_early']);
    }

    public function test_check_out_at_exact_scheduled_end_is_on_time_never_early(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00');
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:00:00', '17:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::STATUS_ON_TIME, $result['early_leave']['status']);
    }

    // ═══ CROSSES MIDNIGHT ═════════════════════════════════════════════════════

    public function test_crosses_midnight_shift_computes_late_early_and_worked_time_correctly(): void
    {
        $employee = $this->employeeWithShift($this->company(), '22:00:00', '06:00:00', graceMinutes: 5, crossesMidnight: true, breakMinutes: 30);
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '22:10:00', '06:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(AttendanceDerivationService::LATE_LATE, $result['late']['status']);
        $this->assertSame(10, $result['late']['minutes_late']);
        $this->assertSame(AttendanceDerivationService::STATUS_ON_TIME, $result['early_leave']['status']);
        // 22:10 -> next-day 06:00 = 7h50m = 470 minutes gross, minus the 30-minute break.
        $this->assertSame(470, $result['worked_time']['gross_minutes']);
        $this->assertSame(440, $result['worked_time']['net_minutes']);
    }

    public function test_an_overnight_span_with_no_shift_to_confirm_crossing_is_not_evaluated(): void
    {
        $employee = app(EmployeeService::class)->create((string) $this->company()->id, ['first_name' => 'No', 'last_name' => 'Shift']);
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '22:00:00', '06:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame(
            AttendanceDerivationService::STATUS_NOT_EVALUATED,
            $result['worked_time']['status'],
            'Without a shift confirming crosses_midnight, an apparently-negative span must never be guessed as overnight.',
        );
    }

    // ═══ NON-WORKING STATUSES ═════════════════════════════════════════════════

    public function test_leave_status_has_no_time_semantics(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00');
        $day = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Leave);

        $result = app(AttendanceDerivationService::class)->derive($employee, $day->work_date, $day);

        $this->assertSame('leave', $result['outcome']);
        $this->assertSame(AttendanceDerivationService::STATUS_NOT_APPLICABLE, $result['late']['status']);
        $this->assertSame(AttendanceDerivationService::STATUS_NOT_APPLICABLE, $result['worked_time']['status']);
    }

    // ═══ ABSENCE EVIDENCE (no AttendanceDay row at all) ═════════════════════════

    public function test_past_scheduled_working_day_with_no_record_reads_as_absent(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00');
        // 2026-01-05 is a Monday — a working day under [1,2,3,4,5].
        $workDate = \Illuminate\Support\Carbon::parse('2026-01-05');

        $result = app(AttendanceDerivationService::class)->derive($employee, $workDate, null);

        $this->assertSame('absent', $result['outcome']);
    }

    public function test_past_rest_day_with_no_record_is_rest_day_never_absent(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00');
        // 2026-01-10 is a Saturday — not in the [1,2,3,4,5] calendar.
        $workDate = \Illuminate\Support\Carbon::parse('2026-01-10');

        $result = app(AttendanceDerivationService::class)->derive($employee, $workDate, null);

        $this->assertSame('rest_day', $result['outcome']);
    }

    public function test_past_holiday_with_no_record_is_holiday_never_absent(): void
    {
        $company = $this->company();
        $employee = $this->employeeWithShift($company, '09:00:00', '17:00:00');
        OfficialHoliday::create([
            'company_id' => $company->id,
            'name' => 'Test Holiday',
            'start_date' => '2026-01-06',
            'end_date' => '2026-01-06',
            'type' => 'public',
            'is_active' => true,
        ]);

        $result = app(AttendanceDerivationService::class)->derive($employee, \Illuminate\Support\Carbon::parse('2026-01-06'), null);

        $this->assertSame('holiday', $result['outcome']);
    }

    public function test_unresolved_today_with_no_record_is_never_absent(): void
    {
        $employee = $this->employeeWithShift($this->company(), '09:00:00', '17:00:00');

        $result = app(AttendanceDerivationService::class)->derive($employee, \Illuminate\Support\Carbon::now()->startOfDay(), null);

        $this->assertSame(AttendanceDerivationService::OUTCOME_UNRESOLVED, $result['outcome']);
    }

    // ═══ Bulk shift resolution (FIN-01 consolidated remediation) ════════════════

    public function test_deriving_many_rows_uses_one_bounded_set_of_shift_queries(): void
    {
        $company = $this->company();
        $dayIds = [];
        foreach (range(1, 5) as $i) {
            $employee = $this->employeeWithShift($company, '09:00:00', '17:00:00', graceMinutes: 10);
            $dayIds[] = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:07:00', '17:00:00')->id;
        }

        // Reloaded with `employee` eager-loaded, exactly as the controller's
        // index() query does — never a lazy per-row relation load either.
        $days = AttendanceDay::query()->with('employee')->whereIn('id', $dayIds)->get();

        DB::enableQueryLog();
        $derived = app(AttendanceDerivationService::class)->deriveMany($days);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(5, $derived);
        foreach ($dayIds as $id) {
            $this->assertSame(AttendanceDerivationService::STATUS_ON_TIME, $derived[$id]['late']['status']);
        }
        $this->assertSame(1, $queries, 'Bulk derivation must issue exactly one EmployeeShiftAssignment query, never one per row.');
    }

    public function test_bulk_resolution_matches_single_row_resolution_across_a_shift_reassignment(): void
    {
        $company = $this->company();
        $employee = app(EmployeeService::class)
            ->create((string) $company->id, ['first_name' => 'E', 'last_name' => 'X']);

        $morningShift = Shift::create([
            'company_id' => $company->id, 'code' => 'morning-'.uniqid(), 'name' => 'Morning',
            'start_time' => '09:00:00', 'end_time' => '17:00:00', 'break_minutes' => 30, 'late_grace_minutes' => 5,
            'crosses_midnight' => false, 'is_active' => true,
        ]);
        $eveningShift = Shift::create([
            'company_id' => $company->id, 'code' => 'evening-'.uniqid(), 'name' => 'Evening',
            'start_time' => '14:00:00', 'end_time' => '22:00:00', 'break_minutes' => 30, 'late_grace_minutes' => 5,
            'crosses_midnight' => false, 'is_active' => true,
        ]);

        $schedule = app(WorkScheduleService::class);
        $schedule->assignShift($employee, $morningShift, '2026-01-01');
        $schedule->assignShift($employee, $eveningShift, '2026-01-10'); // closes the morning assignment at this date

        // Before the reassignment — must resolve against the morning shift.
        $beforeDay = $this->dayFor($employee, '2026-01-05', AttendanceStatus::Present, '09:07:00', '17:00:00');
        // After the reassignment — must resolve against the evening shift.
        $afterDay = $this->dayFor($employee, '2026-01-15', AttendanceStatus::Present, '14:07:00', '22:00:00');

        $service = app(AttendanceDerivationService::class);
        $single = [
            $beforeDay->id => $service->derive($employee, $beforeDay->work_date, $beforeDay),
            $afterDay->id => $service->derive($employee, $afterDay->work_date, $afterDay),
        ];

        $days = AttendanceDay::query()->with('employee')->whereIn('id', [$beforeDay->id, $afterDay->id])->get();
        $bulk = $service->deriveMany($days);

        $this->assertSame($single[$beforeDay->id], $bulk[$beforeDay->id], 'Bulk and single-row resolution must agree for the pre-reassignment date.');
        $this->assertSame($single[$afterDay->id], $bulk[$afterDay->id], 'Bulk and single-row resolution must agree for the post-reassignment date.');
        $this->assertSame(AttendanceDerivationService::STATUS_ON_TIME, $bulk[$beforeDay->id]['late']['status']);
        $this->assertSame(AttendanceDerivationService::STATUS_ON_TIME, $bulk[$afterDay->id]['late']['status']);
    }

    public function test_bulk_resolution_handles_multiple_employees_correctly(): void
    {
        $company = $this->company();
        $employeeA = $this->employeeWithShift($company, '09:00:00', '17:00:00', graceMinutes: 5);
        $employeeB = $this->employeeWithShift($company, '14:00:00', '22:00:00', graceMinutes: 5);

        // A is late against the 09:00 shift; B is on time against the 14:00 shift.
        $dayA = $this->dayFor($employeeA, '2026-01-05', AttendanceStatus::Present, '09:20:00', '17:00:00');
        $dayB = $this->dayFor($employeeB, '2026-01-05', AttendanceStatus::Present, '14:02:00', '22:00:00');

        $days = AttendanceDay::query()->with('employee')->whereIn('id', [$dayA->id, $dayB->id])->get();
        $derived = app(AttendanceDerivationService::class)->deriveMany($days);

        $this->assertSame(AttendanceDerivationService::LATE_LATE, $derived[$dayA->id]['late']['status']);
        $this->assertSame(AttendanceDerivationService::STATUS_ON_TIME, $derived[$dayB->id]['late']['status']);
    }
}
