<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;

/**
 * The ONE definition of Required / Received / Remaining for a Purchase Material line.
 *
 * TASK-PROC-PURCHASING-PHASE2-PART1, decisions RD-2 and RD-3.
 *
 *   Required       = COALESCE(agreed_qty, requested_qty)   — the negotiated quantity when a
 *                    buyer has selected a supplier, the requested quantity otherwise.
 *   Received Gross = Σ posted goods-receipt lines anchored to this Purchase line. Returns are
 *                    NOT netted out here: a return is a separate outbound document with its own
 *                    ceiling, and rewriting receipt history to absorb it would destroy the audit
 *                    trail. Net-of-returns is a DIFFERENT number, computed elsewhere (RD-4).
 *   Remaining      = max(0, Required − Received Gross)
 *
 * WHY DERIVED AND NOT A STORED COUNTER. The legacy `purchase_order_lines.received_qty` is a
 * stored counter incremented inside the posting transaction. It has no reversal path (a posted
 * receipt can never be un-posted) and it already drifts: approved supplier returns remove stock
 * but never decrement it. A derived sum reads the very rows the stock ledger and the FIFO layers
 * were built from, so it is structurally incapable of drifting or double-counting. This mirrors
 * the newer certified pattern used by the supplier ledger, the return allowance and the invoice
 * allowance — all derived, none stored.
 */
final class PurchaseMaterialReceivingService
{
    /** Required quantity for a line — RD-2. */
    public function requiredQty(PurchaseMaterialLine $line): float
    {
        $agreed = $line->agreed_qty;

        return round((float) ($agreed !== null ? $agreed : $line->requested_qty), 4);
    }

    /**
     * Gross received quantity for a line — RD-3.
     *
     * Only POSTED receipts count: a draft receipt has moved no stock. Soft-deleted receipt
     * headers are excluded explicitly because `goods_receipts` soft-deletes while
     * `goods_receipt_lines` does not, so the lines of a deleted header remain in the table.
     */
    public function receivedGross(string $purchaseMaterialLineId): float
    {
        $sum = DB::table('goods_receipt_lines as grl')
            ->join('goods_receipts as gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->where('grl.purchase_material_line_id', $purchaseMaterialLineId)
            ->where('gr.status', 'posted')
            ->whereNull('gr.deleted_at')
            ->sum(DB::raw('COALESCE(grl.net_received_quantity, grl.received_quantity)'));

        return round((float) $sum, 4);
    }

    /** Remaining quantity still to receive — never negative. */
    public function remaining(PurchaseMaterialLine $line): float
    {
        return round(max(0.0, $this->requiredQty($line) - $this->receivedGross((string) $line->id)), 4);
    }

    /**
     * Gross received for many lines at once — one grouped read, so a list or drawer never
     * issues a query per row.
     *
     * @param  list<string>  $purchaseMaterialLineIds
     * @return array<string, float> line id => received gross
     */
    public function receivedGrossFor(array $purchaseMaterialLineIds): array
    {
        if ($purchaseMaterialLineIds === []) {
            return [];
        }

        return DB::table('goods_receipt_lines as grl')
            ->join('goods_receipts as gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->whereIn('grl.purchase_material_line_id', $purchaseMaterialLineIds)
            ->where('gr.status', 'posted')
            ->whereNull('gr.deleted_at')
            ->groupBy('grl.purchase_material_line_id')
            ->selectRaw('grl.purchase_material_line_id AS line_id, SUM(COALESCE(grl.net_received_quantity, grl.received_quantity)) AS received')
            ->pluck('received', 'line_id')
            ->map(fn ($v): float => round((float) $v, 4))
            ->all();
    }
}
