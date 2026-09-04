<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per voice-type `documents` record (architecture report §10/§19).
 * Deliberately its own small table rather than new columns on the shared
 * `documents` table — Collaboration owns this metadata, Documents stays a
 * generic, module-agnostic store.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_voice_metadata')) {
            return;
        }

        Schema::create('collaboration_voice_metadata', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->unique();
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('format', 40)->nullable();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_voice_metadata');
    }
};
