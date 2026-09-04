<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Exceptions\OrderAlreadyShippedException;
use Modules\Commerce\Orders\Domain\Exceptions\OrderWarehouseNotAssignedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Commerce\Orders\Domain\Models\OrderReservationAudit;
use Modules\Inventory\InventoryItems\Application\Actions\ShipStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\ReceiptLayers\Application\Services\InventoryLayerConsumptionService;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ShipOrderInventoryAction
{
    public function __construct(
        private readonly ShipStockAction $shipStock,
        private readonly InventoryLayerConsumptionService $layerConsumption,
        private readonly InventoryItemRepositoryInterface $inventory,
    ) {}

    /**
     * @param  array<string, float>|null  $lineQuantities  Map of order_line_id => qty to ship.
     *                                                     When null, the full reserved_qty per line is used.
     *                                                     Used for split-shipment (P1-001): each vehicle
     *                                                     ships only the quantities allocated to it.
     */
    public function execute(Order $order, ?array $lineQuantities = null): void
    {
        if ($order->inventory_shipped_at !== null) {
            throw new OrderAlreadyShippedException($order->id);
        }

        $previousReservationStatus = $order->reservation_status?->value;

        if ($order->assigned_warehouse_id === null) {
            throw new OrderWarehouseNotAssignedException($order->id);
        }

        if ($order->inventory_reserved_at === null) {
            throw new UnprocessableEntityHttpException(
                "Order [{$order->id}] cannot be shipped: inventory has not been reserved.",
            );
        }

        $order->loadMissing('lines', 'assignedWarehouse');

        $companyId = $order->assignedWarehouse->company_id;
        $warehouseId = $order->assigned_warehouse_id;

        DB::transaction(function () use ($order, $companyId, $warehouseId, $lineQuantities, $previousReservationStatus): void {
            $totalCogs = 0.0;

            foreach ($order->lines as $line) {
                /** @var OrderLine $line */
                // When lineQuantities is provided (split-shipment path), use the quantity
                // allocated to this specific vehicle for this line. Otherwise fall back to
                // the fully-reserved quantity — the standard full-shipment path.
                $qty = $lineQuantities !== null
                    ? (float) ($lineQuantities[$line->id] ?? 0.0)
                    : (float) ($line->reserved_qty ?? 0.0);

                if ($qty <= 0.0) {
                    continue;
                }

                // 1. Move physical stock
                $this->shipStock->execute(new StockOperationDTO(
                    warehouse_id: $warehouseId,
                    product_id: $line->product_id,
                    company_id: $companyId,
                    quantity: $qty,
                    reference_type: 'sales_order',
                    reference_id: $order->id,
                    notes: "Shipped for order #{$order->order_number}",
                ));

                // TASK-...-FINAL-CROSS-SURFACE-CLOSURE-005 §2-§6 — the write this action was
                // missing: shipping the WAREHOUSE stock above never touched the corresponding
                // order-line field, leaving Reserved > 0 on a line whose units had physically
                // shipped. DECREMENT by $qty (the amount actually shipped in THIS call), not an
                // unconditional zero — mirrors ShipStockAction's own reserved_qty pattern
                // exactly ($reservedAfter = $reservedBefore - $dto->quantity, above) because
                // this action supports split-shipment across vehicles (LoadVehicleWorkflow
                // passes $lineQuantities for only ONE vehicle's allocation, which can be less
                // than the line's full reserved_qty); a blind zero here would falsely clear a
                // remaining reservation the domain has not actually shipped yet. In the
                // ordinary single-shipment path (DispatchOrderWorkflow, $lineQuantities=null,
                // $qty === the line's full reserved_qty) this decrement still lands on exactly
                // 0 — the same result a zero-set would give, reached the same way in every case.
                $line->update(['reserved_qty' => max(0.0, (float) ($line->reserved_qty ?? 0.0) - $qty)]);

                // 2. FIFO layer consumption (within same transaction).
                //    C-002: use company-scoped lookup so the audit record's inventory_item_id
                //    always references this tenant's InventoryItem, not another company's.
                $inventoryItem = $this->inventory->findByWarehouseProductAndCompany($warehouseId, $line->product_id, $companyId);

                if ($inventoryItem !== null) {
                    $result = $this->layerConsumption->consume(
                        inventoryItemId: $inventoryItem->id,
                        productId: $line->product_id,
                        warehouseId: $warehouseId,
                        companyId: $companyId,
                        quantity: $qty,
                        orderId: $order->id,
                        orderLineId: $line->id,
                    );

                    $totalCogs += $result->totalCost;

                    // Update current FIFO cost for the product after consumption
                    $this->refreshFifoCost($line->product_id, $warehouseId);
                }
            }

            // 3. Stamp COGS and margin on the order. ACCUMULATED across calls, not
            //    overwritten — TASK-...-FINAL-CROSS-SURFACE-CLOSURE-005-R1 §8. A split
            //    order's later vehicle now actually reaches this method (see the
            //    completion check below), so a second real call had to stop erasing the
            //    first vehicle's COGS contribution. For the ordinary single-call path
            //    (order.actual_cogs_amount starts null/0) this is numerically identical
            //    to the previous overwrite.
            $previousCogs = (float) ($order->actual_cogs_amount ?? 0.0);
            $cumulativeCogs = round($previousCogs + $totalCogs, 2);
            $revenue = (float) $order->total;
            $margin = round($revenue - $cumulativeCogs, 2);
            $marginPct = $revenue > 0 ? round($margin / $revenue * 100, 2) : null;

            // Is the ORDER — not just this call's lines — now fully shipped?
            // TASK-...-FINAL-CROSS-SURFACE-CLOSURE-005-R1 §3-§7. A split order's lines can
            // be carried by several VehicleAssignments (allocation_records.order_id is
            // already denormalized — no join needed). DispatchVehicleAction stamps THIS
            // vehicle's own dispatched_at before calling into this action (same
            // transaction), so this query already sees it. An order with no allocation
            // records at all never entered the Loading OS (DispatchOrderWorkflow's direct
            // path) — allocation_records.vehicle_assignment_id is NOT NULL, so that case is
            // schema-guaranteed to be "no rows", preserving today's stamp-immediately
            // behavior for the common single-shipment case exactly.
            $assignmentIds = AllocationRecord::query()
                ->where('order_id', $order->id)
                ->distinct()
                ->pluck('vehicle_assignment_id');
            $isFullyShipped = $assignmentIds->isEmpty()
                || VehicleAssignment::query()->whereIn('id', $assignmentIds)->whereNull('dispatched_at')->doesntExist();

            $order->update(array_merge(
                [
                    'actual_cogs_amount' => $cumulativeCogs,
                    'actual_margin_amount' => $margin,
                    'actual_margin_percent' => $marginPct,
                ],
                $isFullyShipped ? [
                    'inventory_shipped_at' => now(),
                    'reservation_status' => ReservationStatus::Transferred->value,
                ] : [],
            ));

            // The order-level reservation_status transition — and its audit record — only
            // actually happens once every vehicle carrying this order's lines has
            // dispatched. A partial vehicle shipment still moves real stock/FIFO/COGS
            // above; it just doesn't (yet) flip the order to "fully shipped". Audited
            // inside the transaction so it commits or rolls back atomically with the
            // shipment (F-INV-H6 fix, preserved).
            if ($isFullyShipped) {
                OrderReservationAudit::record(
                    orderId: $order->id,
                    fromStatus: $previousReservationStatus,
                    toStatus: ReservationStatus::Transferred->value,
                    reason: 'Inventory transferred to vehicle during loading',
                    warehouseId: $order->assigned_warehouse_id,
                    meta: ['line_count' => $order->lines->count()],
                    actorId: Auth::id(),
                    actorType: Auth::check() ? 'user' : 'system',
                );
            }
        });
    }

    private function refreshFifoCost(string $productId, string $warehouseId): void
    {
        // BUG-08 fix: scope to the warehouse that shipped to get the correct per-warehouse
        // FIFO cost. Without this, multi-warehouse deployments use a layer from a different
        // warehouse — producing wrong COGS and pricing review data.
        $oldestLayer = \Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('remaining_qty', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        \Modules\Inventory\Products\Domain\Models\Product::query()
            ->where('id', $productId)
            ->update(['current_fifo_cost' => $oldestLayer?->landed_unit_cost]);
    }
}
