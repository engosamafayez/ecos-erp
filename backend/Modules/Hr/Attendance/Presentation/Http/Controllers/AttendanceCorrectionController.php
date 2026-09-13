<?php

declare(strict_types=1);

namespace Modules\Hr\Attendance\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Hr\Attendance\Domain\Models\AttendanceCorrection;
use Modules\Hr\Attendance\Domain\Models\AttendanceDay;
use Modules\Hr\Attendance\Domain\Services\AttendanceCorrectionService;
use Modules\Hr\Workforce\Presentation\Http\Controllers\Concerns\ResolvesHrContext;

/** The Attendance correction request/decision workflow. */
class AttendanceCorrectionController extends Controller
{
    use ResolvesHrContext;

    public function __construct(private readonly AttendanceCorrectionService $corrections) {}

    /** Always scoped to the caller's company — an id from another tenant 404s. */
    private function attendanceDay(Request $request, string $id): AttendanceDay
    {
        return AttendanceDay::query()
            ->where('company_id', $this->companyId($request))
            ->where('id', $id)
            ->firstOrFail();
    }

    private function correction(Request $request, string $id): AttendanceCorrection
    {
        return AttendanceCorrection::query()
            ->where('company_id', $this->companyId($request))
            ->where('id', $id)
            ->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'employee_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);

        $rows = AttendanceCorrection::query()
            ->with(['employee:id,first_name,last_name,employee_number', 'attendanceDay:id,work_date'])
            ->where('company_id', $this->companyId($request))
            ->when(isset($v['employee_id']), fn ($q) => $q->where('employee_id', $v['employee_id']))
            ->when(isset($v['status']), fn ($q) => $q->where('status', $v['status']))
            ->orderByDesc('created_at')
            ->limit(200)->get()
            ->map(fn (AttendanceCorrection $c) => $this->payload($c));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, string $attendanceDayId): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', 'string'],
            'check_in' => ['nullable', 'date_format:H:i,H:i:s'],
            'check_out' => ['nullable', 'date_format:H:i,H:i:s'],
            'notes' => ['nullable', 'string', 'max:300'],
            'reason' => ['required', 'string', 'max:400'],
        ]);

        $day = $this->attendanceDay($request, $attendanceDayId);

        $correction = $this->corrections->request($day, $v, (int) $this->actorId($request));

        return response()->json(['data' => $this->payload($correction)], 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $v = $request->validate(['note' => ['nullable', 'string', 'max:400']]);

        $correction = $this->corrections->approve(
            $this->correction($request, $id),
            $this->actorId($request),
            $v['note'] ?? null,
        );

        return response()->json(['data' => $this->payload($correction)]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $v = $request->validate(['note' => ['nullable', 'string', 'max:400']]);

        $correction = $this->corrections->reject(
            $this->correction($request, $id),
            $this->actorId($request),
            $v['note'] ?? null,
        );

        return response()->json(['data' => $this->payload($correction)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $correction = $this->corrections->cancel($this->correction($request, $id), $this->actorId($request));

        return response()->json(['data' => $this->payload($correction)]);
    }

    /** @return array<string, mixed> */
    private function payload(AttendanceCorrection $c): array
    {
        return [
            'id' => (string) $c->id,
            'attendance_day_id' => (string) $c->attendance_day_id,
            'work_date' => $c->attendanceDay?->work_date?->toDateString(),
            'employee' => $c->employee === null ? null : [
                'id' => (string) $c->employee->id,
                'name' => $c->employee->fullName(),
                'employee_number' => $c->employee->employee_number,
            ],
            'original' => [
                'status' => $c->original_status->value,
                'check_in' => $c->original_check_in,
                'check_out' => $c->original_check_out,
            ],
            'corrected' => [
                'status' => $c->corrected_status->value,
                'check_in' => $c->corrected_check_in,
                'check_out' => $c->corrected_check_out,
                'notes' => $c->corrected_notes,
            ],
            'reason' => $c->reason,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'requested_by' => $c->requested_by,
            'decided_by' => $c->decided_by,
            'decided_at' => $c->decided_at?->toIso8601String(),
            'decision_note' => $c->decision_note,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
