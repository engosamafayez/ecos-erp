<?php

declare(strict_types=1);

namespace Modules\Hr\Attendance\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Hr\Attendance\Domain\Enums\AttendanceStatus;
use Modules\Hr\Attendance\Domain\Models\AttendanceDay;
use Modules\Hr\Attendance\Domain\Models\Shift;
use Modules\Hr\Workforce\Domain\Models\Employee;

/**
 * FIN-01 — the ONE canonical Late / Early-Leave / Worked-Time / outcome
 * derivation path (§12: "avoid multiple copies of Late calculation").
 *
 * ┌─ NEVER PERSISTED, NEVER GUESSED ─────────────────────────────────────────┐
 * │ Every value here is computed fresh from the canonical AttendanceDay row    │
 * │ (status/check_in/check_out) and the employee's schedule effective ON THAT  │
 * │ DATE — never stored back onto the row, never inferred from historical      │
 * │ check-ins, never a hard-coded company-wide hour. A correction that changes │
 * │ check-in/check-out therefore "recomputes" simply by being read again: there│
 * │ is no second, now-stale copy anywhere to invalidate.                       │
 * │                                                                            │
 * │ When an authoritative input is missing (no assigned shift, no check-in, no │
 * │ recorded day at all), the result says so explicitly — never a guessed      │
 * │ On Time/Late/Absent/zero.                                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class AttendanceDerivationService
{
    public const OUTCOME_UNRESOLVED = 'unresolved';

    /** Shared across late/early-leave: both are "actual vs. one scheduled boundary" checks. */
    public const STATUS_ON_TIME = 'on_time';

    public const STATUS_NOT_EVALUATED = 'not_evaluated';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const LATE_LATE = 'late';

    public const EARLY_LEAVE_EARLY = 'early';

    public const WORKED_TIME_AVAILABLE = 'available';

    public function __construct(
        private readonly WorkScheduleService $schedule,
        private readonly HolidayService $holidays,
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     late: array{status: string, minutes_late: int|null, grace_minutes: int|null},
     *     early_leave: array{status: string, minutes_early: int|null},
     *     worked_time: array{status: string, gross_minutes: int|null, net_minutes: int|null, break_minutes_deducted: int|null},
     * }
     */
    public function derive(Employee $employee, Carbon $workDate, ?AttendanceDay $day): array
    {
        if ($day === null) {
            return $this->deriveWithoutRecord($employee, $workDate);
        }

        if (! $day->status->isWorked()) {
            return $this->nonWorkedResult($day->status->value);
        }

        return $this->workedResult($day, $this->schedule->effectiveShiftFor($employee, $workDate));
    }

    /**
     * Bulk form of derive() for a list of attendance rows (e.g. a date-range
     * history read): resolves every row's effective shift in ONE bounded set
     * of queries via WorkScheduleService::effectiveShiftsFor() — never one
     * EmployeeShiftAssignment query per row — then applies the exact same
     * per-row derivation derive() itself uses, so the two paths can never
     * disagree.
     *
     * @param  Collection<int, AttendanceDay>  $days  each with its `employee` relation already loaded
     * @return array<string, array{outcome: string, late: array<string, mixed>, early_leave: array<string, mixed>, worked_time: array<string, mixed>}|null> keyed by AttendanceDay::id — null only when the row's employee relation is missing
     */
    public function deriveMany(Collection $days): array
    {
        $pairs = [];
        foreach ($days as $day) {
            if ($day->employee !== null && $day->status->isWorked()) {
                $pairs[] = ['employee_id' => (string) $day->employee_id, 'date' => $day->work_date];
            }
        }

        $shifts = $this->schedule->effectiveShiftsFor($pairs);

        $out = [];
        foreach ($days as $day) {
            if ($day->employee === null) {
                $out[$day->id] = null;

                continue;
            }

            if (! $day->status->isWorked()) {
                $out[$day->id] = $this->nonWorkedResult($day->status->value);

                continue;
            }

            $key = $day->employee_id.'|'.$day->work_date->toDateString();
            $out[$day->id] = $this->workedResult($day, $shifts[$key] ?? null);
        }

        return $out;
    }

    /** @return array{outcome: string, late: array<string, mixed>, early_leave: array<string, mixed>, worked_time: array<string, mixed>} */
    private function nonWorkedResult(string $status): array
    {
        // Leave / Holiday / Rest day / an explicitly recorded Absence: the
        // status itself is the whole answer. Time semantics do not apply to
        // a day nobody was expected to (or did not) work.
        return [
            'outcome' => $status,
            'late' => $this->notApplicableLate(),
            'early_leave' => $this->notApplicableEarlyLeave(),
            'worked_time' => $this->notApplicableWorkedTime(),
        ];
    }

    /** @return array{outcome: string, late: array<string, mixed>, early_leave: array<string, mixed>, worked_time: array<string, mixed>} */
    private function workedResult(AttendanceDay $day, ?Shift $shift): array
    {
        return [
            'outcome' => $day->status->value,
            'late' => $this->deriveLate($day->work_date, $day->check_in, $shift),
            'early_leave' => $this->deriveEarlyLeave($day->work_date, $day->check_out, $shift),
            'worked_time' => $this->deriveWorkedTime($day->work_date, $day->check_in, $day->check_out, $shift),
        ];
    }

    /**
     * No AttendanceDay row exists at all for this employee/date. Absence is
     * presented — never written — only when there is enough evidence: a
     * scheduled working day, and the date has already closed (§11). A
     * today-or-future date without a record is unresolved, never a guessed
     * absence, since the day may still be worked or registered.
     *
     * @return array{outcome: string, late: array{status: string, minutes_late: int|null, grace_minutes: int|null}, early_leave: array{status: string, minutes_early: int|null}, worked_time: array{status: string, gross_minutes: int|null, net_minutes: int|null, break_minutes_deducted: int|null}}
     */
    private function deriveWithoutRecord(Employee $employee, Carbon $workDate): array
    {
        $companyId = (string) $employee->company_id;

        if (! $workDate->startOfDay()->lessThan(Carbon::now()->startOfDay())) {
            return [
                'outcome' => self::OUTCOME_UNRESOLVED,
                'late' => $this->notApplicableLate(),
                'early_leave' => $this->notApplicableEarlyLeave(),
                'worked_time' => $this->notApplicableWorkedTime(),
            ];
        }

        if ($this->holidays->isHoliday($companyId, $workDate)) {
            $outcome = AttendanceStatus::Holiday->value;
        } elseif (! $this->schedule->isWorkingDay($companyId, $workDate)) {
            $outcome = AttendanceStatus::RestDay->value;
        } else {
            // A scheduled working day, already closed, with no attendance
            // record of any kind — the one case §11 authorizes presenting as
            // Absent without a row ever having been written.
            $outcome = AttendanceStatus::Absent->value;
        }

        return [
            'outcome' => $outcome,
            'late' => $this->notApplicableLate(),
            'early_leave' => $this->notApplicableEarlyLeave(),
            'worked_time' => $this->notApplicableWorkedTime(),
        ];
    }

    /** @return array{status: string, minutes_late: int|null, grace_minutes: int|null} */
    private function deriveLate(Carbon $workDate, ?string $checkIn, ?Shift $shift): array
    {
        if ($shift === null) {
            return ['status' => self::STATUS_NOT_EVALUATED, 'minutes_late' => null, 'grace_minutes' => null];
        }

        if ($checkIn === null) {
            return ['status' => self::STATUS_NOT_EVALUATED, 'minutes_late' => null, 'grace_minutes' => $shift->late_grace_minutes];
        }

        $scheduledStart = $this->at($workDate, $shift->start_time);
        $actualStart = $this->at($workDate, $checkIn);
        $boundary = $scheduledStart->copy()->addMinutes($shift->late_grace_minutes);

        // diffInMinutes() defaults to an absolute (unsigned) distance, so the
        // "arrived before scheduled start" case is handled explicitly rather
        // than trusting a signed-diff sign convention.
        $minutesLate = $actualStart->greaterThan($scheduledStart) ? $scheduledStart->diffInMinutes($actualStart) : 0;

        return [
            'status' => $actualStart->greaterThan($boundary) ? self::LATE_LATE : self::STATUS_ON_TIME,
            'minutes_late' => $minutesLate,
            'grace_minutes' => $shift->late_grace_minutes,
        ];
    }

    /** @return array{status: string, minutes_early: int|null} */
    private function deriveEarlyLeave(Carbon $workDate, ?string $checkOut, ?Shift $shift): array
    {
        if ($shift === null || $checkOut === null) {
            return ['status' => self::STATUS_NOT_EVALUATED, 'minutes_early' => null];
        }

        $scheduledEnd = $this->at($workDate, $shift->end_time);
        if ($shift->crosses_midnight) {
            $scheduledEnd = $scheduledEnd->addDay();
        }

        $actualEnd = $this->at($workDate, $checkOut);
        if ($shift->crosses_midnight && $actualEnd->lessThan($this->at($workDate, $shift->start_time))) {
            // A time-of-day earlier than the shift's own start, on a
            // crosses-midnight shift, can only be the following calendar day.
            $actualEnd = $actualEnd->addDay();
        }

        // No approved early-leave grace configuration exists anywhere in the
        // current schedule authority (§9) — the boundary is exact, never a
        // borrowed or invented tolerance.
        if ($actualEnd->greaterThanOrEqualTo($scheduledEnd)) {
            return ['status' => self::STATUS_ON_TIME, 'minutes_early' => 0];
        }

        return ['status' => self::EARLY_LEAVE_EARLY, 'minutes_early' => $scheduledEnd->diffInMinutes($actualEnd)];
    }

    /** @return array{status: string, gross_minutes: int|null, net_minutes: int|null, break_minutes_deducted: int|null} */
    private function deriveWorkedTime(Carbon $workDate, ?string $checkIn, ?string $checkOut, ?Shift $shift): array
    {
        if ($checkIn === null || $checkOut === null) {
            return ['status' => self::STATUS_NOT_EVALUATED, 'gross_minutes' => null, 'net_minutes' => null, 'break_minutes_deducted' => null];
        }

        $start = $this->at($workDate, $checkIn);
        $end = $this->at($workDate, $checkOut);

        if ($end->lessThan($start)) {
            // Only a shift explicitly marked crosses_midnight authorizes
            // treating this as an overnight span. Without that confirmation,
            // guessing which calendar day check-out belongs to is exactly
            // the kind of inference §6/§10 forbid.
            if ($shift?->crosses_midnight !== true) {
                return ['status' => self::STATUS_NOT_EVALUATED, 'gross_minutes' => null, 'net_minutes' => null, 'break_minutes_deducted' => null];
            }
            $end = $end->addDay();
        }

        $gross = $start->diffInMinutes($end);
        $breakMinutes = $shift?->break_minutes ?? null;
        $net = $breakMinutes !== null ? max(0, $gross - $breakMinutes) : $gross;

        return [
            'status' => self::WORKED_TIME_AVAILABLE,
            'gross_minutes' => $gross,
            'net_minutes' => $net,
            'break_minutes_deducted' => $breakMinutes,
        ];
    }

    /** Combine a calendar date with a bare "H:i:s" time-of-day into one comparable instant. */
    private function at(Carbon $workDate, string $time): Carbon
    {
        return Carbon::parse($workDate->toDateString().' '.$time);
    }

    /** @return array{status: string, minutes_late: null, grace_minutes: null} */
    private function notApplicableLate(): array
    {
        return ['status' => self::STATUS_NOT_APPLICABLE, 'minutes_late' => null, 'grace_minutes' => null];
    }

    /** @return array{status: string, minutes_early: null} */
    private function notApplicableEarlyLeave(): array
    {
        return ['status' => self::STATUS_NOT_APPLICABLE, 'minutes_early' => null];
    }

    /** @return array{status: string, gross_minutes: null, net_minutes: null, break_minutes_deducted: null} */
    private function notApplicableWorkedTime(): array
    {
        return ['status' => self::STATUS_NOT_APPLICABLE, 'gross_minutes' => null, 'net_minutes' => null, 'break_minutes_deducted' => null];
    }
}
