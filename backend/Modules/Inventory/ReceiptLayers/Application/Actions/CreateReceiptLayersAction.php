<?php

declare(strict_types=1);

namespace Modules\Inventory\ReceiptLayers\Application\Actions;

use Modules\CostManagement\Domain\Enums\CostUpdateSource;
use Modules\CostManagement\Domain\Services\EnterpriseCostEngine;
use Modules\CostManagement\Domain\Services\MaterialCostService;
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
    public function __construct(
        private readonly MaterialCostService $materialCostService,
    ) {}

    /**
     * Goods Receipt entry point — signature unchanged.
     *
     * Adapts the receipt to the document-agnostic {@see executeForLines()} below, so the
     * Goods Receipt path and the Mode 3 Supplier Invoice path share ONE layer-creation and
     * cost-propagation implementation rather than each carrying its own. Per the P-7 ruling:
     * neither document may implement its own FIFO algorithm, and no second inventory engine
     * may be created.
     *
     * @param  array<string, float>  $preReceiptQtys  Map of product_id → on_hand_qty BEFORE this receipt
     */
    public function execute(GoodsReceipt $receipt, array $preReceiptQtys): void
    {
        // Null for a Purchase-anchored receipt (TASK-PROC-PURCHASING-PHASE2-PART1).
        $po = $receipt->purchaseOrder;

        $lines = $receipt->lines
            ->filter(fn (GoodsReceiptLine $l) => $l->effectiveReceivedQty() > 0)
            ->map(fn (GoodsReceiptLine $l) => [
                'product_id' => $l->product_id,
                'quantity' => $l->effectiveReceivedQty(),
                'landed_unit_cost' => (float) ($l->landed_unit_cost ?? 0),
                'goods_receipt_line_id' => $l->id,
            ])
            ->values()
            ->all();

        $this->executeForLines(
            lines: $lines,
            warehouseId: $receipt->warehouse_id,
            companyId: $receipt->company_id ?? $po?->company_id ?? $receipt->warehouse?->company_id,
            // RD-1 — supplier identity for a Purchase-anchored receipt comes from the
            // purchase material line, never from a purchase order. The layer's supplier is
            // not decorative: supplier returns and supplier cost analytics attribute through
            // it, so it must be the same supplier the Purchase committed to.
            supplierId: $po?->supplier_id ?? $this->purchaseMaterialSupplierId($receipt),
            receiptDate: $receipt->receipt_date->toDateString(),
            preReceiptQtys: $preReceiptQtys,
            goodsReceiptId: $receipt->id,
            costMeta: ['goods_receipt_id' => $receipt->id],
        );
    }

    /**
     * Supplier of a Purchase-anchored receipt, read from the purchase material line.
     *
     * Queried through the query builder rather than the model so that resolving supplier
     * identity for a posting never depends on the actor's tenant scope — the receipt has
     * already been authorised by the time we get here.
     */
    private function purchaseMaterialSupplierId(GoodsReceipt $receipt): ?string
    {
        $lineIds = $receipt->lines
            ->pluck('purchase_material_line_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($lineIds === []) {
            return null;
        }

        $supplierId = \Illuminate\Support\Facades\DB::table('purchase_material_lines')
            ->whereIn('id', $lineIds)
            ->whereNotNull('supplier_id')
            ->value('supplier_id');

        return $supplierId === null ? null : (string) $supplierId;
    }

    /**
     * The canonical, document-agnostic inbound layer + cost propagation.
     *
     * Owns exactly what the P-7 ruling assigns to canonical inbound posting: FIFO receipt
     * layer creation and landed-cost propagation. Inventory quantity mutation and stock
     * ledger posting belong to `ReceiveStockAction` and are NOT duplicated here.
     *
     * @param  list<array{product_id: string, quantity: float, landed_unit_cost: float, goods_receipt_line_id?: string|null}>  $lines
     * @param  array<string, float>  $preReceiptQtys  product_id → on_hand_qty BEFORE this inbound
     * @param  array<string, mixed>  $costMeta  provenance recorded on the cost update
     */
    public function executeForLines(
        array $lines,
        string $warehouseId,
        ?string $companyId,
        ?string $supplierId,
        string $receiptDate,
        array $preReceiptQtys,
        ?string $goodsReceiptId = null,
        array $costMeta = [],
    ): void {
        $this->postLayers($lines, $warehouseId, $companyId, $supplierId, $receiptDate, $preReceiptQtys, $goodsReceiptId, $costMeta);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, float>  $preReceiptQtys
     * @param  array<string, mixed>  $costMeta
     */
    private function postLayers(
        array $lines,
        string $warehouseId,
        ?string $companyId,
        ?string $supplierId,
        string $receiptDate,
        array $preReceiptQtys,
        ?string $goodsReceiptId,
        array $costMeta,
    ): void {
        if ($lines === []) {
            return;
        }

        // M-02 fix: zero-cost lines still get a FIFO layer. Filtering by
        // landed_unit_cost > 0 left on_hand_qty inflated with no backing layer, causing
        // later FIFO consumption to fail with InsufficientStockException. Zero-cost stock
        // (free samples, internal allocations) is valid inventory.
        $activeLines = collect($lines)->filter(fn (array $l) => (float) $l['quantity'] > 0)->values();

        if ($activeLines->isEmpty()) {
            return;
        }

        // Batch-load products to avoid N queries inside the loop
        $productIds = $activeLines->pluck('product_id')->unique()->values()->all();
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        // Batch-fetch oldest open FIFO layer per product — 1 query replaces N
        $oldestLayers = InventoryReceiptLayer::query()
            ->whereIn('product_id', $productIds)
            ->where('remaining_qty', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($layers) => $layers->first());

        foreach ($activeLines as $line) {
            $netQty = (float) $line['quantity'];
            $landedUnitCost = (float) $line['landed_unit_cost'];
            $product = $products->get($line['product_id']);

            $salePriceSnapshot = $product ? (float) ($product->sale_price ?? 0) : null;

            // ── Create receipt layer ──────────────────────────────────────────
            InventoryReceiptLayer::query()->create([
                'company_id' => $companyId,
                'supplier_id' => $supplierId,
                'product_id' => $line['product_id'],
                'goods_receipt_id' => $goodsReceiptId,
                'goods_receipt_line_id' => $line['goods_receipt_line_id'] ?? null,
                'warehouse_id' => $warehouseId,
                'received_qty' => $netQty,
                'remaining_qty' => $netQty,
                'landed_unit_cost' => $landedUnitCost,
                'sale_price_snapshot' => $salePriceSnapshot > 0 ? $salePriceSnapshot : null,
                'receipt_date' => $receiptDate,
            ]);

            if ($product === null) {
                continue;
            }

            // ── Update product cost intelligence ─────────────────────────────
            $oldQty = $preReceiptQtys[$line['product_id']] ?? 0.0;
            $oldAvg = (float) ($product->average_cost ?? $landedUnitCost);
            // Canonical weighted-average — single definition on EnterpriseCostEngine.
            $newAvgCost = EnterpriseCostEngine::weightedAverageCost($oldQty, $oldAvg, $netQty, $landedUnitCost);

            $oldestLayer = $oldestLayers->get($line['product_id']);

            $product->update([
                'last_purchase_cost' => $landedUnitCost,
                'average_cost' => $newAvgCost,
                'last_purchase_date' => $receiptDate,
                'last_supplier_id' => $supplierId,
                'current_fifo_cost' => $oldestLayer?->landed_unit_cost ?? $landedUnitCost,
            ]);

            // Update official Material Cost and trigger full cascade to pricing reviews
            $this->materialCostService->update(
                material: $product,
                newCost: $landedUnitCost,
                source: CostUpdateSource::PurchaseInvoice,
                meta: $costMeta + ['company_id' => $companyId],
            );
        }
    }
}
