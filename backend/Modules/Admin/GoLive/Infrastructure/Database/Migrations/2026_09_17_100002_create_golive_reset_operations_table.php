<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-...-026 §7 — the auditable Reset / Go-Live Preparation operation record. One row per
 * attempted reset execution (never per preview — previews mutate nothing and are not persisted
 * here; they are computed fresh every call).
 *
 * `idempotency_key` (unique) is the duplicate-execution guard (§7/§21.22): the same client-
 * generated key submitted twice returns the first operation's result rather than resetting twice.
 *
 * `status` never reads "completed" after a partial failure (§8) — ExecuteGoLiveResetAction wraps
 * the whole multi-domain delete in one DB transaction; on any exception the transaction rolls
 * back FIRST, and only then is this row (already created before the transaction, status=
 * executing) updated to `failed` with `failure_stage` — so a reader never sees "completed" for a
 * run that didn't fully commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('golive_reset_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('actor_id')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 20)->default('executing')->index();
            $table->json('selected_domains');
            $table->json('preserved_domains')->nullable();
            $table->json('preview_counts')->nullable();
            $table->json('execution_counts')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('failure_stage', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->string('lifecycle_state_at_run', 20)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('golive_reset_operations');
    }
};
