<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Queries;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierNotFoundException;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

final class GetSupplierAnalyticsQuery
{
    /**
     * Aggregate purchasing and inventory metrics for a supplier.
     *
     * @return array<string, mixed>
     */
    public function execute(string $supplierId): array
    {
        $supplier = Supplier::query()->find($supplierId);

        if ($supplier === null) {
            throw new SupplierNotFoundException($supplierId);
        }

        // ── Purchasing totals from posted GRs ─────────────────────────────────
        $purchasing = GoodsReceipt::query()
            ->join('purchase_orders', 'goods_receipts.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_orders.supplier_id', $supplierId)
            ->where('goods_receipts.status', GoodsReceiptStatus::Posted->value)
            ->whereNull('goods_receipts.deleted_at')
            ->selectRaw('
                COUNT(goods_receipts.id) as total_purchases,
                COALESCE(SUM(goods_receipts.invoice_total_amount), 0) as total_invoiced,
                COALESCE(SUM(goods_receipts.paid_amount), 0) as total_paid,
                MAX(goods_receipts.receipt_date) as last_purchase_date
            ')
            ->first();

        $totalInvoiced   = (float) ($purchasing?->total_invoiced ?? 0);
        $totalPaid       = (float) ($purchasing?->total_paid ?? 0);
        $outstandingBalance = max(0.0, $totalInvoiced - $totalPaid);

        // ── Current inventory from receipt layers ─────────────────────────────
        $inventory = InventoryReceiptLayer::query()
            ->where('supplier_id', $supplierId)
            ->where('remaining_qty', '>', 0)
            ->selectRaw('
                COALESCE(SUM(remaining_qty), 0) as current_inventory_quantity,
                COALESCE(SUM(remaining_qty * landed_unit_cost), 0) as current_inventory_cost_value,
                COALESCE(SUM(CASE WHEN sale_price_snapshot IS NOT NULL THEN remaining_qty * sale_price_snapshot ELSE 0 END), 0) as current_inventory_sale_value
            ')
            ->first();

        $costValue   = (float) ($inventory?->current_inventory_cost_value ?? 0);
        $saleValue   = (float) ($inventory?->current_inventory_sale_value ?? 0);
        $grossProfit = max(0.0, $saleValue - $costValue);
        $marginPct   = $saleValue > 0 ? round($grossProfit / $saleValue * 100, 2) : 0.0;

        return [
            'supplier_id'   => $supplierId,
            'supplier_name' => $supplier->name,
            'supplier_code' => $supplier->code,

            // Purchasing
            'total_purchases'     => (int) ($purchasing?->total_purchases ?? 0),
            'total_invoiced'      => round($totalInvoiced, 2),
            'total_paid'          => round($totalPaid, 2),
            'outstanding_balance' => round($outstandingBalance, 2),
            'last_purchase_date'  => $purchasing?->last_purchase_date,

            // Inventory from open receipt layers (actual remaining stock)
            'current_inventory_quantity'      => round((float) ($inventory?->current_inventory_quantity ?? 0), 4),
            'current_inventory_cost_value'    => round($costValue, 2),
            'current_inventory_sale_value'    => round($saleValue, 2),
            'potential_gross_profit'          => round($grossProfit, 2),

            // New: open-layer profitability
            'inventory_remaining_cost'          => round($costValue, 2),
            'inventory_remaining_sale_value'     => round($saleValue, 2),
            'inventory_remaining_profit'         => round($grossProfit, 2),
            'inventory_remaining_margin_percent' => $marginPct,
        ];
    }
}
