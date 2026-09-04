<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-TRANSACTION-SAFETY-FOUNDATION-002.
 *
 * Command-level idempotency, modeled on finance_posted_event_receipts
 * (2026_08_10_100010) one layer up: that table deduplicates an EVENT
 * reaching the ledger via PostingCoordinator; this table deduplicates an
 * interactive/API COMMAND (e.g. "create this supplier payment") reaching its
 * domain service in the first place, before any PostingRequest exists. A row
 * is written only after its command has completed, inside the same database
 * transaction — see CommandIdempotencyGuard — so this table is never updated
 * and carries no "processing"/"failed" status to expire or reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('company_id');
            $table->string('command_type', 100);
            $table->string('idempotency_key', 200);
            $table->char('request_fingerprint', 64);

            // The resulting resource, resolved generically on replay via
            // $result_type::query()->where('uuid', $result_id) — every Finance
            // domain model this guards over carries a uuid column.
            $table->string('result_type', 150)->nullable();
            $table->string('result_id', 36)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'command_type', 'idempotency_key'], 'finance_cr_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_command_receipts');
    }
};
