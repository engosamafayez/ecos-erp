<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-participant, per-conversation mute (architecture report §21, TASK-ECOS-
 * INTERNAL-COLLABORATION-CHAT-FINAL-IMPLEMENTATION-002). A single nullable
 * timestamp, mirroring `last_read_at`'s own shape — "muted since" rather than
 * a bare boolean, so a future "muted until" or an audit of when a user muted
 * a conversation costs nothing extra later. Purely a notification
 * preference: never consulted by unread-count derivation or message history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('collaboration_conversation_participants', 'muted_at')) {
            return;
        }

        Schema::table('collaboration_conversation_participants', function (Blueprint $table): void {
            $table->timestampTz('muted_at')->nullable()->after('last_read_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('collaboration_conversation_participants', function (Blueprint $table): void {
            $table->dropColumn('muted_at');
        });
    }
};
