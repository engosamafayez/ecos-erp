<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Exceptions\OrderAlreadyReleasedException;
use Modules\Commerce\Orders\Domain\Exceptions\OrderWarehouseNotAssignedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Inventory\InventoryItems\Application\Actions\ReleaseStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;

final class ReleaseOrderInventoryAction
{
    public function __construct(private readonly ReleaseStockAction $releaseStock) {}

    public function execute(Order $order): void
    {
        if ($order->inventory_released_at !== null) {
            throw new OrderAlreadyReleasedException($order->id);
        }

        if ($order->assigned_warehouse_id === null) {
            throw new OrderWarehouseNotAssignedException($order->id);
        }

        // Never reserved → just stamp the release timestamp (nothing to un-reserve in stock)
        if ($order->inventory_reserved_at === null) {
            $order->update(['inventory_released_at' => now()]);

            OrderEvent::log(
                orderId:     $order->id,
                type:        'inventory_released',
                description: "Inventory reservation released for order #{$order->order_number} (was not reserved).",
                payload:     ['warehouse_id' => $order->assigned_warehouse_id],
                module:      'orders',
            );

            return;
        }

        $order->loadMissing('lines', 'assignedWarehouse');

        $companyId = $order->assignedWarehouse->company_id;

        DB::transaction(function () use ($order, $companyId): void {
            foreach ($order->lines as $line) {
                $this->releaseStock->execute(new StockOperationDTO(
                    warehouse_id: $order->assigned_warehouse_id,
                    product_id: $line->product_id,
                    company_id: $companyId,
                    quantity: (float) $line->quantity,
                    reference_type: 'sales_order',
                    reference_id: $order->id,
                    notes: "Released reservation for order #{$order->order_number}",
                ));
            }

            $order->update(['inventory_released_at' => now()]);
        });

        OrderEvent::log(
            orderId:     $order->id,
            type:        'inventory_released',
            description: "Inventory reservation released for order #{$order->order_number}.",
            payload:     ['warehouse_id' => $order->assigned_warehouse_id, 'line_count' => $order->lines->count()],
            module:      'orders',
        );
    }
}
