<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Exceptions\OrderAlreadyShippedException;
use Modules\Commerce\Orders\Domain\Exceptions\OrderWarehouseNotAssignedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\InventoryItems\Application\Actions\ShipStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\ReceiptLayers\Application\Services\InventoryLayerConsumptionService;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ShipOrderInventoryAction
{
    public function __construct(
        private readonly ShipStockAction $shipStock,
        private readonly InventoryLayerConsumptionService $layerConsumption,
        private readonly InventoryItemRepositoryInterface $inventory,
    ) {}

    public function execute(Order $order): void
    {
        if ($order->inventory_shipped_at !== null) {
            throw new OrderAlreadyShippedException($order->id);
        }

        if ($order->assigned_warehouse_id === null) {
            throw new OrderWarehouseNotAssignedException($order->id);
        }

        if ($order->inventory_reserved_at === null) {
            throw new UnprocessableEntityHttpException(
                "Order [{$order->id}] cannot be shipped: inventory has not been reserved."
            );
        }

        $order->loadMissing('lines', 'assignedWarehouse');

        $companyId   = $order->assignedWarehouse->company_id;
        $warehouseId = $order->assigned_warehouse_id;

        DB::transaction(function () use ($order, $companyId, $warehouseId): void {
            $totalCogs = 0.0;

            foreach ($order->lines as $line) {
                /** @var OrderLine $line */
                $qty = (float) $line->quantity;

                // 1. Move physical stock
                $this->shipStock->execute(new StockOperationDTO(
                    warehouse_id: $warehouseId,
                    product_id:   $line->product_id,
                    company_id:   $companyId,
                    quantity:     $qty,
                    reference_type: 'sales_order',
                    reference_id:   $order->id,
                    notes:          "Shipped for order #{$order->order_number}",
                ));

                // 2. FIFO layer consumption (within same transaction)
                $inventoryItem = $this->inventory->findByWarehouseAndProduct($warehouseId, $line->product_id);

                if ($inventoryItem !== null) {
                    $result = $this->layerConsumption->consume(
                        inventoryItemId: $inventoryItem->id,
                        productId:       $line->product_id,
                        warehouseId:     $warehouseId,
                        companyId:       $companyId,
                        quantity:        $qty,
                        orderId:         $order->id,
                        orderLineId:     $line->id,
                    );

                    $totalCogs += $result->totalCost;

                    // Update current FIFO cost for the product after consumption
                    $this->refreshFifoCost($line->product_id, $warehouseId);
                }
            }

            // 3. Stamp COGS and margin on the order
            $revenue      = (float) $order->total;
            $margin       = $revenue - $totalCogs;
            $marginPct    = $revenue > 0 ? round($margin / $revenue * 100, 2) : null;

            $order->update([
                'inventory_shipped_at'  => now(),
                'actual_cogs_amount'    => round($totalCogs, 2),
                'actual_margin_amount'  => round($margin, 2),
                'actual_margin_percent' => $marginPct,
            ]);
        });
    }

    private function refreshFifoCost(string $productId, string $warehouseId): void
    {
        // Find the oldest remaining layer for this product in any warehouse
        // (FIFO cost is global — not warehouse-specific — as it reflects purchase cost)
        $oldestLayer = \Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer::query()
            ->where('product_id', $productId)
            ->where('remaining_qty', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        \Modules\Inventory\Products\Domain\Models\Product::query()
            ->where('id', $productId)
            ->update(['current_fifo_cost' => $oldestLayer?->landed_unit_cost]);
    }
}
