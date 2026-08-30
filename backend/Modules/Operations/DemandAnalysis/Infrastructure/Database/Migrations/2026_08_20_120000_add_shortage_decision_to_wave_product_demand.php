<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Continue Process Despite Shortage" (استمرار العملية رغم العجز) — the operator's explicit,
 * per-product decision to let a product that is short on material proceed without full
 * preparation (TASK-PREPARATION-OPERATIONS-UX-002 §15-§16).
 *
 * Recorded HERE, on the product-demand projection the engine already owns, precisely so that
 * NOTHING on the order is destroyed: the order line and product are never deleted, and NO new
 * OrderStatus is invented. "Not fully fulfilled" is the existing representation
 * `prepared_qty < required_qty`; these columns only mark that a human decided to continue
 * despite that shortfall, and by whom/when.
 *
 * MySQL 8.4-safe (nullable string + timestamps only). Additive; the default reproduces
 * today's behaviour (no decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wave_product_demand')) {
            return;
        }

        Schema::table('wave_product_demand', function (Blueprint $table): void {
            if (! Schema::hasColumn('wave_product_demand', 'shortage_decision')) {
                // null = no decision; 'continue' = proceed despite shortage. Kept a plain
                // string (not an enum column) so a future decision type needs no migration.
                $table->string('shortage_decision', 20)->nullable()->after('blocking_materials_count');
            }
            if (! Schema::hasColumn('wave_product_demand', 'shortage_decided_by')) {
                $table->string('shortage_decided_by')->nullable()->after('shortage_decision');
            }
            if (! Schema::hasColumn('wave_product_demand', 'shortage_decided_at')) {
                $table->timestamp('shortage_decided_at')->nullable()->after('shortage_decided_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wave_product_demand')) {
            return;
        }

        Schema::table('wave_product_demand', function (Blueprint $table): void {
            foreach (['shortage_decision', 'shortage_decided_by', 'shortage_decided_at'] as $col) {
                if (Schema::hasColumn('wave_product_demand', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
