<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_task_checklist_items')) {
            return;
        }

        Schema::create('collaboration_task_checklist_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('checklist_id')->constrained('collaboration_task_checklists')->cascadeOnDelete();

            $table->string('title', 255);
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();

            $table->index(['checklist_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_task_checklist_items');
    }
};
