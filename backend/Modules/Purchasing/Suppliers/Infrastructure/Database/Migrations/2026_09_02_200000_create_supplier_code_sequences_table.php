<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-MASTER-DATA-002-R1.
 *
 * Replaces the count()+lockForUpdate() Supplier Code generator (proven
 * concurrency-unsafe on first-row creates — a zero-row SELECT ... FOR UPDATE
 * locks nothing) with a dedicated per-company sequence row, mirroring the
 * proven atomic-upsert pattern already used by `pos_receipt_counters`
 * (SequentialReceiptNumberingStrategy). `company_id` IS the primary key — one
 * sequence row per company — which is exactly the unique target the atomic
 * `INSERT ... ON DUPLICATE KEY UPDATE` needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_code_sequences')) {
            return;
        }

        Schema::create('supplier_code_sequences', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->primary()->constrained('companies')->cascadeOnDelete();
            $table->unsignedInteger('current_number');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_code_sequences');
    }
};
