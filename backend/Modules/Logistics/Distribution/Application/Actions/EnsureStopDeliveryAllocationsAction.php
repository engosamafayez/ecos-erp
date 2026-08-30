<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Actions;

use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Operations\Loading\Domain\Enums\AllocationRecordStatus;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;

/**
 * THE BRIDGE (TASK-DRIVER-DELIVERY-ALLOCATION-BRIDGE-001).
 *
 * Ensures the canonical `allocation_records` exist for a driver DeliveryStop's order lines,
 * so the driver can record delivery through the SAME canonical writer the operator uses
 * (`RecordProductDeliveryAction` → `allocation_records.quantity_delivered` → the
 * `order_lines.delivered_qty` projection → the vehicle-custody `delivered` movement). It is
 * the missing link between the driver Group/custody world and the allocation-quantity world.
 *
 * ┌─ WHY THIS IS NOT A SECOND ALLOCATION SYSTEM ──────────────────────────────┐
 * │ It reuses the canonical `AllocationRecord` model and produces exactly the  │
 * │ rows `AutoAllocationService` would in the no-shortage case:                │
 * │   quantity_allocated = order_line.quantity  (the real demand)              │
 * │ against the product's own vehicle-custody item. It invents NO split: it    │
 * │ only creates an allocation for a line whose product is actually in this    │
 * │ vehicle's custody; a product not on the vehicle simply gets no allocation  │
 * │ (and cannot be delivered here). Deciding how to PARTITION one product's    │
 * │ custody across competing lines under shortage is `AutoAllocationService`'s  │
 * │ priority job — which needs the wave provenance the driver Group flow never  │
 * │ carries — so it is deliberately NOT reproduced here.                        │
 * │                                                                            │
 * │ It does NOT earmark the loading-time pool (`quantity_unallocated`):         │
 * │ delivery is post-dispatch, uses live custody `quantity_on_hand` as the     │
 * │ availability guard (enforced by the caller), and the load-correction guard │
 * │ that reads the earmark can no longer run once the trip is on the road.     │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * Idempotent: the `(vehicle_assignment_id, order_line_id)` unique key means a re-run skips
 * lines that already have an allocation, and it is safe against a later `AutoAllocationService`
 * run over the same assignment (that engine skips lines that already have a record).
 * `quantity_loaded` is left 0 — it is irrelevant to delivery (RecordProductDeliveryAction reads
 * only `quantity_allocated`/`quantity_delivered`).
 */
final class EnsureStopDeliveryAllocationsAction
{
    /**
     * @return array<string, AllocationRecord> keyed by order_line_id (uuid string)
     */
    public function execute(DeliveryStop $stop, ?string $actorId): array
    {
        $actor = $actorId ?? 'system';

        // The Loading vehicle assignment carrying this trip's custody (canonical link:
        // vehicle_assignments.trip_id → distribution_trips.id).
        $assignment = VehicleAssignment::query()->where('trip_id', $stop->trip_id)->first();
        if ($assignment === null) {
            return [];
        }

        /** @var Order|null $order */
        $order = Order::query()->with('lines')->where('id', $stop->order_id)->first();
        if ($order === null) {
            return [];
        }

        $out = [];

        foreach ($order->lines as $line) {
            $demand = (float) $line->quantity;
            if ($demand <= 0) {
                continue;
            }

            $existing = AllocationRecord::query()
                ->where('vehicle_assignment_id', $assignment->id)
                ->where('order_line_id', $line->id)
                ->first();

            if ($existing !== null) {
                $out[(string) $line->id] = $existing;

                continue;
            }

            // Real basis: the product must physically be in THIS vehicle's custody. If it is
            // not, no allocation is created (the line cannot be delivered from this vehicle).
            $custody = VehicleInventoryItem::query()
                ->where('vehicle_assignment_id', $assignment->id)
                ->where('product_id', $line->product_id)
                ->first();

            if ($custody === null) {
                continue;
            }

            $out[(string) $line->id] = AllocationRecord::query()->create([
                'company_id' => $assignment->company_id,
                'vehicle_assignment_id' => $assignment->id,
                'loading_session_id' => $assignment->loading_session_id,
                'vehicle_id' => $assignment->vehicle_id,
                'order_id' => $order->id,
                'order_line_id' => $line->id,
                'order_number_snapshot' => (string) ($order->order_number ?? ''),
                'product_id' => $line->product_id,
                'sku_snapshot' => (string) ($custody->sku_snapshot ?? ''),
                'vehicle_inventory_item_id' => $custody->id,
                'allocation_mode' => 'full_auto',
                'priority_rank' => 1,
                'quantity_requested' => $demand,
                'quantity_allocated' => $demand,   // demand map — identical to AutoAllocationService when custody covers demand
                'quantity_loaded' => 0.0,          // irrelevant to delivery
                'quantity_delivered' => 0.0,
                'quantity_remaining' => $demand,
                'is_partial' => false,
                'status' => AllocationRecordStatus::Allocated->value,
                'allocated_at' => now(),
                'allocated_by' => 'driver',
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);
        }

        return $out;
    }
}
