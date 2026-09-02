<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_conversation_participants')) {
            return;
        }

        Schema::create('collaboration_conversation_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Owned by the conversation: if the conversation row is ever removed,
            // its membership rows go with it.
            $table->foreignUuid('conversation_id')->constrained('collaboration_conversations')->cascadeOnDelete();

            // A reference to canonical identity, not an owned record: a hard delete
            // of a user must never be allowed to silently orphan conversation
            // history (users are soft-deleted in normal operation, so this should
            // not fire in practice — see ADR-044).
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('role', 10)->default('member');
            $table->timestampTz('joined_at');
            $table->timestampTz('left_at')->nullable();

            // Read cursor (§8/§12 of the architecture report): last_read_at drives
            // unread-count derivation directly against messages.created_at, so it
            // works correctly independent of UUID sortability. last_read_message_id
            // is a UX convenience ("scroll to here") added by the next migration,
            // once the messages table it points to exists.
            $table->timestampTz('last_read_at')->nullable();

            $table->timestampsTz();

            // One row per (conversation, user) for the life of the membership —
            // leaving and rejoining re-activates the same row via left_at, it never
            // inserts a second one.
            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_conversation_participants');
    }
};
