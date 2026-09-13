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
 *
 * ┌─ ONE DECISION MUST WIN, AND IT MUST NEVER BE THE REQUESTER'S OWN ───────┐
 * │ Every decision re-reads the correction row WITH lockForUpdate() inside the │
 * │ transaction and re-checks both the transition and the self-decision rule   │
 * │ against that freshly-locked state — never the possibly-stale object the    │
 * │ caller passed in. A second, concurrent decision on the same correction      │
 * │ blocks on the lock, then sees a row that is no longer Pending and fails     │
 * │ explicitly, so the correction's own status can never disagree with what     │
 * │ actually got applied to AttendanceDay. Self-CANCEL of a still-Pending own   │
 * │ request remains allowed — only approving/rejecting one's own request is    │
 * │ forbidden.                                                                 │
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
        return DB::transaction(function () use ($correction, $decidedBy, $note): AttendanceCorrection {
            $locked = $this->lockPending($correction, CorrectionStatus::Approved, $decidedBy);
            $day = $locked->attendanceDay;
            $employee = $locked->employee;

            $this->attendance->register(
                $employee,
                $day->work_date->toDateString(),
                $locked->corrected_status,
                [
                    // Preserve the day's own shift attribution — a correction
                    // fixes check-in/check-out/status/notes, it does not
                    // silently re-attribute which shift the day belongs to.
                    'shift_id' => $day->shift_id,
                    'check_in' => $locked->corrected_check_in,
                    'check_out' => $locked->corrected_check_out,
                    'notes' => $locked->corrected_notes,
                    'leave_request_id' => $day->leave_request_id,
                ],
                $decidedBy,
            );

            $locked->update([
                'status' => CorrectionStatus::Approved->value,
                'decided_by' => $decidedBy,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ]);

            $this->auditDecision($locked, 'approved', $decidedBy);

            return $locked->refresh();
        });
    }

    /** Rejecting never touches the target AttendanceDay. */
    public function reject(AttendanceCorrection $correction, ?int $decidedBy, ?string $note = null): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $decidedBy, $note): AttendanceCorrection {
            $locked = $this->lockPending($correction, CorrectionStatus::Rejected, $decidedBy);

            $locked->update([
                'status' => CorrectionStatus::Rejected->value,
                'decided_by' => $decidedBy,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ]);

            $this->auditDecision($locked, 'rejected', $decidedBy);

            return $locked->refresh();
        });
    }

    /** Cancelling never touches the target AttendanceDay. Self-cancel of one's own still-Pending request is allowed. */
    public function cancel(AttendanceCorrection $correction, ?int $actorId): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $actorId): AttendanceCorrection {
            $locked = $this->lockPending($correction, CorrectionStatus::Cancelled, $actorId);

            $locked->update(['status' => CorrectionStatus::Cancelled->value]);

            $this->auditDecision($locked, 'cancelled', $actorId);

            return $locked->refresh();
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

    /**
     * Re-fetch the correction WITH A ROW LOCK inside the caller's open
     * transaction, and verify the transition and the self-decision rule
     * against that freshly-locked state — never the (possibly stale) object
     * the caller passed in. This is the one gate every decision path shares:
     * a concurrent second decision blocks on the lock, then re-reads a row
     * that is no longer Pending and fails here, explicitly.
     */
    private function lockPending(AttendanceCorrection $correction, CorrectionStatus $target, ?int $actorId): AttendanceCorrection
    {
        /** @var AttendanceCorrection $locked */
        $locked = AttendanceCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();

        if (! $locked->status->canTransitionTo($target)) {
            throw AttendanceException::correctionNotPending($locked->status->value, $target->value);
        }

        if ($target !== CorrectionStatus::Cancelled && $actorId !== null && (int) $locked->requested_by === $actorId) {
            throw AttendanceException::selfDecisionNotAllowed();
        }

        return $locked;
    }
}
