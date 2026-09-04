<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only task history (brief §18) — module-owned, matching how every
 * other module in this codebase already logs its own activity independently
 * (architecture report §2; `App\Core\Audit\AuditService` is confirmed dead
 * code platform-wide and is not resurrected here).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_internal_task_activity')) {
            return;
        }

        Schema::create('collaboration_internal_task_activity', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('collaboration_internal_tasks')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->string('from_value', 255)->nullable();
            $table->string('to_value', 255)->nullable();
            $table->timestampTz('created_at');

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_internal_task_activity');
    }
};
