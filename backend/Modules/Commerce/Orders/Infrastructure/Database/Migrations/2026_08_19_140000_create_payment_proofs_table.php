<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PAYMENT-PROOF-LIFECYCLE-001 — first-class Payment Proof records.
 *
 * A proof is EVIDENCE for an electronic payment, not the source of payment truth
 * and not an Order status. `state` (uploaded|verified|rejected) is the authoritative
 * lifecycle — never the storage path. Proofs belong to the tenant through the order.
 * History/replacement is retained: a replaced proof is stamped `superseded_at`
 * (null = active) and never deleted; the newest non-superseded proof is the active one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_proofs')) {
            return;
        }

        Schema::create('payment_proofs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();

            // Authoritative lifecycle state — NOT the storage path.
            $table->string('state', 20)->default('uploaded'); // uploaded | verified | rejected

            // Storage reference (via the filesystem abstraction) + file metadata.
            $table->string('storage_disk', 50)->default('public');
            $table->string('storage_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Attribution — users.id is a bigint (preserved), so these are bigint FKs.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();

            // History / replacement — a superseded proof is retained, never deleted.
            $table->timestamp('superseded_at')->nullable();   // null = active
            $table->uuid('replaces_proof_id')->nullable();     // the proof this one replaced

            $table->timestamps();

            $table->index(['order_id', 'superseded_at'], 'idx_pp_order_active');
            $table->index(['company_id', 'order_id'], 'idx_pp_company_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
