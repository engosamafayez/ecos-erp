<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\Loading\Domain\Enums\MovementType;
use Modules\Operations\Loading\Domain\Enums\VehicleInventoryItemStatus;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryMovement;
use RuntimeException;

final class VehicleInventoryService
{
    /** Float tolerance mirroring the loading/delivery guards. */
    private const EPSILON = 0.00005;

    /**
     * Record product load — create/update VehicleInventoryItem + append movement.
     */
    public function recordLoad(
        VehicleAssignment $assignment,
        LoadingTask $task,
        float $quantity,
        string $actorId,
    ): VehicleInventoryItem {
        return DB::transaction(function () use ($assignment, $task, $quantity, $actorId): VehicleInventoryItem {
            $item = VehicleInventoryItem::firstOrNew([
                'vehicle_assignment_id' => $assignment->id,
                'product_id' => $task->product_id,
            ]);

            if (! $item->exists) {
                $item->fill([
                    'id' => (string) Str::uuid(),
                    'company_id' => $assignment->company_id,
                    'vehicle_id' => $assignment->vehicle_id,
                    'sku_snapshot' => $task->sku_snapshot,
                    'name_snapshot' => $task->name_snapshot,
                    'operational_date' => $task->loaded_at?->toDateString() ?? now()->toDateString(),
                    'pool_entry_id' => $task->pool_entry_id,
                    'loading_task_id' => $task->id,
                    'requires_refrigeration' => $task->requires_refrigeration,
                    'created_by' => $actorId,
                ]);
            }

            $item->quantity_loaded = ($item->quantity_loaded ?? 0.0) + $quantity;
            $item->quantity_on_hand = ($item->quantity_on_hand ?? 0.0) + $quantity;
            $item->quantity_unallocated = ($item->quantity_unallocated ?? 0.0) + $quantity;
            $item->status = VehicleInventoryItemStatus::Active->value;
            $item->last_movement_at = now();
            $item->updated_by = $actorId;
            $item->save();

            $this->appendMovement(
                item: $item,
                movementType: MovementType::Loaded->value,
                quantity: $quantity,
                referenceType: 'loading_task',
                referenceId: $task->id,
                actorId: $actorId,
                actorType: 'user',
            );

            return $item->fresh() ?? $item;
        });
    }

