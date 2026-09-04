<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Task Comment is not a Conversation Message (brief §16) — its own table,
 * its own model, deliberately not routed through collaboration_messages
 * even though both are "a bit of text with an author and a timestamp".
 * Immutable once posted, same as messages (no updated_at column).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_internal_task_comments')) {
            return;
        }

        Schema::create('collaboration_internal_task_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('collaboration_internal_tasks')->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestampTz('created_at');

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_internal_task_comments');
    }
};
