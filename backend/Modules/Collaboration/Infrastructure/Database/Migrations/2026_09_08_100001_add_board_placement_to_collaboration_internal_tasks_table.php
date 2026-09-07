<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task's board LIST placement, independent of its canonical TaskStatus
 * column (unchanged by this migration) — see 2026_09_08_100000's docblock.
 * `task_list_id` is nullable: a task need not be placed on a board at all
 * (e.g. it is lazily placed into a default list the first time a company's
 * board is loaded — see EnsureDefaultTaskBoardListsAction), and nullOnDelete
 * mirrors collaboration_internal_tasks.team_id's existing "soft label,
 * never lose the task if the container goes away" contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('collaboration_internal_tasks', 'task_list_id')) {
            return;
        }

        Schema::table('collaboration_internal_tasks', function (Blueprint $table): void {
            $table->foreignUuid('task_list_id')
                ->nullable()
                ->after('team_id')
                ->constrained('collaboration_task_board_lists')
                ->nullOnDelete();

            $table->unsignedInteger('board_position')->default(0)->after('task_list_id');

            $table->index(['task_list_id', 'board_position'], 'collab_internal_tasks_list_position_index');
        });
    }

    public function down(): void
    {
        Schema::table('collaboration_internal_tasks', function (Blueprint $table): void {
            $table->dropIndex('collab_internal_tasks_list_position_index');
            $table->dropConstrainedForeignId('task_list_id');
            $table->dropColumn('board_position');
        });
    }
};
