<?php

declare(strict_types=1);

namespace Modules\Hr\Attendance\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Hr\Attendance\Domain\Enums\AttendanceStatus;
use Modules\Hr\Attendance\Domain\Enums\CorrectionStatus;
use Modules\Hr\Workforce\Domain\Models\Employee;

/**
 * One proposed correction to an AttendanceDay: the original values it found,
 * the values it proposes, and — once decided — who decided and when. Kept
 * even after being applied, so the original evidence is never lost to an
 * overwrite.
 */
class AttendanceCorrection extends Model
{
    use HasUuids;

    protected $table = 'hr_attendance_corrections';

    protected $fillable = [
        'company_id', 'employee_id', 'attendance_day_id',
        'original_status', 'original_check_in', 'original_check_out',
        'corrected_status', 'corrected_check_in', 'corrected_check_out', 'corrected_notes',
        'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'original_status' => AttendanceStatus::class,
            'corrected_status' => AttendanceStatus::class,
            'status' => CorrectionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function isPending(): bool
    {
        return $this->status === CorrectionStatus::Pending;
    }
}
