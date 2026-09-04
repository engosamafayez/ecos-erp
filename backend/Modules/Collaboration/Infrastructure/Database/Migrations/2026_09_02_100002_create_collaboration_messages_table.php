<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_messages')) {
            return;
        }

        Schema::create('collaboration_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('collaboration_conversations')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();

            // 'text' is the only type an application action can actually produce in
            // Task 2. image/file/voice/system already exist here so Task 3 attaches
            // media without a schema change or a second message table.
            $table->string('type', 10)->default('text');

            $table->text('body')->nullable();
            $table->foreignUuid('reply_to_message_id')->nullable()->constrained('collaboration_messages')->nullOnDelete();

            // Immutable operational record (ADR-044 §1.5 / architecture report §9):
            // deliberately no `updated_at` — there is no product action that should
            // ever be able to change a sent message, and the schema itself should
            // say so, not just application code.
            $table->timestampTz('created_at');

            $table->index(['conversation_id', 'created_at', 'id']);
            $table->index('reply_to_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_messages');
    }
};
