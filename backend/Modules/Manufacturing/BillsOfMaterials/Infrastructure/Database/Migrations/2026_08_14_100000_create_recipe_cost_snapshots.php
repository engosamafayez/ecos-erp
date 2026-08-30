<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-DISASSEMBLY-RECIPE-COST-SNAPSHOT-AND-VALUATION-001.
 *
 * Establishes the APPROVED RECIPE COMPONENT COST as the authoritative basis for
 * disassembly valuation.
 *
 * WHY A NEW STRUCTURE WAS UNAVOIDABLE
 * -----------------------------------
 * The preceding contract-resolution audit proved the two candidate sources cannot
 * serve this contract:
 *
 *   bill_of_material_lines   — holds quantity + waste_percentage only. No cost.
 *   recipe_cost_histories    — `cost_snapshot` persists RecipeCostSummaryDTO::toArray(),
 *                              which is thirteen scalar TOTALS and zero per-component
 *                              entries. An aggregate of 800 cannot be decomposed into
 *                              500 + 200 + 100 without inventing historical data.
 *
 * Per-component costs have therefore never been persisted anywhere, at any time. No
 * archaeology recovers them, which is why PART 8 blocks disassembly for recipes with
 * no snapshot rather than approximating one.
 *
 * ENTIRELY ADDITIVE
 * -----------------
 * Two new tables plus two nullable columns. Nothing existing is altered, no data is
 * migrated, no historical cost is written. Every one of the current recipes simply
 * has no snapshot until it is re-approved — the explicit, safe outcome PART 23 requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Approval anchor ───────────────────────────────────────────────────
        //
        // `is_active` alone could not serve as the snapshot event: it is a boolean
        // that can be toggled repeatedly and records no moment, so "the cost when
        // this recipe was approved" had no timestamp to resolve against.
        //
        // Activation IS approval in this platform (SetBomStatusAction). These
        // columns record WHEN and BY WHOM, without introducing a second recipe
        // state machine.
        if (! Schema::hasColumn('bills_of_materials', 'approved_at')) {
            Schema::table('bills_of_materials', function (Blueprint $table): void {
                $table->timestamp('approved_at')->nullable()->after('is_active');
                $table->uuid('approved_by')->nullable()->after('approved_at');
                $table->index('approved_at');
            });
        }

        // ── Snapshot header ───────────────────────────────────────────────────
        if (! Schema::hasTable('recipe_cost_snapshots')) {
            Schema::create('recipe_cost_snapshots', function (Blueprint $table): void {
                $table->uuid('id')->primary();

                // Resolved at approval and stored, not derived on read. A BOM has no
                // company_id of its own (it resolves product → brand → company), and
                // tenant scoping must not depend on a join that a later product
                // re-brand could silently change.
                $table->uuid('company_id')->nullable()->index();

                $table->foreignUuid('bom_id')->constrained('bills_of_materials')->cascadeOnDelete();
                $table->unsignedInteger('bom_version_number')->default(1);

                // Sum of the line extended costs. Stored so a reader never has to
                // re-add them, and so a corrupted line set is detectable.
                $table->decimal('total_cost', 15, 4)->default(0);

                // Component quantities on a recipe are stated per BATCH, not per unit
                // — a recipe yielding 10 units from 5 KG lists 5. Without the yield
                // frozen alongside them, the per-finished-unit cost of a component
                // cannot be derived from this snapshot at all, and a later edit to
                // yield_quantity would silently re-scale historical valuations.
                $table->decimal('yield_quantity', 12, 4)->default(1);

                $table->timestamp('approved_at');
                $table->uuid('approved_by')->nullable();
                $table->timestamps();

                // One snapshot per approval of a given BOM version. A re-approval of
                // the same version is idempotent rather than duplicating history.
                $table->unique(['bom_id', 'bom_version_number'], 'recipe_cost_snapshots_bom_version_unique');
            });
        }

        // ── Snapshot lines — the per-component costs that exist nowhere else ──
        if (! Schema::hasTable('recipe_cost_snapshot_lines')) {
            Schema::create('recipe_cost_snapshot_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('snapshot_id')->constrained('recipe_cost_snapshots')->cascadeOnDelete();

                // The originating line, kept for traceability. Nullable and NOT a
                // constrained FK: recipe lines are replaced wholesale on edit, and a
                // snapshot must survive its source line being deleted — that is the
                // entire point of an immutable historical record.
                $table->uuid('recipe_line_id')->nullable();

                $table->uuid('raw_material_id')->index();

                $table->decimal('quantity', 12, 4);
                $table->decimal('waste_percentage', 5, 2)->default(0);

                // The frozen cost. `unit_cost` is the material's cost AT APPROVAL;
                // `extended_cost` is that multiplied by the waste-adjusted quantity.
                $table->decimal('unit_cost', 15, 4);
                $table->decimal('extended_cost', 15, 4);

                // Denormalised so a snapshot reads correctly even if the material is
                // renamed or soft-deleted later.
                $table->string('sku_snapshot')->nullable();
                $table->string('name_snapshot')->nullable();
                $table->string('unit_symbol_snapshot', 32)->nullable();

                $table->timestamps();

                $table->index(['snapshot_id', 'raw_material_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_cost_snapshot_lines');
        Schema::dropIfExists('recipe_cost_snapshots');

        if (Schema::hasColumn('bills_of_materials', 'approved_at')) {
            Schema::table('bills_of_materials', function (Blueprint $table): void {
                $table->dropIndex(['approved_at']);
                $table->dropColumn(['approved_at', 'approved_by']);
            });
        }
    }
};
