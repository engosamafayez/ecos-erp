<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('collaboration_conversation_participants', 'last_read_message_id')) {
            return;
        }

        Schema::table('collaboration_conversation_participants', function (Blueprint $table): void {
            $table->foreignUuid('last_read_message_id')
                ->nullable()
                ->after('last_read_at')
                ->constrained('collaboration_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collaboration_conversation_participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_read_message_id');
        });
    }
};
