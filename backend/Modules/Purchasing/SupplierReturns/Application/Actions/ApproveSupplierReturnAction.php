<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierReturns\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\InventoryItems\Application\Actions\AdjustmentOutAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\ReceiptLayers\Application\Services\InventoryLayerConsumptionService;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\SupplierReturns\Domain\Enums\SupplierReturnStatus;
use Modules\Purchasing\SupplierReturns\Domain\Exceptions\SupplierReturnValidationException;
use Modules\Purchasing\SupplierReturns\Domain\Models\SupplierReturn;
use Modules\Purchasing\SupplierReturns\Domain\Services\ReturnableQuantityService;

/**
 * The single atomic Supplier Return approval — SR-1, SR-2, SR-3.
 *
 * THE WHOLE OPERATION IS ONE TRANSACTION:
 *
 *     validate returnable quantity
 *          ↓
 *     consume the correct FIFO layer(s)   (receipt-scoped — SR-1)
 *          ↓
 *     reduce inventory + write the stock ledger
 *          ↓
 *     mark the return Approved
 *          ↓
 *     commit
 *
 * Previously the controller set the status to Approved and THEN called inventory reversal as
 * a separate step, so a failure downstream left a return marked Approved with no stock
 * movement. Status is now the last write inside the same transaction as the mutations, so a
 * failure anywhere rolls the whole thing back — the D-6 defect.
 *
 * SR-3: there is no AP module in V1, so this performs NO payable mutation. `credit_amount`,
 * `credit_method` and `debit_note_number` remain plain return data and are untouched here.
 *
 * Nothing new is invented: quantity + ledger go through `AdjustmentOutAction` and FIFO
 * through `InventoryLayerConsumptionService`, the same canonical actions the rest of the
 * platform uses. This is not a second inventory path.
 */
final class ApproveSupplierReturnAction
{
    public function __construct(
        private readonly AdjustmentOutAction $adjustmentOut,
        private readonly InventoryLayerConsumptionService $layerConsumption,
        private readonly InventoryItemRepositoryInterface $inventory,
        private readonly ReturnableQuantityService $returnable,
    ) {}

    public function execute(SupplierReturn $return, ?string $approvedBy = null): SupplierReturn
    {
        // Idempotency FIRST, deliberately before the transition guard.
        //
        // Reusing the existing `inventory_restocked` marker (not a new mechanism). A retry
        // of an already-approved return is a NO-OP, not an error: by the time it runs the
        // status is Approved, and `canTransitionTo(Approved)` is false from there — so
        // checking the transition first would turn a harmless duplicate request into a
        // failure and tell the caller nothing about whether the stock had moved.
        if ($return->inventory_restocked) {
            return $return;
        }

        if (! $return->status->canTransitionTo(SupplierReturnStatus::Approved)) {
            throw new SupplierReturnValidationException(
                "Return {$return->return_number} cannot be approved from status {$return->status->value}.",
            );
        }

        $return->loadMissing(['lines', 'warehouse']);

        $companyId = (string) ($return->company_id ?? $return->warehouse?->company_id ?? '');
        $warehouseId = (string) $return->warehouse_id;

        if ($companyId === '') {
            throw new SupplierReturnValidationException(
                "Return {$return->return_number} has no resolvable company — refusing to mutate inventory.",
            );
        }

        return DB::transaction(function () use ($return, $companyId, $warehouseId, $approvedBy): SupplierReturn {
            // The guard above is a cheap fast path on a possibly-stale in-memory model. It is
            // read BEFORE the transaction opens and holds no lock, so two approvals of the
            // same return arriving together — a double-clicked button, or a client retry on a
            // slow response — both saw `inventory_restocked = false` and both posted. The FIFO
            // layer lock serialised them but did not stop the second: it re-read the reduced
            // remaining quantity and consumed again, so one 4-unit return removed 8 units and
            // wrote two ledger entries.
            //
            // This re-read is the authoritative one. Locking the return row serialises rival
            // approvals here, and the loser observes the winner's committed flag instead of
            // its own stale copy.
            $locked = SupplierReturn::query()->whereKey($return->getKey())->lockForUpdate()->first();

            if ($locked !== null && $locked->inventory_restocked) {
                return $locked;
            }

            $totalValue = 0.0;

            // Quantity this approval has itself already claimed, per receipt line.
            //
            // `returnable()` deliberately EXCLUDES the return being approved, so it cannot see
            // the lines processed moments ago in this very loop. Two lines of one return
            // anchored to the same receipt line therefore each measured themselves against the
            // full untouched allowance: 60 + 60 against a 100-unit receipt both passed, and
            // the over-return was caught only downstream by the FIFO layer running dry — a
            // different invariant, reporting "insufficient stock" for what is an invalid
            // document. Accumulating here keeps the ceiling the thing that enforces the
            // ceiling.
            //
            // @var array<string, float> $claimedHere
            $claimedHere = [];

            foreach ($return->lines as $line) {
                $qty = (float) $line->return_quantity;

                if ($qty <= 0) {
                    continue;
                }

                // ── Validation, BEFORE any mutation ──────────────────────────────
                $receiptLine = $this->resolveReceiptLine($return, $line->goods_receipt_line_id, $companyId);

                if ((string) $receiptLine->product_id !== (string) $line->product_id) {
                    throw new SupplierReturnValidationException(
                        "Return line product does not match goods receipt line {$receiptLine->id}.",
                    );
                }

                $receiptLineKey = (string) $receiptLine->id;

                $remaining = round(
                    $this->returnable->returnable($receiptLine, $companyId, $return->id)
                        - ($claimedHere[$receiptLineKey] ?? 0.0),
                    4,
                );

                if ($qty > $remaining) {
                    throw new SupplierReturnValidationException(sprintf(
                        'Return quantity %.4f exceeds the remaining returnable quantity %.4f for goods receipt line %s.',
                        $qty,
                        $remaining,
                        $receiptLine->id,
                    ));
                }

                $claimedHere[$receiptLineKey] = ($claimedHere[$receiptLineKey] ?? 0.0) + $qty;

                // ── FIFO consumption, receipt-scoped (SR-1) ──────────────────────
                //
                // Runs BEFORE the quantity reduction so that an ineligible or insufficient
                // layer set aborts the transaction while inventory is still untouched.
                $item = $this->inventory->findByWarehouseAndProduct($warehouseId, (string) $line->product_id);

                if ($item === null) {
                    throw new SupplierReturnValidationException(
                        "No inventory record for product {$line->product_id} in warehouse {$warehouseId}.",
                    );
                }

                $consumption = $this->layerConsumption->consume(
                    inventoryItemId: $item->id,
                    productId: (string) $line->product_id,
                    warehouseId: $warehouseId,
                    companyId: $companyId,
                    quantity: $qty,
                    goodsReceiptLineId: (string) $receiptLine->id,
                );

                // ── Quantity + stock ledger, through the canonical action ────────
                $this->adjustmentOut->execute(new StockOperationDTO(
                    warehouse_id: $warehouseId,
                    product_id: (string) $line->product_id,
                    company_id: $companyId,
                    quantity: $qty,
                    reference_type: 'supplier_return',
                    reference_id: (string) $return->id,
                    notes: "Supplier return {$return->return_number}",
                ));

                // The returned value is the ACTUAL consumed layer cost — never
                // material_cost, never the latest supplier price.
                $line->unit_cost = $consumption->weightedCost;
                $line->total_cost = round($consumption->weightedCost * $qty, 4);
                $line->save();

                $totalValue += (float) $line->total_cost;
            }

            // ── Status last, inside the same transaction ─────────────────────────
            $return->status = SupplierReturnStatus::Approved;
            $return->approved_by = $approvedBy;
            $return->approved_at = now();
            $return->inventory_restocked = true;
            $return->inventory_restocked_at = now();
            $return->total_return_value = round($totalValue, 4);
            $return->save();

            return $return->refresh();
        });
    }

