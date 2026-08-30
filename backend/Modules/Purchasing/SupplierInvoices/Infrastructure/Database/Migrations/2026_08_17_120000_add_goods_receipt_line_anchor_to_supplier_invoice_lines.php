<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V-5 — the deterministic receipt anchor on a supplier invoice line.
 *
 * WHY. Clearing GRNI and computing Purchase Price Variance both need ONE number: the valuation
 * the physical receipt already committed to Inventory/FIFO. Without a link from the invoice line
 * to the receipt line, that number can only be guessed — by supplier, product, date, FIFO order
 * or nearest receipt — every one of which the approved contract forbids. This column makes it a
 * lookup instead of a guess.
 *
 * THE PRECEDENT IS CERTIFIED, NOT INVENTED. `supplier_return_lines.goods_receipt_line_id` solved
 * the identical problem for Supplier Returns (SR-2): a nullable column with a real FK, made
 * mandatory at the request/posting boundary rather than in the schema. This mirrors it exactly,
 * including the FK target and the nullability rationale below.
 *
 * WHY NULLABLE. Two reasons, both deliberate:
 *   1. A DRAFT invoice may legitimately exist before the goods arrive — the invoice often reaches
 *      the office first. Forcing the anchor in the schema would make that impossible to record.
 *   2. Existing rows must survive. Historical lines are left unanchored and are never backfilled
 *      by inference; see the engineering report.
 * The rule is enforced where it belongs: a posting-ready line MUST carry a valid anchor, and
 * posting fails safely without one. Nullable column, mandatory contract.
 *
 * `nullOnDelete()` matches the Supplier Return anchor's behaviour: deleting a receipt line must
 * never cascade into financial documents.
 *
 * Additive and idempotent: one nullable column plus an index. No data is written, moved or
 * deleted, and no existing column is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_invoice_lines') || Schema::hasColumn('supplier_invoice_lines', 'goods_receipt_line_id')) {
            return;
        }

        Schema::table('supplier_invoice_lines', function (Blueprint $table): void {
            $table->char('goods_receipt_line_id', 36)->nullable()->after('product_id');

            $table->foreign('goods_receipt_line_id')
                ->references('id')
                ->on('goods_receipt_lines')
                ->nullOnDelete();

            // The ceiling query asks "how much of THIS receipt line has already been invoiced?"
            $table->index('goods_receipt_line_id', 'sil_goods_receipt_line_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('supplier_invoice_lines') || ! Schema::hasColumn('supplier_invoice_lines', 'goods_receipt_line_id')) {
            return;
        }

        Schema::table('supplier_invoice_lines', function (Blueprint $table): void {
            $table->dropForeign(['goods_receipt_line_id']);
            $table->dropIndex('sil_goods_receipt_line_idx');
            $table->dropColumn('goods_receipt_line_id');
        });
    }
};
