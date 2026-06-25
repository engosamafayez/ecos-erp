<?php

declare(strict_types=1);

namespace Modules\Inventory\CountSessions\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * @property string $id
 * @property string $session_id
 * @property string $product_id
 * @property string|null $inventory_item_id
 * @property float $system_qty
 * @property float|null $counted_qty
 * @property float|null $variance_qty
 * @property float|null $variance_value
 * @property string|null $notes
 * @property string|null $photo_path
 */
class InventoryCountLine extends Model
{
    use HasUuids;

    protected $table = 'inventory_count_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'session_id',
        'product_id',
        'inventory_item_id',
        'system_qty',
        'counted_qty',
        'variance_qty',
        'variance_value',
        'notes',
        'photo_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'system_qty'    => 'decimal:4',
            'counted_qty'   => 'decimal:4',
            'variance_qty'  => 'decimal:4',
            'variance_value'=> 'decimal:2',
        ];
    }

    /** @return BelongsTo<InventoryCountSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(InventoryCountSession::class, 'session_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
