<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Domain\Services;

use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Exceptions\InvoiceAnchorValidationException;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;

/**
 * V-5 — resolves and validates the receipt line a supplier invoice line settles.
 *
 * THE PROBLEM THIS EXISTS TO REMOVE. Clearing GRNI and computing Purchase Price Variance both
 * need the valuation the PHYSICAL RECEIPT committed to Inventory/FIFO. Without an anchor that
 * number can only be guessed. Every guess the approved contract forbids — supplier+product+date,
 * timestamp, FIFO order, nearest receipt — is absent here by construction: this class only ever
 * reads the anchor the caller stated, and refuses it if it does not agree.
 *
 * MODELLED ON THE CERTIFIED SUPPLIER RETURN ANCHOR (SR-1/SR-2), deliberately:
 *   - identity is a stated `goods_receipt_line_id`, never inferred;
 *   - the anchor is refused, never guessed, when absent;
 *   - the ceiling is `received − already consumed`, keyed on the anchor;
 *   - guards fail closed and are ordered so the most specific violation is reported.
 *
 * WHAT IT DOES NOT DO. It performs no allocation across receipts. One invoice line settles ONE
 * receipt line; a delivery split across two receipts is represented as two invoice lines, each
 * with its own anchor, so line-level cost differences survive instead of being averaged away.
 */
final class InvoiceReceiptAnchorService
{
    /**
     * The receipt valuation an invoice line settles: what the physical receipt actually put into
     * Inventory/FIFO for the invoiced quantity.
     *
     * Deliberately `landed_unit_cost` from the anchored receipt line — the very value
     * `PostGoodsReceiptAction` stamped when it posted the stock — and never today's product cost,
     * average cost, latest supplier price or a FIFO re-read. Those drift; this does not.
     */
    public function receiptValuation(GoodsReceiptLine $anchor, float $invoicedQty): float
    {
        return round($invoicedQty * (float) $anchor->landed_unit_cost, 4);
    }

    /** Quantity the receipt line actually delivered — net of any variance, per the certified accessor. */
    public function received(GoodsReceiptLine $anchor): float
    {
        return $anchor->effectiveReceivedQty();
    }

    /**
     * How much of this receipt line has already been settled by OTHER supplier invoices.
     *
     * Only invoices that reached a consuming state count: a draft invoice reserves nothing, so a
     * receipt line is not blocked by paperwork that may never post. `$excludeInvoiceId` lets the
     * invoice being validated exclude its own lines, so re-validating a saved invoice does not
     * count it against itself.
     */
    public function alreadyInvoiced(string $anchorId, ?string $excludeInvoiceId = null): float
    {
        return (float) SupplierInvoiceLine::query()
            ->join('supplier_invoices as si', 'si.id', '=', 'supplier_invoice_lines.supplier_invoice_id')
            ->where('supplier_invoice_lines.goods_receipt_line_id', $anchorId)
            ->whereIn('si.status', [SupplierInvoiceStatus::Posted->value])
            ->when($excludeInvoiceId !== null, fn ($q) => $q->where('si.id', '!=', $excludeInvoiceId))
            ->whereNull('si.deleted_at')
            ->sum('supplier_invoice_lines.quantity');
    }

    /** Remaining quantity of the anchored receipt that may still be invoiced. */
    public function invoiceable(GoodsReceiptLine $anchor, ?string $excludeInvoiceId = null): float
    {
        return round($this->received($anchor) - $this->alreadyInvoiced((string) $anchor->id, $excludeInvoiceId), 4);
    }

