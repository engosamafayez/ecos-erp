<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Disassembly\Infrastructure\Adapters;

use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\ReceiptLayers\Application\Services\InventoryLayerConsumptionService;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Manufacturing\Disassembly\Domain\ValueObjects\ComponentProductionPlan;
use Modules\Manufacturing\Disassembly\Domain\ValueObjects\ComponentProductionRecord;

/**
 * Inventory mutations for the Disassembly Executor.
 *
 * Inverse of InventoryMutationAdapter:
 *   consumeFinishedGoods() — deducts FG inventory (DisassemblyConsumption ledger + FIFO layers)
 *   produceComponent()     — adds component inventory (DisassemblyOutput ledger)
 *
 * Both operations MUST run inside the DB::transaction() opened by DisassemblyExecutor.
 * This adapter never opens its own transaction.
 *
 * FIFO on FG consumption: FIFO layers are consumed only up to what is available.
 * Manufactured FG typically has no FIFO layers (production_output doesn't create them),
 * so the layer consumption is gracefully skipped in those cases.
 *
 * FIFO on component production: components produced by disassembly DO create receipt
 * layers, valued at the cost frozen when the recipe was approved. Until this was
 * implemented, disassembly added quantity to inventory with no cost basis at all —
 * those components would later be consumed against whatever unrelated layers
 * happened to exist, or none, silently corrupting valuation.
 */
final class DisassemblyInventoryAdapter
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItems,
        private readonly InventoryLayerConsumptionService $layerService,
    ) {}

    /**
     * Consume the finished good from inventory.
     *
     * Returns the ledger entry ID for audit tracking.
     */
    public function consumeFinishedGoods(
        string $productId,
        float $qty,
        string $warehouseId,
        string $planId,
        string $companyId,
        string $executionUuid,
    ): string {
        $item = $this->inventoryItems->findOrCreate($warehouseId, $productId, $companyId);
        $lockedItem = $this->inventoryItems->lockForUpdate($item->id);

        $onHandBefore = (float) $lockedItem->on_hand_qty;
        $onHandAfter = $onHandBefore - $qty;

        $lockedItem->on_hand_qty = $onHandAfter;
        $this->inventoryItems->save($lockedItem);

        $entry = $this->inventoryItems->recordEntry([
            'inventory_item_id' => $lockedItem->id,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'company_id' => $companyId,
            'movement_type' => LedgerMovementType::DisassemblyConsumption->value,
            'quantity' => $qty,
            'on_hand_before' => $onHandBefore,
            'on_hand_after' => $onHandAfter,
            'reserved_before' => (float) $lockedItem->reserved_qty,
            'reserved_after' => (float) $lockedItem->reserved_qty,
            'reference_type' => 'disassembly_plan',
            'reference_id' => $planId,
            'notes' => "Finished good consumed for disassembly {$executionUuid}",
        ]);

        // Consume FIFO layers if available (gracefully skipped for manufactured FG with no layers)
        $this->consumeFifoLayers($productId, $warehouseId, (string) $lockedItem->id, $companyId, $qty);

        return $entry->id;
    }

    /**
     * Produce a component back into inventory.
     *
     * Returns a ComponentProductionRecord with before/after quantities and the ledger entry ID.
     */
    public function produceComponent(
        ComponentProductionPlan $component,
        string $warehouseId,
        string $planId,
        string $companyId,
        string $executionUuid,
    ): ComponentProductionRecord {
        $item = $this->inventoryItems->findOrCreate($warehouseId, $component->component_id, $companyId);
        $lockedItem = $this->inventoryItems->lockForUpdate($item->id);

        $onHandBefore = (float) $lockedItem->on_hand_qty;
        $onHandAfter = $onHandBefore + $component->qty_to_produce;

        $lockedItem->on_hand_qty = $onHandAfter;
        $this->inventoryItems->save($lockedItem);

        $entry = $this->inventoryItems->recordEntry([
            'inventory_item_id' => $lockedItem->id,
            'warehouse_id' => $warehouseId,
            'product_id' => $component->component_id,
            'company_id' => $companyId,
            'movement_type' => LedgerMovementType::DisassemblyOutput->value,
            'quantity' => $component->qty_to_produce,
            'on_hand_before' => $onHandBefore,
            'on_hand_after' => $onHandAfter,
            'reserved_before' => (float) $lockedItem->reserved_qty,
            'reserved_after' => (float) $lockedItem->reserved_qty,
            'reference_type' => 'disassembly_plan',
            'reference_id' => $planId,
            'notes' => "Component produced by disassembly {$executionUuid}",
        ]);

        $this->createReceiptLayer($component, $warehouseId, $companyId);

        return new ComponentProductionRecord(
            component_id: $component->component_id,
            sku: $component->sku,
            name: $component->name,
            unit_symbol: $component->unit_symbol,
            qty_produced: $component->qty_to_produce,
            on_hand_before: $onHandBefore,
            on_hand_after: $onHandAfter,
            ledger_entry_id: $entry->id,
        );
    }

    /**
     * Open a FIFO receipt layer for a component returned to stock by disassembly.
     *
     * The layer's cost is the recipe's approved cost for that material — never the
     * finished product's cost and never a share of its FIFO layers. Taking a
     * product apart does not tell you what its parts are worth; the recipe that
     * defined those parts does.
     *
     * `supplier_id` and `goods_receipt_id` stay null: this stock came from a
     * disassembly, not a purchase, and inventing a receipt to fill them would make
     * the layer indistinguishable from real procurement.
     *
     * A zero unit cost is not written. DisassemblyWorkflow already blocks any plan
     * whose components are unpriced, so reaching here without a cost means the plan
     * was constructed by some path that skipped that gate — recording a zero-cost
     * layer would bake the error permanently into inventory valuation, whereas
     * skipping it leaves the quantity visibly uncosted and recoverable.
     */
    private function createReceiptLayer(
        ComponentProductionPlan $component,
        string $warehouseId,
        string $companyId,
    ): void {
        if ($component->unit_cost <= 0.0 || $component->qty_to_produce <= 0.0) {
            return;
        }

        InventoryReceiptLayer::query()->create([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'product_id' => $component->component_id,
            'supplier_id' => null,
            'goods_receipt_id' => null,
            'goods_receipt_line_id' => null,
            'received_qty' => $component->qty_to_produce,
            'remaining_qty' => $component->qty_to_produce,
            'landed_unit_cost' => $component->unit_cost,
            'receipt_date' => now(),
        ]);
    }

    /**
     * Consume FIFO receipt layers for the FG, capped at available quantity.
     *
     * Manufactured FG typically has no FIFO layers — this method gracefully
     * returns without error when no layers are found.
     */
    private function consumeFifoLayers(
        string $productId,
        string $warehouseId,
        string $inventoryItemId,
        string $companyId,
        float $qty,
    ): void {
        $availableInLayers = (float) InventoryReceiptLayer::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('remaining_qty', '>', 0)
            ->lockForUpdate()
            ->sum('remaining_qty');

        $layerQtyToConsume = min($qty, $availableInLayers);

        if ($layerQtyToConsume <= 0.0) {
            return; // No layers — graceful skip (normal for manufactured FG)
        }

        $this->layerService->consume(
            inventoryItemId: $inventoryItemId,
            productId: $productId,
            warehouseId: $warehouseId,
            companyId: $companyId,
            quantity: $layerQtyToConsume,
        );
    }
}
