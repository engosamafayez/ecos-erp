<?php

declare(strict_types=1);

namespace Modules\Inventory\ReceiptLayers\Application\Actions;

use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;

/**
 * Creates inventory receipt layers and updates product cost intelligence
 * when a Goods Receipt is posted.
 *
 * Must be called INSIDE an existing DB transaction, after inventory quantities
 * have been updated by ReceiveStockAction.
 */
final class CreateReceiptLayersAction
{
    /**
     * @param  array<string, float>  $preReceiptQtys  Map of product_id → on_hand_qty BEFORE this receipt
     */
    public function execute(GoodsReceipt $receipt, array $preReceiptQtys): void
    {
        $po         = $receipt->purchaseOrder;
        $supplierId = $po->supplier_id;
        $receiptDate = $receipt->receipt_date->toDateString();

        foreach ($receipt->lines as $line) {
            /** @var GoodsReceiptLine $line */
            $netQty         = $line->effectiveReceivedQty();
            $landedUnitCost = (float) ($line->landed_unit_cost ?? 0);

            if ($netQty <= 0 || $landedUnitCost <= 0) {
                continue;
            }

            $product          = Product::query()->find($line->product_id);
            $salePriceSnapshot = $product ? (float) ($product->sale_price ?? 0) : null;

            // ── Create receipt layer ──────────────────────────────────────────
            InventoryReceiptLayer::query()->create([
                'supplier_id'           => $supplierId,
                'product_id'            => $line->product_id,
                'goods_receipt_id'      => $receipt->id,
                'goods_receipt_line_id' => $line->id,
                'warehouse_id'          => $receipt->warehouse_id,
                'received_qty'          => $netQty,
                'remaining_qty'         => $netQty,
                'landed_unit_cost'      => $landedUnitCost,
                'sale_price_snapshot'   => $salePriceSnapshot > 0 ? $salePriceSnapshot : null,
                'receipt_date'          => $receiptDate,
            ]);

            // ── Update product cost intelligence ─────────────────────────────
            if ($product === null) {
                continue;
            }

            $oldQty  = $preReceiptQtys[$line->product_id] ?? 0.0;
            $oldAvg  = (float) ($product->average_cost ?? $landedUnitCost);

            // Weighted average cost: (old_value + new_value) / (old_qty + new_qty)
            $totalQty  = $oldQty + $netQty;
            $newAvgCost = $totalQty > 0
                ? round(($oldQty * $oldAvg + $netQty * $landedUnitCost) / $totalQty, 4)
                : $landedUnitCost;

            // current_fifo_cost = cost of the oldest remaining layer
            $oldestLayer = \Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer::query()
                ->where('product_id', $line->product_id)
                ->where('remaining_qty', '>', 0)
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            $product->update([
                'last_purchase_cost' => $landedUnitCost,
                'average_cost'       => $newAvgCost,
                'last_purchase_date' => $receiptDate,
                'last_supplier_id'   => $supplierId,
                'current_fifo_cost'  => $oldestLayer?->landed_unit_cost ?? $landedUnitCost,
            ]);
        }
    }
}
