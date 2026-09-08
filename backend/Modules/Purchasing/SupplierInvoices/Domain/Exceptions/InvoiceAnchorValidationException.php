<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Domain\Exceptions;

use RuntimeException;

/**
 * A supplier invoice line's receipt anchor is missing or does not agree with the invoice.
 *
 * Messages are deliberately terse about the anchor. A cross-tenant caller must learn only that
 * the receipt line is not available to them — never the other company's supplier, product,
 * quantity or valuation — so the company guard reports {@see notFound()} rather than a specific
 * mismatch.
 */
final class InvoiceAnchorValidationException extends RuntimeException
{
    public static function missing(string $lineId): self
    {
        return new self(
            "Supplier invoice line {$lineId} has no goods receipt anchor. A posting-ready line must "
            .'state the receipt line it settles; it is never inferred.',
        );
    }

    public static function notFound(string $anchorId): self
    {
        return new self("Goods receipt line {$anchorId} was not found or is not available to this invoice.");
    }

    public static function supplierMismatch(string $anchorId): self
    {
        return new self("Goods receipt line {$anchorId} belongs to a different supplier than this invoice.");
    }

    public static function productMismatch(string $anchorId): self
    {
        return new self("Goods receipt line {$anchorId} is for a different product than this invoice line.");
    }

    public static function quantityExceedsReceipt(string $anchorId, float $requested, float $available): self
    {
        return new self(
            "Invoiced quantity {$requested} exceeds the quantity still invoiceable against goods receipt "
            ."line {$anchorId} ({$available}). A physical receipt cannot be financially cleared twice.",
        );
    }

    /**
     * TASK-...-014 — the anchor exists (an invoice-first invoice always auto-creates one) but its
     * Goods Receipt has not yet been posted, so it has not physically moved anything into
     * Inventory/FIFO and stamped no `landed_unit_cost`. Posting the invoice now would clear GRNI
     * at a valuation of zero. Refused until receiving reconciles, not guessed or defaulted.
     */
    public static function receiptNotYetPosted(string $anchorId): self
    {
        return new self(
            "Goods receipt line {$anchorId} has not been received and posted yet — receiving must "
            .'reconcile before this invoice can be validated or posted.',
        );
    }
}
