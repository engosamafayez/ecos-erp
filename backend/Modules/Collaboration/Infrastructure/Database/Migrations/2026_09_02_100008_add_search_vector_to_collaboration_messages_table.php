<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL-native full-text search foundation (architecture report §18,
 * ratified ADR-044 §9). Remediates the original PostgreSQL tsvector/GIN
 * design (TASK-ECOS-INTERNAL-COLLABORATION-MYSQL-MIGRATION-REMEDIATION-003)
 * for MySQL 8.4, the authoritative ECOS runtime/test database
 * (backend/phpunit.xml forces DB_CONNECTION=mysql). No Scout, no
 * Meilisearch, no external search service — MySQL's InnoDB FULLTEXT index
 * lives directly on `body` itself; unlike Postgres, no generated helper
 * column is needed, so `body` stays unchanged and out of any special-case
 * handling in Message::$fillable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('collaboration_messages', 'collab_messages_body_fulltext')) {
            return;
        }

        Schema::table('collaboration_messages', function (Blueprint $table): void {
            $table->fullText('body', 'collab_messages_body_fulltext');
        });
    }

    public function down(): void
    {
        Schema::table('collaboration_messages', function (Blueprint $table): void {
            $table->dropFullText('collab_messages_body_fulltext');
        });
    }
};
