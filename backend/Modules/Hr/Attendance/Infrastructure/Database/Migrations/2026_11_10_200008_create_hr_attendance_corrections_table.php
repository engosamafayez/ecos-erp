<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-01 — the canonical Attendance correction workflow.
 *
 * ┌─ A REQUEST, NEVER A SILENT EDIT ────────────────────────────────────────┐
 * │ An AttendanceDay is never mutated directly for a correction. This row is    │
 * │ the full proposal — the original values it found, the values it proposes,  │
 * │ and (once decided) who decided and when — kept even after the correction    │
 * │ has been applied, so "what did it used to say, and who changed it" is       │
 * │ always answerable without reading between audit-log lines.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_attendance_corrections')) {
            return;
        }

        Schema::create('hr_attendance_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignUuid('attendance_day_id')->constrained('hr_attendance_days')->cascadeOnDelete();

            // The evidence, preserved regardless of what happens next.
            $table->string('original_status', 20);
            $table->time('original_check_in')->nullable();
            $table->time('original_check_out')->nullable();

            // The proposal.
            $table->string('corrected_status', 20);
            $table->time('corrected_check_in')->nullable();
            $table->time('corrected_check_out')->nullable();
            $table->string('corrected_notes', 300)->nullable();
            $table->string('reason', 400);

            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled

            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 400)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status'], 'hr_attendance_correction_status_idx');
            $table->index(['attendance_day_id', 'status'], 'hr_attendance_correction_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_corrections');
    }
};
