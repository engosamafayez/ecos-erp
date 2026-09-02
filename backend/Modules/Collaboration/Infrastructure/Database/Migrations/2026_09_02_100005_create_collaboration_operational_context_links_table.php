<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foundation only (architecture report §13/§19): a generic, string-keyed
     * reference table — the same "type discriminator + id" convention
     * `App\Core\Documents\Document` already uses, not a true polymorphic
     * `morphTo` and not a hard FK per operational module. The set of valid
     * context_type/attached_to_type values is enforced in the application
     * layer (Domain\Enums\OperationalContextType / AttachedToType), not by a
     * DB check constraint, so a future context type is a config change here,
     * not a migration.
     */
    public function up(): void
    {
        if (Schema::hasTable('collaboration_operational_context_links')) {
            return;
        }

        Schema::create('collaboration_operational_context_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('context_type', 40);
            $table->string('context_id', 64);
            $table->string('attached_to_type', 20);
            $table->uuid('attached_to_id');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['attached_to_type', 'attached_to_id']);
            $table->index(['context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_operational_context_links');
    }
};
