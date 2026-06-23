<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;

/**
 * Goods Receipt header.
 *
 * @property string $id
 * @property string $receipt_number
 * @property string $purchase_order_id
 * @property string $warehouse_id
 * @property string $receipt_date
 * @property GoodsReceiptStatus $status
 * @property string|null $notes
 */
class GoodsReceipt extends Model
{
    use HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'receipt_number',
        'purchase_order_id',
        'warehouse_id',
        'receipt_date',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GoodsReceiptStatus::class,
            'receipt_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
