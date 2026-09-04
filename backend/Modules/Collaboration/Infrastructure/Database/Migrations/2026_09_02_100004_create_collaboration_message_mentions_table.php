<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_message_mentions')) {
            return;
        }

        Schema::create('collaboration_message_mentions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('collaboration_messages')->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            // Explicit name: Laravel's auto-generated name (66 chars) exceeds
            // MySQL's 64-char identifier limit. Verification-only fix
            // (TASK-ECOS-INTERNAL-COLLABORATION-ISOLATED-INTEGRATION-VERIFICATION-002)
            // — same columns, same uniqueness semantics, naming only.
            $table->unique(['message_id', 'mentioned_user_id'], 'collab_msg_mentions_message_mentioned_unique');
            $table->index('mentioned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_message_mentions');
    }
};
