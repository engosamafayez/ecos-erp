<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trello-style board LISTS (organizational containers), NOT a second task
 * status/workflow engine — TASK-ECOS-INTERNAL-COLLABORATION-TASKS-TRELLO-
 * FINAL-CLOSURE-002 §1. Canonical TaskStatus (todo/in_progress/done/
 * cancelled, see TaskStatus enum) remains the sole lifecycle authority;
 * `collaboration_task_board_lists` only records where a card visually sits
 * on the board. Company-scoped, exactly like collaboration_task_labels.
 *
 * `position` follows the plain dense-integer convention already established
 * elsewhere in this codebase (no existing sparse/fractional-rank pattern was
 * found — see CrmSales PipelineStage.order, Hr Recruitment stage.sequence,
 * Engineering checklist item.position) — reorder rewrites the affected
 * range, same as those.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_task_board_lists')) {
            return;
        }

        Schema::create('collaboration_task_board_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();

            $table->string('name', 100);
            $table->unsignedInteger('position')->default(0);
            $table->timestampTz('archived_at')->nullable();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();

            $table->timestampsTz();

            $table->index(['company_id', 'archived_at', 'position'], 'collab_task_board_lists_company_archived_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_task_board_lists');
    }
};
