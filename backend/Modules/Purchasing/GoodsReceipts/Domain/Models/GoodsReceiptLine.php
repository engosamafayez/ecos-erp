<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;

/**
 * A single line in a goods receipt.
 *
 * @property string $id
 * @property string $goods_receipt_id
 * @property string $purchase_order_line_id
 * @property string $product_id
 * @property numeric-string $ordered_quantity
 * @property numeric-string $received_quantity
 */
class GoodsReceiptLine extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_line_id',
        'product_id',
        'ordered_quantity',
        'received_quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
