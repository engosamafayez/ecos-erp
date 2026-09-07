<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watchers, DISTINCT from the primary assignee (brief §5/§16) — "who is
 * following/monitoring the task", never a second assignment authority.
 * IAM User identities only, never a Collaboration-owned user record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_task_followers')) {
            return;
        }

        Schema::create('collaboration_task_followers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('collaboration_internal_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['task_id', 'user_id'], 'collab_task_followers_task_user_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_task_followers');
    }
};
