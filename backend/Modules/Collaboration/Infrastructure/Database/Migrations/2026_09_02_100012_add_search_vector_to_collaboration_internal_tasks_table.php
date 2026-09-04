<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL-native full-text search foundation — remediation of the original
 * PostgreSQL tsvector/GIN design, see 2026_09_02_100008's docblock for the
 * full rationale (TASK-ECOS-INTERNAL-COLLABORATION-MYSQL-MIGRATION-
 * REMEDIATION-003). Task search keeps its own index, deliberately separate
 * from collaboration_messages.body (brief §23 — "do not force Task content
 * into the Message search index"). MySQL supports a single FULLTEXT index
 * spanning both columns directly, so — unlike Postgres — no concatenated
 * generated helper column is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('collaboration_internal_tasks', 'collab_internal_tasks_search_fulltext')) {
            return;
        }

        Schema::table('collaboration_internal_tasks', function (Blueprint $table): void {
            $table->fullText(['title', 'description'], 'collab_internal_tasks_search_fulltext');
        });
    }

    public function down(): void
    {
        Schema::table('collaboration_internal_tasks', function (Blueprint $table): void {
            $table->dropFullText('collab_internal_tasks_search_fulltext');
        });
    }
};
