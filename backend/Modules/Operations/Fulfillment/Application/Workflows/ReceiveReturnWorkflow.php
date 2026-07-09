<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Inventory\InventoryItems\Application\Actions\AdjustmentInAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Operations\Fulfillment\Domain\Events\InventoryRestoredEvent;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;
use Modules\Operations\Fulfillment\Domain\Models\CustomerReturn;

/**
 * Processes a warehouse acceptance of returned goods.
 *
 * For each sellable line, issues an AdjustmentIn to restore stock at the
 * order's assigned warehouse. Damaged / destroyed lines do not restore inventory.
 *
 * Closes GAP-05 (warehouse leg): inventory returns to physical on-hand only
 * after warehouse confirmation — not automatically on driver rejection.
 *
 * This is NOT an FulfillmentWorkflowInterface implementor — it operates on a
 * CustomerReturn (not a single workflow-routed Order). Called directly from
 * FulfillmentController.
 */
final class ReceiveReturnWorkflow
{
    public function __construct(
        private readonly AdjustmentInAction $adjustmentIn,
    ) {}

    /**
     * Accept a customer return and restore sellable stock.
     *
     * @param array<string, string> $lineConditions  Override line conditions: ['line_id' => 'sellable'|'damaged'|'destroyed']
     */
    public function execute(
        CustomerReturn $customerReturn,
        string         $actorId,
        ?string        $warehouseNotes = null,
        array          $lineConditions = [],
    ): CustomerReturn {
        if (! $customerReturn->isPendingInspection()) {
            throw new WorkflowPreconditionException(
                "CustomerReturn [{$customerReturn->id}] is not pending inspection (status: {$customerReturn->status})."
            );
        }

        $customerReturn->loadMissing(['lines', 'order']);

        $order = $customerReturn->order;

        if (! $order instanceof Order || $order->assigned_warehouse_id === null) {
            throw new WorkflowPreconditionException(
                "CustomerReturn [{$customerReturn->id}] references an order with no assigned warehouse."
            );
        }

        $warehouseId = $order->assigned_warehouse_id;
        $companyId   = $order->company_id ?? '';
        $linesRestored = 0;

        DB::transaction(function () use ($customerReturn, $order, $warehouseId, $companyId, $actorId, $lineConditions, $warehouseNotes, &$linesRestored): void {
            foreach ($customerReturn->lines as $line) {
                // Allow override of condition during inspection
                $condition = $lineConditions[$line->id] ?? $line->condition;

                if ($condition !== 'sellable') {
                    $line->update(['condition' => $condition]);
                    continue;
                }

                // Restore inventory via AdjustmentIn (recorded as AdjustmentIn movement)
                $this->adjustmentIn->execute(new StockOperationDTO(
                    warehouse_id:   $warehouseId,
                    product_id:     $line->product_id,
                    company_id:     $companyId,
                    quantity:       $line->quantity_returned,
                    reference_type: 'customer_return',
                    reference_id:   $customerReturn->id,
                    notes:          "Return accepted for order #{$order->order_number}. Condition: sellable.",
                ));

                $line->update(['condition' => $condition]);
                $linesRestored++;
            }

            $customerReturn->update([
                'status'                => 'accepted',
                'accepted_at'           => now(),
                'inspector_id'          => $actorId,
                'inspected_at'          => now(),
                'warehouse_notes'       => $warehouseNotes,
                'inventory_restored_at' => $linesRestored > 0 ? now() : null,
            ]);
        });

        $customerReturn->refresh();

        OrderEvent::log(
            orderId:     $order->id,
            type:        'return_received',
            description: "CustomerReturn #{$customerReturn->return_number} accepted. {$linesRestored} line(s) restored to inventory.",
            payload:     ['customer_return_id' => $customerReturn->id, 'lines_restored' => $linesRestored],
            actorId:     $actorId,
        );

        event(new InventoryRestoredEvent(
            orderId:       $order->id,
            returnId:      $customerReturn->id,
            companyId:     $companyId,
            warehouseId:   $warehouseId,
            linesRestored: $linesRestored,
            restoredAt:    now()->toIso8601String(),
            actorId:       $actorId,
        ));

        return $customerReturn;
    }
}
