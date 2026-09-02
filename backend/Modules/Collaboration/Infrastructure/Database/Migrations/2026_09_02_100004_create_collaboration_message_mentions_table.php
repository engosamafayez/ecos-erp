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

            $table->unique(['message_id', 'mentioned_user_id']);
            $table->index('mentioned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_message_mentions');
    }
};
