<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal Tasks (ADR-044 §1.5/§1.6/§1.10, architecture report §11/§12) —
 * owned by Modules\Collaboration, not a second project-management platform.
 * `assignee_user_id` is required (not nullable): every task has exactly one
 * accountable owner from creation (defaulting to self-assign at the
 * application layer, architecture report §12), matching the single-
 * assignee V1 decision — no join table for multiple assignees.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_internal_tasks')) {
            return;
        }

        Schema::create('collaboration_internal_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('creator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_user_id')->constrained('users')->restrictOnDelete();

            // Soft label only, exactly like collaboration_conversations.team_id —
            // never a membership/ownership source (ADR-044 §7, ADR-011).
            $table->foreignUuid('team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->string('priority', 10)->default('normal');
            $table->string('status', 15)->default('todo');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            // Message -> Task durable linkage (architecture report §12). The
            // snapshot survives regardless of what later happens to the live
            // message; both FK columns are nullable because a task need not
            // originate from a message at all.
            $table->foreignUuid('source_conversation_id')->nullable()->constrained('collaboration_conversations')->nullOnDelete();
            $table->foreignUuid('source_message_id')->nullable()->constrained('collaboration_messages')->nullOnDelete();
            $table->text('source_message_snapshot')->nullable();

            $table->timestampsTz();

            // Explicit name on the first index only: Laravel's auto-generated
            // name for it (69 chars) exceeds MySQL's 64-char identifier limit.
            // The other two below stay at their Laravel-default names (61/59
            // chars, within limit). Verification-only fix (TASK-ECOS-INTERNAL-
            // COLLABORATION-ISOLATED-INTEGRATION-VERIFICATION-002) — same
            // columns, same index semantics, naming only.
            $table->index(['company_id', 'assignee_user_id', 'status'], 'collab_internal_tasks_company_assignee_status_index');
            $table->index(['company_id', 'creator_user_id']);
            $table->index(['company_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_internal_tasks');
    }
};
