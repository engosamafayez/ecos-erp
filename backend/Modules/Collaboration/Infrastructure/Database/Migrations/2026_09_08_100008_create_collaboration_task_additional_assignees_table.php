<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional assignees, ALONGSIDE (never replacing) the primary
 * `collaboration_internal_tasks.assignee_user_id` (TASK-ECOS-INTERNAL-
 * COLLABORATION-FINAL-USER-REVIEW-REMEDIATION-010 §7 — "preserve it for
 * backward compatibility; add a canonical multiple-assignee relation").
 * The primary column keeps every existing authority (reassign, "my tasks"
 * scoping, notifications) untouched; this table is a purely additive
 * membership set, so no backfill is needed — an empty table correctly
 * represents "no additional assignees yet" for every pre-existing task.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_task_additional_assignees')) {
            return;
        }

        Schema::create('collaboration_task_additional_assignees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('collaboration_internal_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['task_id', 'user_id'], 'collab_task_additional_assignees_task_user_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_task_additional_assignees');
    }
};
