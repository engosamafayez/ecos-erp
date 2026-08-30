<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PREPARATION-WAVE-WORKSPACE-FINAL-BEHAVIOR-REPAIR-001 — Part 6.
 *
 * "Preparation finished" for a product inside a wave.
 *
 * Deliberately a separate marker rather than an inference from
 * `prepared_qty >= required_qty`: reaching the required quantity is not the same
 * as the operator declaring the work done, and Required can move afterwards when
 * an order is postponed. Editing Prepared must never auto-complete.
 *
 * ADDITIVE ONLY — one nullable timestamp. Nothing else on the table changes, and
 * product-level Prepared itself needed no schema change: `prepared_qty` already
 * existed. What changed there is ownership (the operator writes it, the demand
 * rebuild preserves it), which is a code-level contract, not a column.
 *
 * No new lifecycle is introduced. `preparation_wave_items` / PreparationItemStatus
 * remain the per-item lifecycle; this marker records a product-level fact on the
 * demand read model, which is where product-level Prepared already lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wave_product_demand')) {
            return;
        }

        if (Schema::hasColumn('wave_product_demand', 'preparation_completed_at')) {
            return;
        }

        Schema::table('wave_product_demand', function (Blueprint $table): void {
            $table->timestamp('preparation_completed_at')
                ->nullable()
                ->after('completion_pct');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wave_product_demand')) {
            return;
        }

        if (! Schema::hasColumn('wave_product_demand', 'preparation_completed_at')) {
            return;
        }

        Schema::table('wave_product_demand', function (Blueprint $table): void {
            $table->dropColumn('preparation_completed_at');
        });
    }
};
