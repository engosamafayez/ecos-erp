<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Brief §3 — preserve every existing task; introduce the four default board
 * lists matching the current fixed Board columns, then place each existing
 * task into the list matching its CURRENT canonical TaskStatus. This is a
 * one-time organizational backfill: it never touches the `status` column
 * itself, only the new `task_list_id`/`board_position` pair.
 *
 * Runs per company that actually has at least one task — a company with
 * zero tasks gets its default lists lazily on first board load instead (see
 * EnsureDefaultTaskBoardListsAction), so this migration never has to guess
 * at every company in the system, only the ones with real data to backfill.
 */
return new class extends Migration
{
    private const DEFAULT_LISTS = [
        ['key' => 'todo', 'name' => 'To Do'],
        ['key' => 'in_progress', 'name' => 'In Progress'],
        ['key' => 'done', 'name' => 'Done'],
        ['key' => 'cancelled', 'name' => 'Cancelled'],
    ];

    public function up(): void
    {
        $companyIds = DB::table('collaboration_internal_tasks')
            ->whereNull('task_list_id')
            ->distinct()
            ->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $createdBy = DB::table('collaboration_internal_tasks')
                ->where('company_id', $companyId)
                ->orderBy('created_at')
                ->value('creator_user_id');

            if ($createdBy === null) {
                continue;
            }

            $now = now();
            $listIdByStatus = [];

            foreach (self::DEFAULT_LISTS as $position => $list) {
                $listId = (string) Str::uuid();
                $listIdByStatus[$list['key']] = $listId;

                DB::table('collaboration_task_board_lists')->insert([
                    'id' => $listId,
                    'company_id' => $companyId,
                    'name' => $list['name'],
                    'position' => $position,
                    'archived_at' => null,
                    'created_by_user_id' => $createdBy,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (self::DEFAULT_LISTS as $list) {
                $tasks = DB::table('collaboration_internal_tasks')
                    ->where('company_id', $companyId)
                    ->where('status', $list['key'])
                    ->whereNull('task_list_id')
                    ->orderBy('created_at')
                    ->pluck('id');

                foreach ($tasks as $boardPosition => $taskId) {
                    DB::table('collaboration_internal_tasks')
                        ->where('id', $taskId)
                        ->update([
                            'task_list_id' => $listIdByStatus[$list['key']],
                            'board_position' => $boardPosition,
                        ]);
                }
            }
        }
    }

    /**
     * Reversible in spirit (clears the new columns) but the generated list
     * rows themselves are left in place — the same "never delete/rewrite
     * history irreversibly" posture as every other Collaboration migration
     * in this module (see 2026_09_02_100006's down()).
     */
    public function down(): void
    {
        DB::table('collaboration_internal_tasks')->update([
            'task_list_id' => null,
            'board_position' => 0,
        ]);
    }
};
