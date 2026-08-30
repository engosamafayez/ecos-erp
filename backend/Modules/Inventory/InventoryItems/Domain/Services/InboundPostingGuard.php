<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Domain\Services;

use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;

/**
 * "Has this physical inbound already been posted to inventory?"
 *
 * THE CONTRACT (P-7 goods-inward ownership ruling): a Supplier Invoice under ADR-011 Mode 3
 * and a Goods Receipt are both valid inbound SOURCE DOCUMENTS, but the physical receipt they
 * describe must reach inventory EXACTLY ONCE.
 *
 * Before this guard the two paths were blind to each other: a Goods Receipt and its linked
 * Supplier Invoice (`supplier_invoices.auto_receipt_id`, a real FK) could each post the same
 * delivery, producing double stock, two FIFO layers and a split audit trail.
 *
 * NO NEW MECHANISM IS INTRODUCED. `stock_ledger_entries` already records
 * `reference_type` + `reference_id` for every movement — `ReceiveStockAction` writes them —
 * so the ledger already knows whether an inbound has been posted. This class only asks it.
 *
 * THE KEY IDEA IS THE SHARED REFERENCE. An invoice that carries `auto_receipt_id` posts
 * under the RECEIPT's reference, not its own, because it is describing that receipt's
 * delivery. Both documents therefore resolve to the same ledger key, and whichever posts
 * first wins; the second finds the entry and stands down. An invoice with no linked receipt
 * is a genuine Mode 3 inbound in its own right and posts under its own reference.
 */
final class InboundPostingGuard
{
    /** Reference type used when a Goods Receipt is the physical inbound. */
    public const REF_GOODS_RECEIPT = 'goods_receipt';

    /** Reference type used when a Mode 3 Supplier Invoice is the physical inbound. */
    public const REF_SUPPLIER_INVOICE = 'supplier_invoice';

    /**
     * True when a purchase-receipt movement already exists for this inbound reference.
     *
     * Scoped to `PurchaseReceipt` deliberately: a later issue, adjustment or transfer against
     * the same document must not be mistaken for the inbound itself.
     */
    public function alreadyPosted(string $referenceType, string $referenceId): bool
    {
        return StockLedgerEntry::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('movement_type', LedgerMovementType::PurchaseReceipt->value)
            ->exists();
    }

    /**
     * The canonical inbound reference for a supplier invoice.
     *
     * @return array{0: string, 1: string} [reference_type, reference_id]
     */
    public function referenceForInvoice(?string $autoReceiptId, string $invoiceId): array
    {
        return $autoReceiptId !== null && $autoReceiptId !== ''
            ? [self::REF_GOODS_RECEIPT, $autoReceiptId]
            : [self::REF_SUPPLIER_INVOICE, $invoiceId];
    }
}
