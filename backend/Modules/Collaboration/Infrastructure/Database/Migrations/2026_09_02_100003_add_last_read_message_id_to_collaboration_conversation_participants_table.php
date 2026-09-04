<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('collaboration_conversation_participants', 'last_read_message_id')) {
            return;
        }

        Schema::table('collaboration_conversation_participants', function (Blueprint $table): void {
            // Explicit name: Laravel's auto-generated FK name (68 chars) exceeds
            // MySQL's 64-char identifier limit (TASK-ECOS-INTERNAL-COLLABORATION-
            // MYSQL-MIGRATION-REMEDIATION-003) — same column, same FK semantics,
            // naming only. The FK is declared via the base foreign()/references()/
            // on() call (not ->constrained()->name()): naming a foreign key via a
            // ->name() call chained after ->constrained() silently overwrites the
            // pending command's type instead of its index name, so the constraint
            // is never compiled at all — proven during this remediation's MySQL
            // 8.4 DDL smoke test (empty ->name() call, no error, no constraint).
            $table->foreignUuid('last_read_message_id')
                ->nullable()
                ->after('last_read_at');

            $table->foreign('last_read_message_id', 'collab_conv_participants_last_read_msg_id_foreign')
                ->references('id')->on('collaboration_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collaboration_conversation_participants', function (Blueprint $table): void {
            $table->dropForeign('collab_conv_participants_last_read_msg_id_foreign');
            $table->dropColumn('last_read_message_id');
        });
    }
};
