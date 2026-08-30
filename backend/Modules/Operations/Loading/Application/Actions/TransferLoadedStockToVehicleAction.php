<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Application\Actions;

use Modules\Inventory\InventoryItems\Application\Actions\ShipStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InvalidInventoryMovementException;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Operations\Loading\Domain\Models\LoadingTask;

/**
 * TASK-DRIVER-CUSTODY-INVENTORY-TRANSFER-001 — the warehouse→vehicle custody bridge.
 *
 * ┌─ THE GAP THIS CLOSES ────────────────────────────────────────────────────┐
 * │ Loading records the loaded quantity into the vehicle custody engine        │
 * │ (VehicleInventoryService::recordLoad) but NEVER deducted it from warehouse │
 * │ stock — deduction was deferred to a dispatch path the driver/Group flow     │
 * │ never reaches. The canonical Stock Ledger therefore held no record of the   │
 * │ goods leaving, so the warehouse still counted product physically on a truck.│
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * This is the WAREHOUSE-SIDE half of the transfer, and nothing more. When the driver
 * confirms receipt of a loaded product, this issues the confirmed loaded quantity out
 * of the source warehouse through the CANONICAL ShipStockAction: on_hand and reserved
 * both fall, the movement is written to stock_ledger_entries, and a product that
 * permits negative stock may overdraft (ShipStock now honours allow_negative_stock,
 * so a later goods receipt offsets the negative). The VEHICLE side is unchanged — it
 * is still the vehicle_inventory_items custody the load already credited. A vehicle can
 * never be a ledger location (stock_ledger_entries.warehouse_id is a NOT NULL FK to
 * warehouses), so "the two reconcile" means the warehouse decrement equals the custody
 * credit; it is deliberately NOT a second ledger row against the vehicle.
 *
 * ATOMIC. Callers MUST invoke this inside the driver-confirmation transaction so a
 * ledger failure (e.g. insufficient stock with allow_negative_stock=false) rolls the
 * confirmation back: the receipt is only "confirmed" once the stock has actually moved.
 *
 * IDEMPOTENT via the ledger itself — no new schema. The transfer is keyed on
 * (reference_type=vehicle_custody_transfer, reference_id=loading_task_id); once a row
 * exists, a repeated Confirm Received moves nothing.
 *
 * SCOPE. Order-level bookkeeping (reservation_status, inventory_shipped_at, COGS and the
 * Group→order-line split) is DEFERRED to a separate task and is deliberately untouched
 * here, as is re-bridging a warehouse quantity revised AFTER the transfer.
 */
final class TransferLoadedStockToVehicleAction
{
    /** Ledger reference_type that marks — and idempotency-keys — a custody transfer. */
    public const REFERENCE_TYPE = 'vehicle_custody_transfer';

    /** Mirrors the loading/custody float tolerance (decimal(18,4)). */
    private const EPSILON = 0.00005;

    public function __construct(
        private readonly ShipStockAction $shipStock,
    ) {}

    /**
     * Move ONE loading task's confirmed loaded quantity from its source warehouse into
     * the driver's vehicle custody, via the canonical Stock Ledger.
     */
    public function execute(LoadingTask $task): void
    {
        $quantity = (float) $task->quantity_loaded;

        // Nothing loaded ⇒ no custody was handed over ⇒ nothing to move.
        if ($quantity <= self::EPSILON) {
            return;
        }

        // Idempotency (requirement 7): the ledger is the record of truth. If this task's
        // custody transfer is already posted, a repeated confirmation must not deduct
        // the warehouse a second time. Safe under the task row lock the confirmation
        // holds, so no concurrent transfer for the same task can slip between here and
        // the write below.
        $alreadyTransferred = StockLedgerEntry::query()
            ->where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_id', $task->id)
            ->exists();

        if ($alreadyTransferred) {
            return;
        }

        $session = $task->loadingSession;

        if ($session === null || $session->warehouse_id === null) {
            // Every loaded task belongs to a session with a source warehouse. If that
            // link is missing the movement cannot be represented canonically, so refuse
            // rather than guess a location — surfaced as 422, rolling the confirm back.
            throw new InvalidInventoryMovementException(
                "loading task {$task->id} has no source warehouse to transfer from",
            );
        }

        // Canonical warehouse outbound movement. ShipStockAction consumes the reservation
        // (on_hand + reserved down), honours allow_negative_stock, and writes the
        // stock_ledger_entries row. The reference identifies this task's custody transfer
        // and is also the idempotency key checked above. reference_id=loading_task_id
        // links the movement to the full loading/vehicle/trip/driver context by join.
        $this->shipStock->execute(new StockOperationDTO(
            warehouse_id: (string) $session->warehouse_id,
            product_id: (string) $task->product_id,
            company_id: (string) $task->company_id,
            quantity: $quantity,
            reference_type: self::REFERENCE_TYPE,
            reference_id: (string) $task->id,
            notes: "Warehouse→vehicle custody transfer for loading task {$task->id} "
                ."(vehicle assignment {$task->vehicle_assignment_id}).",
        ));
    }
}
