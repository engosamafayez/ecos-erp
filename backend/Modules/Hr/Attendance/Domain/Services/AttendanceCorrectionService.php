<?php

declare(strict_types=1);

namespace Modules\Hr\Attendance\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Hr\Attendance\Domain\Enums\CorrectionStatus;
use Modules\Hr\Attendance\Domain\Exceptions\AttendanceException;
use Modules\Hr\Attendance\Domain\Models\AttendanceCorrection;
use Modules\Hr\Attendance\Domain\Models\AttendanceDay;
use Modules\Hr\Infrastructure\Services\HrAuditService;

/**
 * The canonical Attendance correction workflow.
 *
 * ┌─ A REQUEST, THEN ONE DECISION — NEVER A SILENT EDIT ────────────────────┐
 * │ request() only ever writes a Pending AttendanceCorrection row; the         │
 * │ target AttendanceDay is untouched until approve() runs. Rejecting or       │
 * │ cancelling likewise never touches it. Approving applies the correction     │
 * │ through AttendanceRegistrationService::register() — the SAME canonical     │
 * │ mutation-and-audit path every other attendance write already uses — so     │
 * │ the closed 045B audit contract (hr.attendance_day.corrected) covers this    │
 * │ exactly as it covers a supervisor's own re-registration, and there is no     │
 * │ second attendance-mutation code path to keep in sync.                      │
 * │                                                                            │
 * │ A correction's own decision (approved/rejected/cancelled) is a SEPARATE,   │
 * │ additionally-audited fact from the resulting attendance mutation.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class AttendanceCorrectionService
{
    public function __construct(
        private readonly AttendanceRegistrationService $attendance,
        private readonly HrAuditService $audit,
    ) {}

    /**
     * @param  array{status?: string, check_in?: ?string, check_out?: ?string, notes?: ?string, reason: string}  $data
     */
    public function request(AttendanceDay $day, array $data, int $requestedBy): AttendanceCorrection
    {
        return DB::transaction(function () use ($day, $data, $requestedBy): AttendanceCorrection {
            $correction = AttendanceCorrection::create([
                'company_id' => $day->company_id,
                'employee_id' => $day->employee_id,
                'attendance_day_id' => $day->id,
                'original_status' => $day->status->value,
                'original_check_in' => $day->check_in,
                'original_check_out' => $day->check_out,
                'corrected_status' => $data['status'] ?? $day->status->value,
                'corrected_check_in' => $data['check_in'] ?? $day->check_in,
                'corrected_check_out' => $data['check_out'] ?? $day->check_out,
                'corrected_notes' => $data['notes'] ?? $day->notes,
                'reason' => $data['reason'],
                'status' => CorrectionStatus::Pending->value,
                'requested_by' => $requestedBy,
            ]);

            $this->audit->log(
                action: 'hr.attendance_correction.requested',
                entityType: HrAuditService::ENTITY_ATTENDANCE_CORRECTION,
                entityId: (string) $correction->id,
                companyId: (string) $day->company_id,
                actorId: $requestedBy,
                newValues: $correction->only(['corrected_status', 'corrected_check_in', 'corrected_check_out', 'corrected_notes', 'reason']),
                metadata: ['attendance_day_id' => (string) $day->id, 'employee_id' => (string) $day->employee_id],
            );

            return $correction;
        });
    }

    /** Applies the proposed values onto the canonical AttendanceDay, atomically with the decision. */
    public function approve(AttendanceCorrection $correction, ?int $decidedBy, ?string $note = null): AttendanceCorrection
    {
        $this->assertTransition($correction, CorrectionStatus::Approved);

        return DB::transaction(function () use ($correction, $decidedBy, $note): AttendanceCorrection {
            $day = $correction->attendanceDay;
            $employee = $correction->employee;

            $this->attendance->register(
                $employee,
                $day->work_date->toDateString(),
                $correction->corrected_status,
                [
                    // Preserve the day's own shift attribution — a correction
                    // fixes check-in/check-out/status/notes, it does not
                    // silently re-attribute which shift the day belongs to.
                    'shift_id' => $day->shift_id,
                    'check_in' => $correction->corrected_check_in,
                    'check_out' => $correction->corrected_check_out,
                    'notes' => $correction->corrected_notes,
                    'leave_request_id' => $day->leave_request_id,
                ],
                $decidedBy,
            );

            $correction->update([
                'status' => CorrectionStatus::Approved->value,
                'decided_by' => $decidedBy,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ]);

            $this->auditDecision($correction, 'approved', $decidedBy);

            return $correction->refresh();
        });
    }

    /** Rejecting never touches the target AttendanceDay. */
    public function reject(AttendanceCorrection $correction, ?int $decidedBy, ?string $note = null): AttendanceCorrection
    {
        $this->assertTransition($correction, CorrectionStatus::Rejected);

        return DB::transaction(function () use ($correction, $decidedBy, $note): AttendanceCorrection {
            $correction->update([
                'status' => CorrectionStatus::Rejected->value,
                'decided_by' => $decidedBy,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ]);

            $this->auditDecision($correction, 'rejected', $decidedBy);

            return $correction->refresh();
        });
    }

    /** Cancelling never touches the target AttendanceDay. */
    public function cancel(AttendanceCorrection $correction, ?int $actorId): AttendanceCorrection
    {
        $this->assertTransition($correction, CorrectionStatus::Cancelled);

        return DB::transaction(function () use ($correction, $actorId): AttendanceCorrection {
            $correction->update(['status' => CorrectionStatus::Cancelled->value]);

            $this->auditDecision($correction, 'cancelled', $actorId);

            return $correction->refresh();
        });
    }

    private function auditDecision(AttendanceCorrection $correction, string $verb, ?int $actorId): void
    {
        $this->audit->log(
            action: "hr.attendance_correction.{$verb}",
            entityType: HrAuditService::ENTITY_ATTENDANCE_CORRECTION,
            entityId: (string) $correction->id,
            companyId: (string) $correction->company_id,
            actorId: $actorId,
            oldValues: ['status' => CorrectionStatus::Pending->value],
            newValues: ['status' => $correction->status->value],
            metadata: ['attendance_day_id' => (string) $correction->attendance_day_id],
        );
    }

    private function assertTransition(AttendanceCorrection $correction, CorrectionStatus $target): void
    {
        if (! $correction->status->canTransitionTo($target)) {
            throw AttendanceException::correctionNotPending($correction->status->value, $target->value);
        }
    }
}
