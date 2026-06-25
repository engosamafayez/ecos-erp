<?php

declare(strict_types=1);

namespace Modules\Inventory\ReceiptLayers\Application\Services;

use Modules\Inventory\InventoryItems\Domain\Exceptions\InsufficientStockException;
use Modules\Inventory\ReceiptLayers\Application\DTO\ConsumedLayerDTO;
use Modules\Inventory\ReceiptLayers\Application\DTO\ConsumptionResult;
use Modules\Inventory\ReceiptLayers\Domain\Contracts\InventoryLayerConsumptionRepositoryInterface;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;

/**
 * FIFO layer consumption engine.
 *
 * Consumes receipt layers in chronological order (oldest first) until the
 * requested quantity is satisfied. Creates an immutable audit record for
 * each layer slice consumed.
 *
 * MUST be called inside an existing DB::transaction() — it does not open one.
 */
final class InventoryLayerConsumptionService
{
    public function __construct(
        private readonly InventoryLayerConsumptionRepositoryInterface $repo,
    ) {}

    /**
     * @throws InsufficientStockException when open layers cannot cover the quantity
     */
    public function consume(
        string $inventoryItemId,
        string $productId,
        string $warehouseId,
        string $companyId,
        float $quantity,
        ?string $orderId = null,
        ?string $orderLineId = null,
    ): ConsumptionResult {
        // Load open layers for this product+warehouse in FIFO order (oldest first)
        $layers = InventoryReceiptLayer::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('remaining_qty', '>', 0)
            ->lockForUpdate()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $available = $layers->sum(fn ($l) => (float) $l->remaining_qty);

        if ($available < $quantity) {
            throw new InsufficientStockException($productId, $warehouseId, $quantity, $available);
        }

        $remaining       = $quantity;
        $consumedLayers  = [];
        $totalCost       = 0.0;

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }

            $layerRemaining = (float) $layer->remaining_qty;
            $consume        = min($remaining, $layerRemaining);
            $unitCost       = (float) $layer->landed_unit_cost;
            $sliceCost      = round($consume * $unitCost, 4);

            // Decrement the layer
            $layer->remaining_qty = round($layerRemaining - $consume, 4);
            $layer->save();

            // Audit record
            $this->repo->create([
                'order_id'                   => $orderId,
                'order_line_id'              => $orderLineId,
                'inventory_item_id'          => $inventoryItemId,
                'inventory_receipt_layer_id' => $layer->id,
                'product_id'                 => $productId,
                'warehouse_id'               => $warehouseId,
                'company_id'                 => $companyId,
                'quantity'                   => $consume,
                'unit_cost'                  => $unitCost,
                'total_cost'                 => $sliceCost,
            ]);

            $consumedLayers[] = new ConsumedLayerDTO(
                layerId:   $layer->id,
                quantity:  $consume,
                unitCost:  $unitCost,
                totalCost: $sliceCost,
            );

            $totalCost += $sliceCost;
            $remaining  = round($remaining - $consume, 4);
        }

        $weightedCost = $quantity > 0 ? round($totalCost / $quantity, 4) : 0.0;

        return new ConsumptionResult(
            totalQuantity:  $quantity,
            totalCost:      round($totalCost, 4),
            weightedCost:   $weightedCost,
            consumedLayers: $consumedLayers,
        );
    }
}
