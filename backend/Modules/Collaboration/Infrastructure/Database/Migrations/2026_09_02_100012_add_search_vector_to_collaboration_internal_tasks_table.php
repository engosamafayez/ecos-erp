<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task search gets its own vector/index, deliberately separate from
 * collaboration_messages.body_tsv (brief §23 — "do not force Task content
 * into the Message search index"). Same STORED generated-column approach as
 * Task 3's message search — see that migration's docblock for why raw SQL
 * is used and why this column must never appear in InternalTask::$fillable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('collaboration_internal_tasks', 'search_tsv')) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE collaboration_internal_tasks
            ADD COLUMN search_tsv tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english', coalesce(title, '') || ' ' || coalesce(description, ''))
            ) STORED
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX collaboration_internal_tasks_search_tsv_index
            ON collaboration_internal_tasks
            USING GIN (search_tsv)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS collaboration_internal_tasks_search_tsv_index');
        DB::statement('ALTER TABLE collaboration_internal_tasks DROP COLUMN IF EXISTS search_tsv');
    }
};
