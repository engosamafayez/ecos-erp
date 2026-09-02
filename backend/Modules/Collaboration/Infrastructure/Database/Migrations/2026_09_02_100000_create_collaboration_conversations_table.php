<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_conversations')) {
            return;
        }

        Schema::create('collaboration_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('type', 10);
            $table->string('title')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('team_id')->nullable()->constrained('teams')->nullOnDelete();

            // Populated only for type=direct: a stable, order-independent key derived
            // from the pair of participant user ids. Lets the DB itself enforce "at
            // most one active direct conversation per pair" instead of relying on an
            // application-only check (Postgres unique indexes allow many NULLs, so
            // group rows — which never set this column — never collide with it).
            $table->string('direct_pair_key', 40)->nullable();

            $table->timestampTz('last_message_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'direct_pair_key']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_conversations');
    }
};
