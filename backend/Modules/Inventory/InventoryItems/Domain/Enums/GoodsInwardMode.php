<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Domain\Enums;

/**
 * Which document is a company's authoritative goods-inward path — the G-1 contract.
 *
 * Exactly one of these owns inventory posting for a given company at a given time. The other
 * document remains fully usable as a business record; it simply does not move stock.
 *
 * This is what makes "one physical inbound → one inventory posting" hold WITHOUT matching
 * documents to each other. There is no quantity comparison, no price comparison, no timestamp
 * window and no fuzzy matching anywhere in the contract: the question "may this document post?"
 * is answered from configuration alone, so it cannot be defeated by the order operators happen
 * to work in.
 */
enum GoodsInwardMode: string
{
    /** Receiving drives inventory. A Supplier Invoice is a financial document only. */
    case GoodsReceipt = 'goods_receipt';

    /** ADR-011 Mode 3 — the Supplier Invoice drives inventory. A Goods Receipt records receiving only. */
    case SupplierInvoice = 'supplier_invoice';

    /**
     * The safe interpretation of an unknown or unset value.
     *
     * Receiving is the traditional path and the schema default, so an unconfigured company
     * behaves exactly as it did before this contract existed.
     */
    public static function default(): self
    {
        return self::GoodsReceipt;
    }

    public static function tryFromValue(?string $value): self
    {
        return $value === null ? self::default() : (self::tryFrom($value) ?? self::default());
    }

    /**
     * A stable, non-localised name for the mode.
     *
     * The Configuration API exposes this so a client has something meaningful without
     * hard-coding the enum; user-facing Arabic and English copy is owned by the frontend's
     * i18n catalogue, not by the backend.
     */
    public function label(): string
    {
        return match ($this) {
            self::GoodsReceipt => 'Goods Receipt',
            self::SupplierInvoice => 'Supplier Invoice (Mode 3)',
        };
    }
}
