<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Application\Actions\ShipOrderInventoryAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Operations\Fulfillment\Domain\Events\OrderDispatchedEvent;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;

/**
 * Ships inventory for every order on a vehicle when it is dispatched.
 *
 * Closes GAP-02 (ShipOrderInventoryAction never called for manual orders) and
 * GAP-03 (VehicleInventoryService disconnected from main InventoryItem system).
 *
 * This is NOT an FulfillmentWorkflowInterface implementor — it operates on a
 * VehicleAssignment (multiple orders) rather than a single Order. It is called
 * directly from DispatchVehicleAction inside that action's transaction.
 */
final class LoadVehicleWorkflow
{
    public function __construct(
        private readonly ShipOrderInventoryAction $shipInventory,
    ) {}

    /**
     * Ship inventory for all orders loaded onto this vehicle assignment.
     *
     * @return list<Order> Orders successfully transitioned to out_for_delivery.
     */
    public function execute(VehicleAssignment $assignment, string $actorId): array
    {
        $orderIds = AllocationRecord::where('vehicle_assignment_id', $assignment->id)
            ->distinct()
            ->pluck('order_id');

        if ($orderIds->isEmpty()) {
            return [];
        }

        $shipped = [];

        // All orders on this vehicle ship atomically — one failure rolls back all.
        // This runs as a savepoint inside DispatchVehicleAction's outer transaction.
        DB::transaction(function () use ($orderIds, $assignment, $actorId, &$shipped): void {
            foreach ($orderIds as $orderId) {
                $order = Order::find($orderId);

                if ($order === null) {
                    continue;
                }

                // Idempotent — skip if already shipped (e.g. partial re-dispatch)
                if ($order->inventory_shipped_at !== null) {
                    continue;
                }

                // ShipOrderInventoryAction opens its own savepoint; FIFO consumption included
                $this->shipInventory->execute($order);

                $order->update(['status' => OrderStatus::OutForDelivery]);
                $order->refresh();

                $shipped[] = $order;
            }
        });

        // Audit trail + events after the savepoint releases
        foreach ($shipped as $order) {
            OrderEvent::log(
                orderId:     $order->id,
                type:        'load_vehicle',
                description: "Order #{$order->order_number} inventory shipped. Vehicle {$assignment->assignment_number} dispatched.",
                payload:     ['vehicle_assignment_id' => $assignment->id],
                actorId:     $actorId,
            );

            event(new OrderDispatchedEvent(
                orderId:             $order->id,
                orderNumber:         $order->order_number,
                companyId:           $order->company_id ?? '',
                vehicleAssignmentId: $assignment->id,
                vehicleId:           $assignment->vehicle_id,
                driverId:            null,
                cogsAmount:          (float) ($order->actual_cogs_amount ?? 0),
                dispatchedAt:        now()->toIso8601String(),
                actorId:             $actorId,
            ));
        }

        return $shipped;
    }
}
