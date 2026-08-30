<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PREPARATION-WORKSPACE-FIX-003 §2 — operator-owned Expected Incoming.
 *
 * Expected Incoming is a PLANNING input owned by Procurement inside the Preparation
 * context. It is deliberately NOT stored on wave_material_demand / wave_missing_materials:
 * those are projections that the demand calculators rebuild wholesale, so an operator
 * value written onto them would be silently clobbered on the next recalculation. Keeping
 * it in its own table means the projections stay pure and the planning value survives
 * every rebuild without needing a preservation contract in the calculators.
 *
 * What this value is NOT: it is not inventory, not a purchase-order balance, not a goods
 * receipt, and not a reservation. It never increases on-hand, available or reserved, never
 * writes the stock ledger, and never reduces the real missing_qty. It only feeds
 * Uncovered = max(0, missing_qty - expected_incoming).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wave_expected_incoming')) {
            return;
        }

        Schema::create('wave_expected_incoming', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_id');
            $table->string('preparation_wave_id');
            $table->string('material_id');

            // The operator's expectation for this material in this wave. Planning only.
            $table->decimal('expected_qty', 12, 4)->default(0);

            // Attribution: users.id is BIGINT, so foreignId (not foreignUuid).
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One planning value per material per wave — this is what makes the write
            // idempotent and keeps the read join fan-out free.
            $table->unique(['preparation_wave_id', 'material_id'], 'uq_wave_expected_incoming_wave_material');
            $table->index(['company_id', 'preparation_wave_id'], 'idx_wave_expected_incoming_company_wave');

            $table->foreign('preparation_wave_id')
                ->references('id')->on('preparation_waves')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_expected_incoming');
    }
};
