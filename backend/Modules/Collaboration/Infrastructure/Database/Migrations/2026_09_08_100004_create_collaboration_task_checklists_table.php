<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Checklist is organizational content, never task status (brief §13). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_task_checklists')) {
            return;
        }

        Schema::create('collaboration_task_checklists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('collaboration_internal_tasks')->cascadeOnDelete();

            $table->string('title', 100);
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();

            $table->index(['task_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_task_checklists');
    }
};
