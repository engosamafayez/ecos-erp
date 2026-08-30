<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PROC-PURCHASING-PHASE2-PART1 — Purchase Material becomes a receiving anchor.
 *
 * ┌─ WHY ────────────────────────────────────────────────────────────────────┐
 * │ A Goods Receipt could only ever be raised against a legacy PurchaseOrder: │
 * │ `goods_receipts.purchase_order_id` and `goods_receipt_lines.              │
 * │ purchase_order_line_id` were both NOT NULL FKs. The approved workflow      │
 * │ (Option A) makes PurchaseMaterial the operational purchase, so a receipt   │
 * │ line must be able to name a PURCHASE MATERIAL LINE instead.               │
 * │                                                                            │
 * │ Attribution cannot be derived: `purchase_material_lines` has no unique     │
 * │ (purchase_material_id, product_id) — the same product on two lines with    │
 * │ different suppliers is the designed split-sourcing shape — and multiple    │
 * │ open Purchases per supplier+product are normal. A stated FK is the only    │
 * │ deterministic answer, exactly as `supplier_invoice_lines.                  │
 * │ goods_receipt_line_id` and `supplier_return_lines.goods_receipt_line_id`   │
 * │ already resolve the same class of problem: a lookup, never a guess.        │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * ADDITIVE AND REVERSIBLE. The new column is nullable with NO backfill: a
 * deterministic backfill is impossible (a receipt carries no supplier of its own,
 * the PM-line supplier is nullable, requesting vs receiving warehouse are different
 * fields) and inventing one is the guess this design refuses. Every existing row
 * keeps its PO anchor and reads NULL here — i.e. "legacy, PO-anchored receipt".
 *
 * RESTRICT, not nullOnDelete: editing a held Purchase hard-deletes and recreates its
 * lines (EloquentPurchaseMaterialRepository::update), which under nullOnDelete would
 * silently orphan a POSTED receipt — stock in the warehouse, attribution gone. RESTRICT
 * turns that into a loud, correct failure.
 *
 * The two nullability relaxations carry no data risk: widening NOT NULL → NULL never
 * rewrites a value, and both tables are empty in every environment inspected.
 */
return new class extends Migration
{
    private const INDEX = 'grl_purchase_material_line_idx';

    public function up(): void
    {
        if (! Schema::hasTable('goods_receipt_lines') || ! Schema::hasTable('purchase_material_lines')) {
            return;
        }

        if (! Schema::hasColumn('goods_receipt_lines', 'purchase_material_line_id')) {
            Schema::table('goods_receipt_lines', function (Blueprint $table): void {
                $table->char('purchase_material_line_id', 36)
                    ->nullable()
                    ->after('purchase_order_line_id');

                $table->foreign('purchase_material_line_id')
                    ->references('id')->on('purchase_material_lines')
                    ->restrictOnDelete();

                $table->index('purchase_material_line_id', self::INDEX);
            });
        }

        // Relax the legacy PO anchors so a receipt can be raised against a Purchase.
        // Raw DDL: Laravel's ->change() needs the full column definition restated and is
        // brittle across the two FKs already on these columns; MODIFY preserves them.
        DB::statement('ALTER TABLE `goods_receipts` MODIFY `purchase_order_id` CHAR(36) NULL');
        DB::statement('ALTER TABLE `goods_receipt_lines` MODIFY `purchase_order_line_id` CHAR(36) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('goods_receipt_lines')) {
            return;
        }

        // Restoring NOT NULL only succeeds while no Purchase-anchored receipt exists —
        // by design. Once operators receive against Purchases, this is not reversible
        // without deciding what happens to those receipts.
        DB::statement('ALTER TABLE `goods_receipt_lines` MODIFY `purchase_order_line_id` CHAR(36) NOT NULL');
        DB::statement('ALTER TABLE `goods_receipts` MODIFY `purchase_order_id` CHAR(36) NOT NULL');

        if (Schema::hasColumn('goods_receipt_lines', 'purchase_material_line_id')) {
            Schema::table('goods_receipt_lines', function (Blueprint $table): void {
                $table->dropForeign(['purchase_material_line_id']);
                $table->dropIndex(self::INDEX);
                $table->dropColumn('purchase_material_line_id');
            });
        }
    }
};
