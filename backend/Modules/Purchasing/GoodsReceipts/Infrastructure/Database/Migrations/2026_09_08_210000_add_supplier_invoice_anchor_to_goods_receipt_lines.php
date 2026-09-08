<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PROCUREMENT-INVOICE-FIRST-RECEIVING-FLOW-014.
 *
 * ┌─ WHY ────────────────────────────────────────────────────────────────────┐
 * │ A Goods Receipt line could only ever be raised against a legacy          │
 * │ PurchaseOrder line or a PurchaseMaterial line (§Part-1's XOR). The       │
 * │ approved invoice-first flow adds a THIRD, mutually-exclusive origin: a   │
 * │ receipt line auto-created directly from a Supplier Invoice line, with no │
 * │ PO or Purchase behind it at all.                                        │
 * │                                                                          │
 * │ This is the reverse direction of the existing V-5 anchor                │
 * │ (`supplier_invoice_lines.goods_receipt_line_id`, invoice → the receipt   │
 * │ line it settles). That column stays untouched and keeps serving the      │
 * │ pre-existing manual-anchor flow unchanged. This new column answers a     │
 * │ different question — "which invoice line did THIS receipt line          │
 * │ originate from" — and, because it is a plain FK with no uniqueness       │
 * │ constraint, it also lets MULTIPLE receipt lines (e.g. two partial        │
 * │ deliveries) accumulate against the SAME invoice line: the reconciled     │
 * │ quantity for an invoice line is derived as SUM(net_received_quantity)    │
 * │ over every posted receipt line naming it, exactly mirroring how a        │
 * │ Purchase-Material-anchored line already derives its received quantity    │
 * │ instead of keeping a stored counter.                                     │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * ADDITIVE AND REVERSIBLE. Nullable, no backfill: every existing row keeps its
 * PO or Purchase-Material anchor and reads NULL here — i.e. "not
 * invoice-originated". RESTRICT on delete, matching the Purchase-Material
 * anchor migration's own reasoning: deleting an invoice line out from under an
 * already-posted receipt line would silently orphan physical stock history.
 */
return new class extends Migration
{
    private const INDEX = 'grl_supplier_invoice_line_idx';

    public function up(): void
    {
        if (! Schema::hasTable('goods_receipt_lines') || ! Schema::hasTable('supplier_invoice_lines')) {
            return;
        }

        if (Schema::hasColumn('goods_receipt_lines', 'supplier_invoice_line_id')) {
            return;
        }

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->char('supplier_invoice_line_id', 36)
                ->nullable()
                ->after('purchase_material_line_id');

            $table->foreign('supplier_invoice_line_id')
                ->references('id')->on('supplier_invoice_lines')
                ->restrictOnDelete();

            $table->index('supplier_invoice_line_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('goods_receipt_lines', 'supplier_invoice_line_id')) {
            return;
        }

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->dropForeign(['supplier_invoice_line_id']);
            $table->dropIndex(self::INDEX);
            $table->dropColumn('supplier_invoice_line_id');
        });
    }
};
