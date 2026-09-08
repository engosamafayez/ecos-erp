<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task archive (TASK-ECOS-INTERNAL-COLLABORATION-FINAL-USER-REVIEW-
 * REMEDIATION-010 §5) — orthogonal to canonical TaskStatus (unchanged) and
 * to board placement, same "organizational, not a lifecycle state" posture
 * as TaskBoardList.archived_at (2026_09_08_100000's docblock). Archiving
 * hides a task from the normal Board/List views without deleting it;
 * task_list_id/board_position/status are all left untouched by archiving —
 * only RestoreTaskAction may adjust them, and only to keep the restored
 * task's placement valid (see its own docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('collaboration_internal_tasks', 'archived_at')) {
            return;
        }

        Schema::table('collaboration_internal_tasks', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable()->after('cancelled_at');
            $table->index(['company_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('collaboration_internal_tasks', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
