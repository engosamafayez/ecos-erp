<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\InventoryItems\Application\Actions\ReceiveStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Services\GoodsInwardAuthority;
use Modules\Inventory\InventoryItems\Domain\Services\InboundPostingGuard;
use Modules\Inventory\ReceiptLayers\Application\Actions\CreateReceiptLayersAction;
use Modules\Purchasing\GoodsReceipts\Domain\Contracts\GoodsReceiptRepositoryInterface;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\EmptyGoodsReceiptException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptAlreadyPostedException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptNotFoundException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\OverReceiptException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseMaterialReceivingException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseOrderCancelledException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseOrderClosedException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;
use Modules\Purchasing\PurchaseMaterials\Domain\Services\PurchaseMaterialReceivingService;
use Modules\Purchasing\PurchaseOrders\Domain\Enums\PurchaseOrderStatus;
use Modules\Purchasing\PurchaseOrders\Domain\Exceptions\InvalidPurchaseOrderStatusException;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;

/**
 * Posts a Goods Receipt, triggering inventory updates via ReceiveStockAction.
 *
 * Quantity used for inventory = net_received_quantity (falls back to received_quantity for legacy records).
 * Landed unit cost is computed per line: unit_price + (total_extra_landed_costs / total_net_qty).
 */
final class PostGoodsReceiptAction extends BaseAction
{
    public function __construct(
        private readonly GoodsReceiptRepositoryInterface $receipts,
        private readonly ReceiveStockAction $receiveStock,
        private readonly CreateReceiptLayersAction $createLayers,
        private readonly InboundPostingGuard $inboundGuard,
        private readonly GoodsInwardAuthority $inwardAuthority,
        // The ONE definition of Required / Received / Remaining for a Purchase line (RD-2/RD-3).
        private readonly PurchaseMaterialReceivingService $receivingQuantities,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $receipt = $this->receipts->findById($id);

        if ($receipt === null) {
            throw new GoodsReceiptNotFoundException($id);
        }

        // ── Guard 1: duplicate posting ────────────────────────────────────────
        if ($receipt->status === GoodsReceiptStatus::Posted) {
            throw new GoodsReceiptAlreadyPostedException($receipt->receipt_number);
        }

        // ── Guard 1b: this physical inbound is already in inventory ───────────
        //
        // P-7 goods-inward ownership ruling. Guard 1 only knows about THIS document; a
        // Mode 3 Supplier Invoice carrying `auto_receipt_id = this receipt` posts under
        // this receipt's ledger reference, so it can already have delivered the stock.
        // Posting again would double the quantity and create a second FIFO layer for one
        // physical delivery. Reuses the existing ledger reference — no new mechanism.
        if ($this->inboundGuard->alreadyPosted(InboundPostingGuard::REF_GOODS_RECEIPT, $receipt->id)) {
            throw new GoodsReceiptAlreadyPostedException($receipt->receipt_number);
        }

        // Eager-load all relationships used in this action to avoid N+1 and
        // ensure data is available before the transaction opens.
        $receipt->loadMissing(['purchaseOrder', 'lines', 'warehouse']);

        // ── Guard 2: PO must be in a receivable state ─────────────────────────
        //
        // Part 1: a Purchase-anchored receipt has NO purchase order, so these three gates
        // simply do not apply to it — they are document-workflow gates on the legacy PO, and
        // nothing in the inventory or accounting result depends on them. The equivalent
        // protection for the Purchase branch is the over-receipt ceiling in Guard 4, which is
        // evaluated against the Purchase line's own required quantity.
        $po = $receipt->purchaseOrder;

        if ($po !== null) {
            if ($po->status === PurchaseOrderStatus::Cancelled) {
                throw new PurchaseOrderCancelledException($po->po_number);
            }

            if ($po->status === PurchaseOrderStatus::Closed) {
                throw new PurchaseOrderClosedException($po->po_number);
            }

            if (! $po->status->canReceive()) {
                throw new InvalidPurchaseOrderStatusException(
                    $po->po_number,
                    $po->status->value,
                    [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value],
                );
            }
        }

        // ── Guard 3: receipt must have at least one non-zero net-quantity line ─
        $activeLines = $receipt->lines->filter(
            fn (GoodsReceiptLine $l): bool => $l->effectiveReceivedQty() > 0,
        );

        if ($activeLines->isEmpty()) {
            throw new EmptyGoodsReceiptException($receipt->receipt_number);
        }

        // company_id: the receipt's own stamped ownership first (set at creation from the PO
        // or, for a Purchase-anchored receipt, from the Purchase), then the PO, then the
        // receiving warehouse. Identical result for every legacy receipt.
        $companyId = $receipt->company_id ?? $po?->company_id ?? $receipt->warehouse?->company_id;

        // ── Guard 3b: is the Goods Receipt this company's goods-inward authority? ──
        //
        // G-1. Under ADR-011 Mode 3 the Supplier Invoice owns inventory instead, and the
        // receipt is a receiving record only. Guard 1b above cannot express this: it asks
        // whether THIS document already posted, which never catches the other document
        // unless the two share a ledger reference — and nothing in production populates the
        // link that would make them share one.
        //
        // Receiving bookkeeping below still runs: the goods physically arrived, so the PO's
        // received quantity and the receipt's own status are still advanced. Only the
        // INVENTORY effect belongs to the other document.
        $postsInventory = $this->inwardAuthority->receiptMayPost((string) $companyId);

        // ── Pre-compute landed cost distribution ──────────────────────────────
        $totalExtraCosts = $receipt->totalLandedCosts();
        $totalNetQty = $activeLines->sum(fn (GoodsReceiptLine $l): float => $l->effectiveReceivedQty());
        $extraPerUnit = $totalNetQty > 0 ? $totalExtraCosts / $totalNetQty : 0.0;

        // ── Snapshot on-hand qtys BEFORE inventory is updated ────────────────
        $productIds = $activeLines->pluck('product_id')->unique()->values()->all();
        $preReceiptQtys = InventoryItem::query()
            ->whereIn('product_id', $productIds)
            ->where('warehouse_id', $receipt->warehouse_id)
            ->pluck('on_hand_qty', 'product_id')
            ->map(fn ($qty): float => (float) $qty)
            ->all();

        DB::transaction(function () use ($receipt, $activeLines, $po, $companyId, $extraPerUnit, $preReceiptQtys, $postsInventory): void {

            // ── Guard 1c (locked): close the check-then-act window — D-INB-03 ──
            //
            // Guards 1 and 1b ran BEFORE this transaction opened and took no lock, so two
            // concurrent requests could both observe an unposted receipt, both enter here and
            // both mutate: two ledger rows, two FIFO layers, double received_qty. The
            // over-receipt guard below does not catch it — a second post only exceeds the PO
            // when the receipt happens to consume more than half the ordered quantity.
            //
            // The receipt row IS the canonical authority for its own posting, so it is what
            // gets locked: the loser blocks on the row until the winner commits, then reads
            // the committed status and stands down. Same convention as the certified
            // ApproveSupplierReturnAction — a lockForUpdate re-read inside the transaction,
            // re-asserting the guard that was evaluated outside it. No new mechanism, no new
            // lock table, no application-level lock.
            //
            // Re-read through the model (tenant global scope intact), so a foreign-company
            // row stays invisible here exactly as it was to the repository lookup above.
            $locked = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new GoodsReceiptNotFoundException((string) $receipt->id);
            }

            if ($locked->status === GoodsReceiptStatus::Posted) {
                throw new GoodsReceiptAlreadyPostedException($receipt->receipt_number);
            }

            // Re-assert the cross-document ledger guard under the same lock: a Mode 3
            // Supplier Invoice carrying this receipt's reference may have delivered the stock
            // between the pre-transaction read and this point.
            if ($this->inboundGuard->alreadyPosted(InboundPostingGuard::REF_GOODS_RECEIPT, $receipt->id)) {
                throw new GoodsReceiptAlreadyPostedException($receipt->receipt_number);
            }

            // ── Guard 4 (locked): over-receipt check ──────────────────────────
            //
            // Both branches enforce the same rule — cumulative received may never exceed the
            // ordered quantity — against their own ordering document. The PO branch compares
            // to `purchase_order_lines.quantity` using its stored counter; the Purchase branch
            // compares to Required = COALESCE(agreed_qty, requested_qty) (RD-2) using the
            // DERIVED gross received (RD-3), so it cannot drift. Both take a row lock first,
            // closing the same check-then-act window Guard 1c closes for the receipt itself.
            foreach ($activeLines as $line) {
                /** @var GoodsReceiptLine $line */
                $netQty = $line->effectiveReceivedQty();

                if ($line->purchase_material_line_id !== null) {
                    $pmLine = PurchaseMaterialLine::query()
                        ->lockForUpdate()
                        ->find($line->purchase_material_line_id);

                    if ($pmLine === null) {
                        throw PurchaseMaterialReceivingException::lineNotFound((string) $line->purchase_material_line_id);
                    }

                    $required = $this->receivingQuantities->requiredQty($pmLine);
                    $alreadyReceived = $this->receivingQuantities->receivedGross((string) $pmLine->id);

                    if (round($alreadyReceived + $netQty, 4) > round($required, 4)) {
                        throw PurchaseMaterialReceivingException::overReceipt(
                            (string) $pmLine->id,
                            $required,
                            $alreadyReceived,
                            $netQty,
                        );
                    }

                    continue;
                }

                $poLine = PurchaseOrderLine::query()
                    ->lockForUpdate()
                    ->findOrFail($line->purchase_order_line_id);

                $newTotal = (float) $poLine->received_qty + $netQty;

                if ($newTotal > (float) $poLine->quantity) {
                    throw new OverReceiptException(
                        $po?->po_number ?? '',
                        (float) $poLine->quantity,
                        (float) $poLine->received_qty,
                        $netQty,
                    );
                }
            }

            // ── Step 1: inventory update + landed cost stamp per line ─────────
            foreach ($activeLines as $line) {
                /** @var GoodsReceiptLine $line */
                $netQty = $line->effectiveReceivedQty();
                $landedUnitCost = round((float) $line->unit_price + $extraPerUnit, 4);

                if ($postsInventory) {
                    $this->receiveStock->execute(
                        StockOperationDTO::fromArray([
                            'warehouse_id' => $receipt->warehouse_id,
                            'product_id' => $line->product_id,
                            'company_id' => $companyId,
                            'quantity' => $netQty,
                            'reference_type' => 'goods_receipt',
                            'reference_id' => $receipt->id,
                            'notes' => "GR {$receipt->receipt_number}",
                            // Hand the landed cost down so the resulting financial
                            // event is valued at the price actually paid, not at a
                            // running average of older stock.
                            'unit_cost' => $landedUnitCost,
                        ]),
                    );
                }

                GoodsReceiptLine::query()
                    ->where('id', $line->id)
                    ->update(['landed_unit_cost' => $landedUnitCost]);

                // ── Step 2: cumulative received qty on PO line ────────────────
                //
                // PO-anchored lines only. The Purchase branch deliberately keeps NO stored
                // counter: received is derived from these very receipt lines, so there is
                // nothing to increment, nothing to drift, and nothing that a later return
                // would silently invalidate.
                if ($line->purchase_order_line_id !== null) {
                    PurchaseOrderLine::query()
                        ->where('id', $line->purchase_order_line_id)
                        ->increment('received_qty', $netQty);
                }
            }

            // ── Step 3: advance PO status ─────────────────────────────────────
            // Only when this receipt actually belongs to a purchase order. Advancing the
            // PURCHASE's own lifecycle on receipt is Part 2 and is deliberately not done here.
            if ($po !== null) {
                $poLines = PurchaseOrderLine::query()
                    ->where('purchase_order_id', $po->id)
                    ->get();

                $allFullyReceived = $poLines->every(
                    fn (PurchaseOrderLine $l): bool => (float) $l->received_qty >= (float) $l->quantity,
                );

                $po->update([
                    'status' => $allFullyReceived
                        ? PurchaseOrderStatus::Received->value
                        : PurchaseOrderStatus::PartiallyReceived->value,
                ]);
            }

            // ── Step 4: stamp the receipt as Posted ───────────────────────────
            $receipt->update([
                'status' => GoodsReceiptStatus::Posted->value,
                'posted_at' => now(),
            ]);

            // ── Step 5: create receipt layers + update product cost intel ─────
            //
            // Skipped when the Supplier Invoice is this company's inbound authority: the
            // layer belongs to whichever document actually moved the stock, and creating
            // one here would be the second layer for a single physical delivery.
            if ($postsInventory) {
                $receipt->refresh(); // reload with updated landed_unit_cost on lines
                $this->createLayers->execute($receipt, $preReceiptQtys);
            }
        });

        return OperationResult::success(
            $this->receipts->findById($id),
            $postsInventory
                ? 'Goods receipt posted. Inventory updated.'
                : 'Goods receipt posted. Inventory is owned by the Supplier Invoice for this company.',
        );
    }
}