    /**
     * The receipt line this return line is anchored to — validated, never guessed.
     *
     * SR-2 makes `goods_receipt_line_id` the canonical identity, so a return line without one
     * cannot be approved: there is no basis on which to compute a ceiling, and inventing one
     * is explicitly forbidden. Historical rows lacking the link are therefore refused here
     * rather than fabricated.
     *
     * Company is checked through the receipt's own warehouse, so a Company A return can never
     * resolve a Company B receipt line. Fails closed.
     */
    private function resolveReceiptLine(SupplierReturn $return, ?string $receiptLineId, string $companyId): GoodsReceiptLine
    {
        if ($receiptLineId === null || $receiptLineId === '') {
            throw new SupplierReturnValidationException(
                "Return {$return->return_number} has a line with no goods_receipt_line_id. ".
                'The returnable quantity cannot be established and will not be guessed.',
            );
        }

        $line = GoodsReceiptLine::query()
            ->with(['goodsReceipt.warehouse', 'goodsReceipt.purchaseOrder', 'purchaseMaterialLine'])
            ->find($receiptLineId);

        if ($line === null) {
            throw new SupplierReturnValidationException("Goods receipt line {$receiptLineId} does not exist.");
        }

        $lineCompanyId = (string) ($line->goodsReceipt?->warehouse?->company_id ?? '');

        if ($lineCompanyId !== $companyId) {
            throw new SupplierReturnValidationException(
                "Goods receipt line {$receiptLineId} belongs to another company.",
            );
        }

        // SR-1 is a statement about SUPPLIERS: "a return to Supplier A must never consume a
        // FIFO layer belonging to Supplier B". Anchoring consumption to the receipt line
        // delivers that ONLY IF the anchor really is a line this supplier delivered — and
        // nothing else establishes that. `supplier_id` on the return is client-supplied and
        // was previously never compared with the anchor, so a document addressed to Supplier
        // A could name Supplier B's receipt line, consume B's layer at B's cost, and burn
        // down B's SR-2 allowance. Both identities are NOT NULL, so this fails closed.
        //
        // Checked AFTER the company guard so a cross-company anchor still reports as such.
        //
        // D-1: resolved from the legacy purchase order first, then from the Purchase Material
        // line. A PM-anchored receipt has no purchase order, so reading only `purchaseOrder`
        // yielded '' and refused every return against one. The PM line is the certified supplier
        // authority for those receipts (RD-1). SR-1 is unchanged and still enforced: the anchor
        // must genuinely belong to this return's supplier, and an anchor with no resolvable
        // supplier still yields '' and is still refused.
        $lineSupplierId = (string) (
            $line->goodsReceipt?->purchaseOrder?->supplier_id
            ?? $line->purchaseMaterialLine?->supplier_id
            ?? ''
        );

        if ($lineSupplierId === '' || $lineSupplierId !== (string) $return->supplier_id) {
            throw new SupplierReturnValidationException(
                "Goods receipt line {$receiptLineId} was not supplied by supplier {$return->supplier_id}.",
            );
        }

        return $line;
    }
}
