<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-1 — the company's authoritative goods-inward document.
 *
 * ADR-011 Mode 3 allows a Supplier Invoice to be a goods-inward path, and the Goods Receipt
 * workflow is the other. Both were permitted SIMULTANEOUSLY with nothing linking them, so one
 * physical delivery documented both ways posted to inventory twice — doubled quantity, two
 * ledger rows, two FIFO layers, two cost cascades.
 *
 * The three options considered in the closure audit were: (a) implement Mode 3 auto-generation,
 * (b) expose the existing `auto_receipt_id` FK on the create contract, and (c) make the two
 * paths mutually exclusive per company. (a) and (b) both reduce to operator discipline — an
 * operator who also raises a receipt by hand, or who omits the link, still double-posts — and
 * the repair task forbids relying on discipline, timing, or heuristic matching. (c) is the only
 * option that holds by construction, so this column is the contract.
 *
 * DEFAULT IS `goods_receipt`: the traditional receiving workflow keeps behaving exactly as it
 * does today, and the invoice path becomes financial-only unless a company explicitly opts into
 * Mode 3. Defaulting the other way would silently stop stock posting for every receipt-based
 * company, which is the far more damaging direction to be wrong in.
 *
 * VARCHAR + application enum rather than a DB CHECK: MySQL compatibility, consistent with how
 * `companies.currency` / `companies.timezone` and every other status column in this schema are
 * expressed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'goods_inward_mode')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->string('goods_inward_mode', 30)
                    ->default('goods_receipt')
                    ->after('timezone');
            });
        }

        // ── Tenant repair (B-2) — make `company_id` trustworthy before it is scoped on ──
        //
        // Both columns were added nullable with a ONE-SHOT backfill and no writer on the
        // create path, so every row created since is NULL. A tenant global scope filtering
        // on a permanently-NULL column would hide every document from every actor, so the
        // backfill is re-run here and the create paths now populate it.
        //
        // The derivations are the ones the original migrations defined, unchanged:
        // a receipt takes its purchase order's company, an invoice takes its warehouse's.
        if (Schema::hasColumn('goods_receipts', 'company_id')) {
            DB::statement(<<<'SQL'
                UPDATE goods_receipts gr
                JOIN purchase_orders po ON po.id = gr.purchase_order_id
                SET gr.company_id = po.company_id
                WHERE gr.company_id IS NULL
                  AND po.company_id IS NOT NULL
            SQL);

            // Receipts whose PO carries no company still resolve through the warehouse,
            // which is NOT NULL on both tables.
            DB::statement(<<<'SQL'
                UPDATE goods_receipts gr
                JOIN warehouses w ON w.id = gr.warehouse_id
                SET gr.company_id = w.company_id
                WHERE gr.company_id IS NULL
                  AND w.company_id IS NOT NULL
            SQL);
        }

        if (Schema::hasColumn('supplier_invoices', 'company_id')) {
            DB::statement(<<<'SQL'
                UPDATE supplier_invoices si
                JOIN warehouses w ON w.id = si.warehouse_id
                SET si.company_id = w.company_id
                WHERE si.company_id IS NULL
                  AND w.company_id IS NOT NULL
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'goods_inward_mode')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('goods_inward_mode');
            });
        }

        // The company_id backfill is deliberately not reversed: dropping ownership data
        // would re-open the tenant hole this migration exists to close.
    }
};