    /**
     * Correct a previously recorded load DOWNWARDS (the operator/driver overstated it).
     *
     * WHY THIS EXISTS (TASK-DRIVER-02). `LoadProductAction` is an idempotent absolute
     * SET, so revising 18 → 12 produces a delta of −6. That delta used to be passed
     * straight to {@see recordLoad()}, which appended a movement row with quantity −6 —
     * and `vehicle_inventory_movements` carries `CHECK (quantity > 0)`, so the whole
     * transaction rolled back. A downward correction was therefore impossible: custody
     * could be permanently overstated by a typo.
     *
     * THE LEDGER'S OWN CONVENTION is magnitude + typed direction: `quantity` is always a
     * positive MAGNITUDE and `movement_type` carries the meaning. Every other reduction
     * in this service already works that way — `allocated`, `unallocated`, `delivered`,
     * `returned` all subtract while writing a positive row. This method is the missing
     * member of that family and uses the movement type the schema already reserves for a
     * correction: `adjusted`, referenced back to the `loading_task` that was corrected so
     * the row stays traceable to its source. Nothing is mislabelled as a `loaded`
     * movement, no constraint is bypassed or disabled, and no second custody engine is
     * introduced. The ledger identity therefore stays decodable:
     * `quantity_loaded = Σ(loaded) − Σ(adjusted)`, since this is the only producer of
     * `adjusted` rows and it only ever subtracts.
     *
     * WAREHOUSE STOCK IS NOT TOUCHED. Loading does not deduct warehouse stock in this
     * architecture (deduction happens at dispatch), so un-loading must not credit it
     * either — inventing a warehouse movement here would fabricate stock that the
     * loading path never removed.
     */
    public function recordLoadCorrection(
        VehicleAssignment $assignment,
        LoadingTask $task,
        float $quantityRemoved,
        string $actorId,
    ): VehicleInventoryItem {
        return DB::transaction(function () use ($assignment, $task, $quantityRemoved, $actorId): VehicleInventoryItem {
            /** @var VehicleInventoryItem|null $item */
            $item = VehicleInventoryItem::query()
                ->where('vehicle_assignment_id', $assignment->id)
                ->where('product_id', $task->product_id)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                throw new RuntimeException(
                    'There is no vehicle custody record for this product, so the loaded quantity cannot be corrected.',
                );
            }

            $magnitude = abs($quantityRemoved);
            $loaded = (float) ($item->quantity_loaded ?? 0.0);
            $gone = (float) ($item->quantity_delivered ?? 0.0) + (float) ($item->quantity_returned ?? 0.0);
            $allocated = (float) ($item->quantity_allocated ?? 0.0);
            $corrected = $loaded - $magnitude;

            // Never allow custody to go negative, and never un-load product that has
            // already physically left the vehicle: that quantity is accounted for by a
            // delivery/return movement and cannot be retracted by a loading correction.
            if ($corrected < -self::EPSILON) {
                throw new RuntimeException(
                    "The correction would reduce the loaded quantity below zero (loaded {$loaded}, correction {$magnitude}).",
                );
            }

            if ($corrected - $gone < -self::EPSILON) {
                throw new RuntimeException(
                    "The loaded quantity cannot be corrected to {$corrected}: {$gone} has already been delivered or returned from this vehicle.",
                );
            }

            // …and never un-load product that is still on board but already PROMISED to
            // orders. `quantity_allocated` is an earmark that delivery ceilings against
            // (RecordProductDeliveryAction checks the AllocationRecord, never this item),
            // so silently correcting below it would leave `allocated > loaded`: the driver
            // could still deliver the full earmark, and the excess would be swallowed by
            // the `max(0, …)` clamps in recordDelivery()/shift reconciliation, erasing the
            // variance instead of reporting it. Refuse instead — releasing an allocation is
            // an allocation-engine decision, not something a loading correction may do
            // implicitly. (Before this correction path existed the state was unreachable,
            // because every downward correction rolled back at the movements CHECK.)
            if ($corrected - ($gone + $allocated) < -self::EPSILON) {
                throw new RuntimeException(
                    "The loaded quantity cannot be corrected to {$corrected}: {$allocated} is still allocated to orders on this vehicle. Release the allocation first.",
                );
            }

            $item->quantity_loaded = max(0.0, $corrected);
            // With the guard above, `unallocated - magnitude` == `corrected - allocated`,
            // which is >= `gone` >= 0 — so this clamp can no longer mask an impossible
            // correction; it is belt-and-braces for pre-existing rows only.
            $item->quantity_unallocated = max(0.0, (float) ($item->quantity_unallocated ?? 0.0) - $magnitude);
            $item->quantity_on_hand = max(0.0, (float) $item->quantity_loaded - $gone);
            $item->last_movement_at = now();
            $item->updated_by = $actorId;

            if ((float) $item->quantity_on_hand <= 0.0) {
                $item->status = VehicleInventoryItemStatus::Depleted->value;
            }

            $item->save();

            $this->appendMovement(
                item: $item,
                movementType: MovementType::Adjusted->value,
                quantity: $magnitude,
                referenceType: 'loading_task',
                referenceId: $task->id,
                actorId: $actorId,
                actorType: 'user',
                notes: 'Downward correction of the recorded loaded quantity.',
            );

            return $item->fresh() ?? $item;
        });
    }

    /**
     * Reserve quantity for an allocation (earmark — does not physically move product).
     */
    public function allocate(
        VehicleInventoryItem $item,
        string $allocationRecordId,
        float $quantity,
        string $actorId,
    ): VehicleInventoryItem {
        return DB::transaction(function () use ($item, $allocationRecordId, $quantity, $actorId): VehicleInventoryItem {
            $item->quantity_allocated = ($item->quantity_allocated ?? 0.0) + $quantity;
            $item->quantity_unallocated = max(0.0, ($item->quantity_unallocated ?? 0.0) - $quantity);
            $item->last_movement_at = now();
            $item->updated_by = $actorId;
            $item->save();

            $this->appendMovement(
                item: $item,
                movementType: MovementType::Allocated->value,
                quantity: $quantity,
                referenceType: 'order_allocation',
                referenceId: $allocationRecordId,
                actorId: $actorId,
                actorType: 'system',
            );

            return $item->fresh() ?? $item;
        });
    }

    /**
     * Release previously allocated quantity back to unallocated pool.
     */
    public function unallocate(
        VehicleInventoryItem $item,
        string $allocationRecordId,
        float $quantity,
        string $actorId,
    ): VehicleInventoryItem {
        return DB::transaction(function () use ($item, $allocationRecordId, $quantity, $actorId): VehicleInventoryItem {
            $item->quantity_allocated = max(0.0, ($item->quantity_allocated ?? 0.0) - $quantity);
            $item->quantity_unallocated = ($item->quantity_unallocated ?? 0.0) + $quantity;
            $item->last_movement_at = now();
            $item->updated_by = $actorId;
            $item->save();

            $this->appendMovement(
                item: $item,
                movementType: MovementType::Unallocated->value,
                quantity: $quantity,
                referenceType: 'order_allocation',
                referenceId: $allocationRecordId,
                actorId: $actorId,
                actorType: 'user',
            );

            return $item->fresh() ?? $item;
        });
    }

    /**
     * Record delivery of product to customer.
     */
    public function recordDelivery(
        VehicleInventoryItem $item,
        string $orderId,
        float $quantity,
        string $actorId,
        string $actorType = 'driver',
    ): VehicleInventoryItem {
        return DB::transaction(function () use ($item, $orderId, $quantity, $actorId, $actorType): VehicleInventoryItem {
            $item->quantity_delivered = ($item->quantity_delivered ?? 0.0) + $quantity;
            $item->quantity_on_hand = max(
                0.0,
                ($item->quantity_loaded ?? 0.0) - ($item->quantity_delivered ?? 0.0) - ($item->quantity_returned ?? 0.0),
            );
            $item->last_movement_at = now();
            $item->updated_by = $actorId;

            if ($item->quantity_on_hand <= 0) {
                $item->status = VehicleInventoryItemStatus::Depleted->value;
            }

            $item->save();

            $this->appendMovement(
                item: $item,
                movementType: MovementType::Delivered->value,
                quantity: $quantity,
                referenceType: 'order_allocation',
                referenceId: $orderId,
                actorId: $actorId,
                actorType: $actorType,
            );

            return $item->fresh() ?? $item;
        });
    }

    /**
     * Record product return to warehouse at end of shift.
     */
    public function recordReturn(
        VehicleInventoryItem $item,
        float $quantity,
        string $reconciliationLineId,
        string $actorId,
    ): VehicleInventoryItem {
        return DB::transaction(function () use ($item, $quantity, $reconciliationLineId, $actorId): VehicleInventoryItem {
            $item->quantity_returned = ($item->quantity_returned ?? 0.0) + $quantity;
            $item->quantity_on_hand = max(
                0.0,
                ($item->quantity_loaded ?? 0.0) - ($item->quantity_delivered ?? 0.0) - ($item->quantity_returned ?? 0.0),
            );
            $item->status = VehicleInventoryItemStatus::Returned->value;
            $item->last_movement_at = now();
            $item->updated_by = $actorId;
            $item->save();

            $this->appendMovement(
                item: $item,
                movementType: MovementType::Returned->value,
                quantity: $quantity,
                referenceType: 'reconciliation',
                referenceId: $reconciliationLineId,
                actorId: $actorId,
                actorType: 'user',
            );

            return $item->fresh() ?? $item;
        });
    }

    /**
     * Reconcile the vehicle's returned quantity for a product to an ABSOLUTE total —
     * the warehouse return receipt (TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001, §3/§5).
     *
     * Unlike {@see recordReturn()} (incremental `+=`, no meaningful caller), this SETS
     * quantity_returned to the total physically received back at the warehouse for this
     * custody item, so a repeated receipt is a no-op on the item state (idempotent — §7).
     * on_hand is recomputed as loaded − delivered − returned; a fully-received item
     * (on_hand ≤ 0) is Depleted, otherwise Returned (a positive on_hand is the visible
     * shortage the reconciliation keeps open — §5 "do not silently force to zero").
     *
     * Warehouse stock is posted separately by the receipt action via the canonical
     * AdjustmentIn — this method reconciles ONLY the vehicle custody engine, never
     * warehouse inventory (the Vehicle Warehouse is not a ledger location — §5).
     */
    public function reconcileReturn(
        VehicleInventoryItem $item,
        float $quantityReturnedTotal,
        string $reconciliationLineId,
        string $actorId,
    ): VehicleInventoryItem {
        if ($quantityReturnedTotal < 0) {
            throw new RuntimeException(
                "Cannot reconcile a negative returned quantity ({$quantityReturnedTotal}) for custody item '{$item->id}'.",
            );
        }

        return DB::transaction(function () use ($item, $quantityReturnedTotal, $reconciliationLineId, $actorId): VehicleInventoryItem {
            /** @var VehicleInventoryItem $locked */
            $locked = VehicleInventoryItem::query()->lockForUpdate()->findOrFail($item->id);

            $previous = (float) ($locked->quantity_returned ?? 0.0);
            $delta = $quantityReturnedTotal - $previous;

            $locked->quantity_returned = $quantityReturnedTotal;
            $locked->quantity_on_hand = max(
                0.0,
                (float) ($locked->quantity_loaded ?? 0.0) - (float) ($locked->quantity_delivered ?? 0.0) - $quantityReturnedTotal,
            );
            $locked->status = ((float) $locked->quantity_on_hand <= self::EPSILON)
                ? VehicleInventoryItemStatus::Depleted->value
                : VehicleInventoryItemStatus::Returned->value;
            $locked->last_movement_at = now();
            $locked->updated_by = $actorId;
            $locked->save();

            // Append a movement only for a real positive change — keeps the ledger
            // identity decodable and avoids a zero/duplicate row on an idempotent
            // re-receipt (the delta is 0 when the same total is reconciled again).
            if ($delta > self::EPSILON) {
                $this->appendMovement(
                    item: $locked,
                    movementType: MovementType::Returned->value,
                    quantity: $delta,
                    referenceType: 'reconciliation',
                    referenceId: $reconciliationLineId,
                    actorId: $actorId,
                    actorType: 'user',
                    notes: 'Warehouse return receipt (absolute custody reconcile).',
                );
            }

            return $locked->fresh() ?? $locked;
        });
    }

    private function appendMovement(
        VehicleInventoryItem $item,
        string $movementType,
        float $quantity,
        string $referenceType,
        string $referenceId,
        string $actorId,
        string $actorType,
        ?string $notes = null,
    ): void {
        VehicleInventoryMovement::create([
            'id' => Str::ulid()->toBase32(),
            'company_id' => $item->company_id,
            'vehicle_inventory_item_id' => $item->id,
            'vehicle_assignment_id' => $item->vehicle_assignment_id,
            'vehicle_id' => $item->vehicle_id,
            'product_id' => $item->product_id,
            'operational_date' => $item->operational_date,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'notes' => $notes,
            'recorded_at' => now(),
        ]);
    }
}
