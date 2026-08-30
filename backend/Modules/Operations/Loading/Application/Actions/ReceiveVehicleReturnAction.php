<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\CostManagement\Domain\Services\EnterpriseCostEngine;
use Modules\Inventory\InventoryItems\Application\Actions\AdjustmentInAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Operations\Loading\Domain\Enums\ReconciliationStatus;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliation;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliationLine;
use Modules\Operations\Loading\Domain\Services\VehicleInventoryService;
use Modules\Operations\Loading\Domain\Services\VehicleShiftReconciliationService;
use RuntimeException;

/**
 * TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001 — the Warehouse Return Receipt.
 *
 * The single authoritative inventory event for a vehicle return (§4). The Driver only
 * DECLARES; the Warehouse operator counts what physically came back and classifies it,
 * per the approved contract (§1):
 *
 *   expected_return = loaded − delivered            (canonical, from the reconciliation line)
 *   actual_received = accepted + damaged            (what physically returned)
 *   shortage        = expected_return − actual_received  (= variance; kept visible)
 *
 * On finalize, atomically (§8):
 *   1. records the canonical ABSOLUTE actual receipt + variance via the existing
 *      VehicleShiftReconciliationService::recordReturnedActual (no second authority — §3);
 *   2. stamps the accepted/damaged split + warehouse_receipt_at (idempotency marker — §7);
 *   3. reconciles the vehicle custody engine to the received total
 *      (VehicleInventoryService::reconcileReturn — absolute, §5);
 *   4. moves ACCEPTED good stock back to the warehouse via the canonical AdjustmentIn
 *      (+ a FIFO receipt layer so it is consumable), reference_type='vehicle_return' (§14);
 *   5. leaves DAMAGED out of good stock entirely (never AdjustmentIn'd — §12 condition-gate,
 *      identical to ReceiveReturnWorkflow);
 *   6. leaves SHORTAGE as the visible variance and holds the shift Disputed until it is
 *      classified (§5/§13/§15 — never silently forced to zero, never auto-charged);
 *   7. transitions the shift to Completed only when every line is received AND balanced.
 *
 * OUT OF SCOPE / DEFERRED (documented blockers — do NOT invent business rules here):
 *   • damaged → WasteInvestigation: that model is NOT-NULL FK-coupled to inventory count
 *     sessions and deducts (AdjustmentOut); a return has no count session. Damage is
 *     correctly kept out of good stock via the condition-gate; the waste DISPOSITION
 *     record is deferred pending schema relaxation / owner decision.
 *   • shortage → WarehouseLiability: the liability table has no driver/vehicle/trip
 *     attribution column and no create action; raising a driver-attributed liability is an
 *     owner decision. Shortage stays visible as the reconciliation variance.
 *   • order_lines.returned_qty: a customer-RMA concept written by
 *     ProjectReturnedQuantityFromCustomerReturn; an undelivered vehicle return is a
 *     different fact (vehicle custody), so this action does NOT write it (§16/§18 —
 *     no competing authority).
 *   • Order.status: never written here (FulfillmentEngine-guarded); the undelivered-stock
 *     order transition is undefined (no PartiallyReturned state) — §17.
 */
final class ReceiveVehicleReturnAction
{
    /** Float tolerance mirroring the loading/reconciliation guards. */
    private const EPSILON = 0.00005;

    public function __construct(
        private readonly VehicleShiftReconciliationService $reconciliation,
        private readonly VehicleInventoryService $custody,
        private readonly AdjustmentInAction $adjustmentIn,
    ) {}

