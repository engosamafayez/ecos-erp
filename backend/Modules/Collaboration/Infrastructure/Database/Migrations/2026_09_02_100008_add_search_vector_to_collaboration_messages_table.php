<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL full-text search foundation (architecture report §18, ratified
 * ADR-044 §9): a STORED generated `tsvector` column plus a GIN index over it.
 * No Scout, no Meilisearch, no external search service — Postgres computes
 * and maintains the vector itself on every insert/update, so application
 * code never writes to this column directly (it is not, and must never be,
 * in Message::$fillable).
 *
 * Raw SQL because Laravel's schema builder has no first-class generated-
 * column/tsvector support; `Schema::hasColumn()` still works correctly for
 * the idempotency guard regardless of how the column was created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('collaboration_messages', 'body_tsv')) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE collaboration_messages
            ADD COLUMN body_tsv tsvector
            GENERATED ALWAYS AS (to_tsvector('english', coalesce(body, ''))) STORED
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX collaboration_messages_body_tsv_index
            ON collaboration_messages
            USING GIN (body_tsv)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS collaboration_messages_body_tsv_index');
        DB::statement('ALTER TABLE collaboration_messages DROP COLUMN IF EXISTS body_tsv');
    }
};
