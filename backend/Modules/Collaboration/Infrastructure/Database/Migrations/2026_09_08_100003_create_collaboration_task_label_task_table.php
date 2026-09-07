<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Task <-> Label many-to-many (brief §12 — multiple labels per task). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_task_label_task')) {
            return;
        }

        Schema::create('collaboration_task_label_task', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('collaboration_internal_tasks')->cascadeOnDelete();
            $table->foreignUuid('label_id')->constrained('collaboration_task_labels')->cascadeOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['task_id', 'label_id'], 'collab_task_label_task_task_label_unique');
            $table->index('label_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_task_label_task');
    }
};