    /**
     * @return VehicleShiftReconciliationLine the finalized line (refreshed)
     *
     * @throws RuntimeException on an invalid quantity, an over-receipt, an approved shift,
     *                          or a conflicting re-receipt.
     */
    public function execute(
        VehicleShiftReconciliationLine $line,
        float $quantityAccepted,
        float $quantityDamaged,
        ?string $damageReason,
        string $actorId,
    ): VehicleShiftReconciliationLine {
        if ($quantityAccepted < 0 || $quantityDamaged < 0) {
            throw new RuntimeException('Accepted and damaged quantities cannot be negative.');
        }

        $actualReceived = $quantityAccepted + $quantityDamaged;

        return DB::transaction(function () use ($line, $quantityAccepted, $quantityDamaged, $damageReason, $actualReceived, $actorId): VehicleShiftReconciliationLine {
            /** @var VehicleShiftReconciliationLine $locked */
            $locked = VehicleShiftReconciliationLine::query()->lockForUpdate()->findOrFail($line->id);

            /** @var VehicleShiftReconciliation $header */
            $header = VehicleShiftReconciliation::query()->lockForUpdate()->findOrFail($locked->reconciliation_id);

            if ($header->status === ReconciliationStatus::Approved) {
                throw new RuntimeException(
                    "Shift reconciliation '{$header->id}' is approved and can no longer be amended.",
                );
            }

            $expected = (float) $locked->quantity_returned_expected;

            // ── Idempotency (§7): a finalized receipt with the SAME split is a no-op;
            //    a different split is refused rather than silently double-processed.
            if ($locked->warehouse_receipt_at !== null) {
                $sameSplit = abs((float) $locked->quantity_accepted - $quantityAccepted) <= self::EPSILON
                    && abs((float) $locked->quantity_damaged - $quantityDamaged) <= self::EPSILON;

                if ($sameSplit) {
                    return $locked; // already received with the same values — no double posting
                }

                throw new RuntimeException(
                    "Reconciliation line '{$locked->id}' already has a warehouse receipt; ".
                    'reopen/correct it through the reconciliation workflow rather than re-receiving.',
                );
            }

            // ── Validation (§11): cannot receive more than was expected back.
            if ($actualReceived - $expected > self::EPSILON) {
                throw new RuntimeException(
                    "Actual received ({$actualReceived}) exceeds the expected return ({$expected}) for line '{$locked->id}'.",
                );
            }

            // 1. Canonical absolute actual-receipt + variance + header totals (no 2nd authority).
            $this->reconciliation->recordReturnedActual($locked, $actualReceived, $damageReason, $actorId);
            $locked->refresh();

            // 2. Classification split + idempotency marker.
            $locked->quantity_accepted = $quantityAccepted;
            $locked->quantity_damaged = $quantityDamaged;
            $locked->damage_reason = $damageReason;
            $locked->warehouse_receipt_at = now();
            $locked->updated_by = $actorId;
            $locked->save();

            // 3. Reconcile the vehicle custody engine to the received total (absolute).
            /** @var VehicleInventoryItem|null $custodyItem */
            $custodyItem = VehicleInventoryItem::query()->find($locked->vehicle_inventory_item_id);
            if ($custodyItem !== null) {
                $this->custody->reconcileReturn($custodyItem, $actualReceived, $locked->id, $actorId);
            }

            // 4. Move ACCEPTED good stock back to the warehouse via the canonical AdjustmentIn.
            if ($quantityAccepted > self::EPSILON) {
                $this->restockAccepted($locked, $header, $quantityAccepted, $actorId);
            }

            // 5. Damaged: intentionally NOT restocked (condition-gate). 6. Shortage: variance.

            // 7. Status: once every line has a warehouse receipt, close the shift —
            //    Completed if fully balanced, Disputed while any variance remains visible.
            $this->settleShiftIfFullyReceived($header, $actorId);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Restore accepted good stock to the shift's warehouse using the canonical inventory
     * movement, and open a FIFO receipt layer so the returned units are consumable —
     * exactly the pattern ReceiveReturnWorkflow uses for a sellable customer return (§14).
     * Cost is resolved from canonical inventory cost intelligence (never invented — §20).
     */
    private function restockAccepted(
        VehicleShiftReconciliationLine $line,
        VehicleShiftReconciliation $header,
        float $quantityAccepted,
        string $actorId,
    ): void {
        $warehouseId = LoadingSession::query()->whereKey($header->loading_session_id)->value('warehouse_id');

        if ($warehouseId === null) {
            throw new RuntimeException(
                "Loading session '{$header->loading_session_id}' has no warehouse; accepted returns cannot be restocked.",
            );
        }

        $product = Product::query()->find($line->product_id);
        $unitCost = $product !== null ? EnterpriseCostEngine::resolveUnitCost($product) : 0.0;

        $this->adjustmentIn->execute(new StockOperationDTO(
            warehouse_id: (string) $warehouseId,
            product_id: (string) $line->product_id,
            company_id: (string) $line->company_id,
            quantity: $quantityAccepted,
            reference_type: 'vehicle_return',
            reference_id: (string) $line->id,
            notes: "Accepted vehicle return for shift reconciliation {$header->id}.",
            unit_cost: $unitCost,
        ));

        // CERT-GAP-002 parity: without a FIFO layer the restored on-hand cannot be
        // allocated by future shipments. Mirror ReceiveReturnWorkflow exactly.
        InventoryReceiptLayer::create([
            'company_id' => $line->company_id,
            'supplier_id' => null,
            'product_id' => $line->product_id,
            'goods_receipt_id' => null,
            'goods_receipt_line_id' => null,
            'warehouse_id' => (string) $warehouseId,
            'received_qty' => $quantityAccepted,
            'remaining_qty' => $quantityAccepted,
            'landed_unit_cost' => $unitCost,
            'sale_price_snapshot' => $product !== null ? (((float) ($product->sale_price ?? 0)) ?: null) : null,
            'receipt_date' => now()->toDateString(),
        ]);
    }

    /**
     * Close the shift once every line has been received at the warehouse. A balanced
     * shift → Completed; any remaining variance → Disputed (kept visible, never forced
     * to zero — §5/§15). No transition is forced when a line is still unreceived.
     */
    private function settleShiftIfFullyReceived(VehicleShiftReconciliation $header, string $actorId): void
    {
        $lines = VehicleShiftReconciliationLine::query()
            ->where('reconciliation_id', $header->id)
            ->get();

        if ($lines->isEmpty()) {
            return;
        }

        $allReceived = $lines->every(
            fn (VehicleShiftReconciliationLine $l): bool => $l->warehouse_receipt_at !== null,
        );

        if (! $allReceived) {
            return;
        }

        $header->refresh();
        $target = $header->has_variance ? ReconciliationStatus::Disputed : ReconciliationStatus::Completed;

        if ($header->status === $target || ! $header->status->canTransitionTo($target)) {
            return;
        }

        $header->update([
            'status' => $target->value,
            'reconciled_by' => $actorId,
            'completed_at' => $target === ReconciliationStatus::Completed ? now() : null,
            'updated_by' => $actorId,
        ]);
    }
}
