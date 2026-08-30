<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;

class SupplierInvoiceLine extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'supplier_invoice_id',
        'product_id',
        // V-5 anchor. The receipt line whose physical valuation this line settles — the single
        // deterministic source for GRNI clearing and Purchase Price Variance.
        'goods_receipt_line_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'discount_amount',
        'line_total',
        'uom_id_snapshot',
        'uom_name_snapshot',
        'uom_symbol_snapshot',
        'allocated_freight',
        'allocated_additional_costs',
        'landed_unit_cost',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'line_total' => 'decimal:4',
        'allocated_freight' => 'decimal:4',
        'allocated_additional_costs' => 'decimal:4',
        'landed_unit_cost' => 'decimal:4',
    ];

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The physical receipt line this invoice line settles — the V-5 anchor.
     *
     * Null only while the invoice is a draft raised before the goods arrived. A posting-ready
     * line must carry it; {@see \Modules\Purchasing\SupplierInvoices\Domain\Services\InvoiceReceiptAnchorService}
     * is what enforces that, together with company, supplier, product and quantity agreement.
     */
    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }
}
