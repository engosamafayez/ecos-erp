<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\GoodsReceipts\Application\Actions\CreateGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptLineDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;

/**
 * TASK-ECOS-PROCUREMENT-INVOICE-FIRST-RECEIVING-FLOW-014.
 *
 * Creating (or editing, before receiving starts) a Supplier Invoice automatically
 * creates/syncs ONE linked Goods Receipt work item — the invoice's own declared
 * quantities become the receipt's `ordered_quantity` (expected), with nothing yet
 * received (`net_received_quantity = 0`). Goods Receipt remains the only physical/
 * inventory authority: this service calls only the existing, unmodified
 * `CreateGoodsReceiptAction` — exactly the same action the PO- and
 * Purchase-Material-driven flows already use — and never itself touches inventory,
 * FIFO, or the stock ledger.
 *
 * IDEMPOTENT BY CONSTRUCTION. `SupplierInvoice.auto_receipt_id` (an existing column,
 * previously written by no production code — confirmed by inspection before reuse) is
 * the single source of truth for "has this invoice already spawned its receipt":
 *   - null  → create once, then stamp it. A retried/duplicate save never creates a
 *             second receipt because the very first write of this column closes that
 *             door for every call after it.
 *   - set   → re-sync the STILL-DRAFT-AND-UNTOUCHED lines only (§6/§18): once the
 *             linked receipt has posted, or ANY of its lines already carries a
 *             non-zero accepted quantity, this is a no-op — accepted physical receipt
 *             history is never silently rewritten.
 */
final class InvoiceReceivingLinkService
{
    public function __construct(
        private readonly CreateGoodsReceiptAction $createReceipt,
    ) {}

    public function sync(SupplierInvoice $invoice): void
    {
        $invoice->loadMissing(['lines', 'warehouse', 'autoReceipt.lines']);

        if ($invoice->auto_receipt_id === null) {
            $this->createLinkedReceipt($invoice);

            return;
        }

        $this->resyncLinkedReceipt($invoice);
    }

    private function createLinkedReceipt(SupplierInvoice $invoice): void
    {
        $lines = $invoice->lines->filter(fn (SupplierInvoiceLine $l): bool => (float) $l->quantity > 0);

        // Nothing to receive yet (e.g. a header-only draft with no lines saved yet) — sync()
        // will be called again on every subsequent save, so this simply waits for lines.
        if ($lines->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($invoice, $lines): void {
            $dto = new GoodsReceiptDTO(
                purchase_order_id: null,
                warehouse_id: (string) $invoice->warehouse_id,
                receipt_date: now()->toDateString(),
                notes: 'Auto-created from Supplier Invoice '.$invoice->invoice_number,
                lines: $lines->map(fn (SupplierInvoiceLine $l): GoodsReceiptLineDTO => new GoodsReceiptLineDTO(
                    purchase_order_line_id: null,
                    product_id: (string) $l->product_id,
                    ordered_quantity: (float) $l->quantity,
                    received_quantity: 0.0,
                    gross_received_quantity: 0.0,
                    net_received_quantity: 0.0,
                    unit_price: (float) $l->unit_price,
                    supplier_invoice_line_id: (string) $l->id,
                ))->values()->all(),
            );

            /** @var GoodsReceipt $receipt */
            $receipt = $this->createReceipt->execute($dto)->data();

            $invoice->forceFill(['auto_receipt_id' => $receipt->id])->save();

            // The V-5 anchor, populated automatically the instant the receipt exists — the
            // relationship is explicit from creation (§5), never left for someone to search
            // for and attach later (§3). One receipt line per invoice line, matched back by
            // the very column CreateGoodsReceiptAction just stamped.
            $receipt->loadMissing('lines');
            foreach ($receipt->lines as $receiptLine) {
                /** @var GoodsReceiptLine $receiptLine */
                SupplierInvoiceLine::query()
                    ->where('id', $receiptLine->supplier_invoice_line_id)
                    ->update(['goods_receipt_line_id' => $receiptLine->id]);
            }
        });
    }

    private function resyncLinkedReceipt(SupplierInvoice $invoice): void
    {
        /** @var GoodsReceipt|null $receipt */
        $receipt = $invoice->autoReceipt;

        if ($receipt === null) {
            // The stamped id no longer resolves (soft-deleted?) — do not fabricate a new
            // receipt silently; this is an operator-visible inconsistency, out of this
            // service's authority to repair.
            return;
        }

        if (! $this->isSafeToRewrite($receipt)) {
            return;
        }

        $lines = $invoice->lines->filter(fn (SupplierInvoiceLine $l): bool => (float) $l->quantity > 0);

        if ($lines->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($receipt, $lines): void {
            $receipt->lines()->delete();

            $created = $lines->map(function (SupplierInvoiceLine $l) use ($receipt): GoodsReceiptLine {
                return $receipt->lines()->create([
                    'purchase_order_line_id' => null,
                    'purchase_material_line_id' => null,
                    'supplier_invoice_line_id' => $l->id,
                    'product_id' => $l->product_id,
                    'ordered_quantity' => (float) $l->quantity,
                    'received_quantity' => 0.0,
                    'gross_received_quantity' => 0.0,
                    'net_received_quantity' => 0.0,
                    'variance_quantity' => -1 * (float) $l->quantity,
                    'unit_price' => (float) $l->unit_price,
                ]);
            });

            foreach ($created as $receiptLine) {
                SupplierInvoiceLine::query()
                    ->where('id', $receiptLine->supplier_invoice_line_id)
                    ->update(['goods_receipt_line_id' => $receiptLine->id]);
            }
        });
    }

    /**
     * §6/§18 — safe to fully re-sync a Draft receipt's lines ONLY while nothing physical has
     * happened to it yet: not posted, and no line already carries an accepted quantity. Once
     * either is true, editing the invoice's commercial lines no longer touches receiving —
     * accepted physical history is never rewritten, silently or otherwise.
     */
    private function isSafeToRewrite(GoodsReceipt $receipt): bool
    {
        if ($receipt->status === GoodsReceiptStatus::Posted) {
            return false;
        }

        foreach ($receipt->lines as $line) {
            if ($line->effectiveReceivedQty() > 0.0001) {
                return false;
            }
        }

        return true;
    }
}
