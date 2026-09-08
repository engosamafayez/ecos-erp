<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message reactions (TASK-ECOS-INTERNAL-COLLABORATION-FINAL-USER-REVIEW-
 * REMEDIATION-010 §13) — a proper relation, never a mutation of the
 * immutable `collaboration_messages.body`. One reaction per user PER
 * MESSAGE (the unique constraint below): reacting again with a different
 * emoji replaces the previous one (upsert in SetMessageReactionAction),
 * matching "one user may add/remove their reaction" (singular) rather than
 * a Slack-style multi-emoji-per-user model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_message_reactions')) {
            return;
        }

        Schema::create('collaboration_message_reactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('collaboration_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('emoji', 32);
            $table->timestampTz('created_at');

            $table->unique(['message_id', 'user_id'], 'collab_message_reactions_message_user_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_message_reactions');
    }
};