    /**
     * TASK-...-014 — the accepted/reconciled quantity for an invoice line, derived by summing
     * `net_received_quantity` over every POSTED Goods Receipt line that names this invoice line as
     * its origin (`goods_receipt_lines.supplier_invoice_line_id`) — never a stored counter, so a
     * follow-up partial receipt against the same line still aggregates correctly instead of
     * requiring anything here to be updated.
     *
     * Falls back to the invoice line's own declared `quantity` when no such receipt line exists —
     * i.e. a legacy, manually-anchored (pre-invoice-first) invoice line, where `quantity` already
     * IS the settled amount by convention (the user typed it after seeing what was received). This
     * keeps the fallback exactly as accurate as it always was for that flow; nothing changes for it.
     */
    public function reconciledQuantity(SupplierInvoiceLine $line): float
    {
        $sum = GoodsReceiptLine::query()
            ->join('goods_receipts as gr', 'gr.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->where('goods_receipt_lines.supplier_invoice_line_id', $line->id)
            ->where('gr.status', 'posted')
            ->sum('goods_receipt_lines.net_received_quantity');

        if ((float) $sum > 0.0 || $this->hasAnyInvoiceOriginatedReceiptLine($line)) {
            return round((float) $sum, 4);
        }

        return round((float) $line->quantity, 4);
    }

    /**
     * True when at least one Goods Receipt line (posted or not) names this invoice line as its
     * origin — i.e. this IS an invoice-first line, even if receiving has not posted anything yet
     * (reconciled = 0 is then the correct, honest answer, not a fallback to the invoiced quantity).
     */
    private function hasAnyInvoiceOriginatedReceiptLine(SupplierInvoiceLine $line): bool
    {
        return GoodsReceiptLine::query()
            ->where('supplier_invoice_line_id', $line->id)
            ->exists();
    }

    /**
     * Resolved the same way the certified inbound path resolves it: the PO's own company,
     * falling back to the receiving warehouse's.
     */
    private function anchorCompanyId(GoodsReceiptLine $anchor): string
    {
        $receipt = $anchor->goodsReceipt;

        return (string) ($receipt?->purchaseOrder?->company_id ?? $receipt?->warehouse?->company_id ?? '');
    }

    /**
     * D-1: the receipt's supplier — the legacy PO authority first, then the Purchase Material
     * authority (a Purchase-Material-anchored receipt carries no purchase order, so reading the
     * supplier from `purchaseOrder` alone would refuse every such line as a mismatch).
     */
    private function anchorSupplierId(GoodsReceiptLine $anchor): string
    {
        $receipt = $anchor->goodsReceipt;

        return (string) (
            $receipt?->purchaseOrder?->supplier_id
            ?? $anchor->purchaseMaterialLine?->supplier_id
            ?? ''
        );
    }

    /**
     * Resolve the anchor for one invoice line and prove it may be settled by this invoice.
     *
     * Guard order is intentional: company first, so a foreign row is reported as not-found and
     * never as a supplier or product mismatch — a cross-tenant caller learns nothing about the
     * other company's receipt, supplier, product or valuation.
     *
     * @throws InvoiceAnchorValidationException
     */
    public function resolve(SupplierInvoice $invoice, SupplierInvoiceLine $line, ?string $excludeInvoiceId = null): GoodsReceiptLine
    {
        $anchorId = $line->goods_receipt_line_id;

        // Refused, never guessed — the whole point of V-5.
        if ($anchorId === null || $anchorId === '') {
            throw InvoiceAnchorValidationException::missing((string) $line->id);
        }

        /** @var GoodsReceiptLine|null $anchor */
        $anchor = GoodsReceiptLine::query()
            ->with(['goodsReceipt.purchaseOrder', 'goodsReceipt.warehouse', 'purchaseMaterialLine'])
            ->find($anchorId);

        if ($anchor === null) {
            throw InvoiceAnchorValidationException::notFound($anchorId);
        }

        if ($anchor->goodsReceipt === null) {
            throw InvoiceAnchorValidationException::notFound($anchorId);
        }

        // ── Company ───────────────────────────────────────────────────────────
        // Reported as not-found so nothing about another tenant's document leaks through the error.
        $anchorCompany = $this->anchorCompanyId($anchor);
        $invoiceCompany = (string) ($invoice->company_id ?? $invoice->warehouse?->company_id ?? '');

        if ($anchorCompany === '' || $invoiceCompany === '' || $anchorCompany !== $invoiceCompany) {
            throw InvoiceAnchorValidationException::notFound($anchorId);
        }

        // ── Supplier ──────────────────────────────────────────────────────────
        $anchorSupplier = $this->anchorSupplierId($anchor);

        if ($anchorSupplier === '' || $anchorSupplier !== (string) $invoice->supplier_id) {
            throw InvoiceAnchorValidationException::supplierMismatch($anchorId);
        }

        // ── Product ───────────────────────────────────────────────────────────
        if ((string) $anchor->product_id !== (string) $line->product_id) {
            throw InvoiceAnchorValidationException::productMismatch($anchorId);
        }

        // ── Receiving must be posted (TASK-...-014) ──────────────────────────
        // The anchor's `landed_unit_cost` is only ever stamped by PostGoodsReceiptAction,
        // at the moment stock actually moves. An unposted receipt — including an
        // invoice-first receipt whose quantities were merely confirmed but not yet
        // posted — has moved nothing and stamped nothing, so clearing GRNI against it now
        // would value the settlement at zero. Refused, not defaulted.
        if ($anchor->goodsReceipt?->status !== GoodsReceiptStatus::Posted) {
            throw InvoiceAnchorValidationException::receiptNotYetPosted($anchorId);
        }

        // ── Quantity ──────────────────────────────────────────────────────────
        // An invoice may settle only what was physically received and not already settled. This
        // is the Supplier Return ceiling (SR-2) applied to the payable side; it is what stops the
        // same physical quantity being financially cleared twice.
        $qty = (float) $line->quantity;
        $available = $this->invoiceable($anchor, $excludeInvoiceId);

        if ($qty > $available + 0.0001) {
            throw InvoiceAnchorValidationException::quantityExceedsReceipt($anchorId, $qty, $available);
        }

        return $anchor;
    }

    /**
     * §9 (remediation-004) — list the Goods Receipt Lines a NEW or edited invoice line for this
     * supplier+product may legally anchor to, so the UI can offer only real, eligible choices
     * instead of asking the user to state an id blind. Reuses the exact identity guards {@see
     * resolve()} enforces (company via {@see anchorCompanyId()}, supplier via {@see
     * anchorSupplierId()}) plus the same {@see invoiceable()} ceiling — no parallel eligibility
     * rule is introduced. Read-only; never itself sets an anchor.
     *
     * @return list<array{id: string, receipt_number: string|null, po_number: string|null, receipt_date: string|null, ordered_quantity: float, available_quantity: float, unit_price: float, landed_unit_cost: float|null}>
     */
    public function eligibleFor(string $companyId, string $supplierId, string $productId, ?string $excludeInvoiceId = null): array
    {
        if ($companyId === '' || $supplierId === '') {
            return [];
        }

        return GoodsReceiptLine::query()
            ->where('product_id', $productId)
            ->with(['goodsReceipt.purchaseOrder', 'goodsReceipt.warehouse', 'purchaseMaterialLine'])
            ->get()
            ->filter(fn (GoodsReceiptLine $anchor): bool => $anchor->goodsReceipt !== null
                && $this->anchorCompanyId($anchor) === $companyId
                && $this->anchorSupplierId($anchor) === $supplierId)
            ->map(function (GoodsReceiptLine $anchor) use ($excludeInvoiceId): array {
                $receipt = $anchor->goodsReceipt;

                return [
                    'id' => (string) $anchor->id,
                    'receipt_number' => $receipt?->receipt_number,
                    'po_number' => $receipt?->purchaseOrder?->po_number,
                    'receipt_date' => $receipt?->receipt_date?->toDateString(),
                    'ordered_quantity' => (float) $anchor->ordered_quantity,
                    'available_quantity' => $this->invoiceable($anchor, $excludeInvoiceId),
                    'unit_price' => (float) $anchor->unit_price,
                    'landed_unit_cost' => $anchor->landed_unit_cost !== null ? (float) $anchor->landed_unit_cost : null,
                ];
            })
            ->filter(fn (array $row): bool => $row['available_quantity'] > 0.0001)
            ->values()
            ->all();
    }

    /**
     * Resolve every posting-relevant line of an invoice and return the financial basis.
     *
     * @return array{receiptValuation: float, invoiceNet: float, variance: float, lines: list<array{line_id: string, anchor_id: string, quantity: float, receipt_unit_cost: float, receipt_value: float, invoice_value: float, variance: float}>}
     */
    public function basisFor(SupplierInvoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'warehouse']);

