<?php

declare(strict_types=1);

namespace Modules\Manufacturing\ManufacturingExecution\Infrastructure\Adapters;

use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\ReceiptLayers\Application\Services\InventoryLayerConsumptionService;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Manufacturing\ManufacturingExecution\Domain\Contracts\InventoryMutationInterface;
use Modules\Manufacturing\ManufacturingExecution\Domain\ValueObjects\ComponentConsumptionRecord;
use Modules\Manufacturing\ManufacturingPlanner\Domain\ValueObjects\ComponentConsumptionPlan;

/**
 * Infrastructure adapter that fulfils InventoryMutationInterface.
 *
 * Internally reuses:
 *   - InventoryItemRepository (on_hand_qty mutation + ledger entries)
 *   - InventoryLayerConsumptionService (FIFO layer decrement + audit records)
 *
 * Both operations happen inside the same DB::transaction() opened by the Executor.
 * This adapter never opens its own transaction.
 *
 * Negative-stock strategy (RC-2):
 *   When allow_negative_stock = true and the quantity to consume exceeds available
 *   FIFO layers, the on_hand_qty goes below zero (standard behaviour) and FIFO
 *   layers are consumed only up to what is available. This preserves exact-once
 *   semantics for FIFO cost tracking while allowing inventory to go negative.
 */
final class InventoryMutationAdapter implements InventoryMutationInterface
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItems,
        private readonly InventoryLayerConsumptionService $layerService,
    ) {}

    public function consumeComponent(
        ComponentConsumptionPlan $component,
        string $warehouseId,
        string $planId,
        string $companyId,
        string $executionUuid,
    ): ComponentConsumptionRecord {
        // 1. Find (or lazy-create) the inventory record, then lock it
        $item       = $this->inventoryItems->findOrCreate($warehouseId, $component->component_id, $companyId);
        $lockedItem = $this->inventoryItems->lockForUpdate($item->id);

        $onHandBefore = (float) $lockedItem->on_hand_qty;
        $onHandAfter  = $onHandBefore - $component->qty_to_consume;

        // 2. Decrement on_hand_qty (may go negative when allow_negative_stock = true)
        $lockedItem->on_hand_qty = $onHandAfter;
        $this->inventoryItems->save($lockedItem);

        // 3. Immutable production-consumption ledger entry
        $entry = $this->inventoryItems->recordEntry([
            'inventory_item_id' => $lockedItem->id,
            'warehouse_id'      => $warehouseId,
            'product_id'        => $component->component_id,
            'company_id'        => $companyId,
            'movement_type'     => LedgerMovementType::ProductionConsumption->value,
            'quantity'          => $component->qty_to_consume,
            'on_hand_before'    => $onHandBefore,
            'on_hand_after'     => $onHandAfter,
            'reserved_before'   => (float) $lockedItem->reserved_qty,
            'reserved_after'    => (float) $lockedItem->reserved_qty,
            'reference_type'    => 'manufacturing_plan',
            'reference_id'      => $planId,
            'notes'             => "Consumed for manufacturing execution {$executionUuid}",
        ]);

        // 4. FIFO layer tracking — consume up to available, never throw for negative-stock
        $this->consumeFifoLayers(
            component:     $component,
            warehouseId:   $warehouseId,
            inventoryItemId: $lockedItem->id,
            companyId:     $companyId,
        );

        return new ComponentConsumptionRecord(
            component_id:    $component->component_id,
            sku:             $component->sku,
            name:            $component->name,
            unit_symbol:     $component->unit_symbol,
            qty_consumed:    $component->qty_to_consume,
            on_hand_before:  $onHandBefore,
            on_hand_after:   $onHandAfter,
            went_negative:   $onHandAfter < 0.0,
            ledger_entry_id: $entry->id,
        );
    }

    public function produceFinishedGoods(
        string $productId,
        float $qty,
        string $warehouseId,
        string $planId,
        string $companyId,
        string $executionUuid,
    ): string {
        // 1. Find (or lazy-create) the finished goods inventory record, then lock it
        $item       = $this->inventoryItems->findOrCreate($warehouseId, $productId, $companyId);
        $lockedItem = $this->inventoryItems->lockForUpdate($item->id);

        $onHandBefore = (float) $lockedItem->on_hand_qty;
        $onHandAfter  = $onHandBefore + $qty;

        // 2. Increment on_hand_qty
        $lockedItem->on_hand_qty = $onHandAfter;
        $this->inventoryItems->save($lockedItem);

        // 3. Immutable production-output ledger entry
        $entry = $this->inventoryItems->recordEntry([
            'inventory_item_id' => $lockedItem->id,
            'warehouse_id'      => $warehouseId,
            'product_id'        => $productId,
            'company_id'        => $companyId,
            'movement_type'     => LedgerMovementType::ProductionOutput->value,
            'quantity'          => $qty,
            'on_hand_before'    => $onHandBefore,
            'on_hand_after'     => $onHandAfter,
            'reserved_before'   => (float) $lockedItem->reserved_qty,
            'reserved_after'    => (float) $lockedItem->reserved_qty,
            'reference_type'    => 'manufacturing_plan',
            'reference_id'      => $planId,
            'notes'             => "Finished goods produced by execution {$executionUuid}",
        ]);

        return $entry->id;
    }

    /**
     * Consume FIFO receipt layers, capped at available quantity.
     *
     * For components with allow_negative_stock = true, layers are consumed
     * only up to what is available (partial), preventing InsufficientStockException
     * while maintaining accurate FIFO cost records for the consumed portion.
     */
    private function consumeFifoLayers(
        ComponentConsumptionPlan $component,
        string $warehouseId,
        string $inventoryItemId,
        string $companyId,
    ): void {
        // Compute available FIFO qty without loading full rows.
        // lockForUpdate ensures the sum is consistent within this transaction.
        $availableInLayers = (float) InventoryReceiptLayer::query()
            ->where('product_id', $component->component_id)
            ->where('warehouse_id', $warehouseId)
            ->where('remaining_qty', '>', 0)
            ->lockForUpdate()
            ->sum('remaining_qty');

        $layerQtyToConsume = min($component->qty_to_consume, $availableInLayers);

        if ($layerQtyToConsume <= 0.0) {
            return; // No layers to consume (stock went negative or no receipts yet)
        }

        $this->layerService->consume(
            inventoryItemId: $inventoryItemId,
            productId:       $component->component_id,
            warehouseId:     $warehouseId,
            companyId:       $companyId,
            quantity:        $layerQtyToConsume,
        );
    }
}
