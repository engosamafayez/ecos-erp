<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Exceptions\OrderAlreadyReservedException;
use Modules\Commerce\Orders\Domain\Exceptions\OrderWarehouseNotAssignedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Inventory\InventoryItems\Application\Actions\ReserveStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;

final class ReserveOrderInventoryAction
{
    public function __construct(private readonly ReserveStockAction $reserveStock) {}

    public function execute(Order $order): void
    {
        if ($order->inventory_reserved_at !== null) {
            throw new OrderAlreadyReservedException($order->id);
        }

        if ($order->assigned_warehouse_id === null) {
            throw new OrderWarehouseNotAssignedException($order->id);
        }

        $order->loadMissing('lines', 'assignedWarehouse');

        $companyId = $order->assignedWarehouse->company_id;

        DB::transaction(function () use ($order, $companyId): void {
            foreach ($order->lines as $line) {
                $this->reserveStock->execute(new StockOperationDTO(
                    warehouse_id: $order->assigned_warehouse_id,
                    product_id: $line->product_id,
                    company_id: $companyId,
                    quantity: (float) $line->quantity,
                    reference_type: 'sales_order',
                    reference_id: $order->id,
                    notes: "Reserved for order #{$order->order_number}",
                ));
            }

            $order->update(['inventory_reserved_at' => now()]);
        });

        OrderEvent::log(
            orderId:     $order->id,
            type:        'inventory_reserved',
            description: "Inventory reserved for order #{$order->order_number} in warehouse.",
            payload:     ['warehouse_id' => $order->assigned_warehouse_id, 'line_count' => $order->lines->count()],
            module:      'orders',
            actionType:  'inventory',
        );
    }
}