        $receiptTotal = 0.0;
        $invoiceTotal = 0.0;
        $rows = [];

        foreach ($invoice->lines as $line) {
            if ((float) $line->quantity <= 0) {
                continue;
            }

            $anchor = $this->resolve($invoice, $line, (string) $invoice->id);

            // TASK-...-014 — the RECONCILED quantity settles the payable, not the originally
            // declared one: "Invoice must not financially settle 100 unless [the warehouse]
            // accepted 100." For a legacy, manually-anchored line this is exactly
            // `$line->quantity` (unchanged behaviour — see reconciledQuantity()'s own docblock).
            $qty = $this->reconciledQuantity($line);

            if ($qty <= 0) {
                continue;
            }

            $receiptValue = $this->receiptValuation($anchor, $qty);
            $invoiceValue = round($qty * (float) $line->unit_price, 4);

            $receiptTotal += $receiptValue;
            $invoiceTotal += $invoiceValue;

            $rows[] = [
                'line_id' => (string) $line->id,
                'anchor_id' => (string) $anchor->id,
                'quantity' => $qty,
                'receipt_unit_cost' => (float) $anchor->landed_unit_cost,
                'receipt_value' => $receiptValue,
                'invoice_value' => $invoiceValue,
                // Per line, never a single invoice-level average — line-level cost differences
                // must survive into the variance (PART 15/27).
                'variance' => round($invoiceValue - $receiptValue, 4),
            ];
        }

        return [
            'receiptValuation' => round($receiptTotal, 4),
            'invoiceNet' => round($invoiceTotal, 4),
            // > 0 unfavourable (invoice above receipt), < 0 favourable, 0 exact.
            'variance' => round($invoiceTotal - $receiptTotal, 4),
            'lines' => $rows,
        ];
    }
}
