<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable, company-scoped labels (brief §12). `color` is a small fixed
 * token (e.g. "red"/"orange"/"yellow"/"green"/"blue"/"purple"/"gray"),
 * validated at the request layer — not a free CSS value, so the frontend's
 * badge palette stays closed and theme-consistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_task_labels')) {
            return;
        }

        Schema::create('collaboration_task_labels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();

            $table->string('name', 60);
            $table->string('color', 20);

            $table->timestampsTz();

            $table->unique(['company_id', 'name'], 'collab_task_labels_company_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_task_labels');
    }
};
