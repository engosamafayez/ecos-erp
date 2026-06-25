<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Products\Infrastructure\Database\Factories\ProductFactory;
use Modules\MasterData\Categories\Domain\Models\Category;
use Modules\MasterData\Units\Domain\Models\Unit;

/**
 * Product entity (UUID primary key, soft-deletable).
 *
 * @property string $id
 * @property string $sku
 * @property string|null $barcode
 * @property string $name
 * @property string|null $description
 * @property string $category_id
 * @property string $unit_id
 * @property string $product_type
 * @property bool $is_active
 * @property string|null $image_url
 * @property float|null $regular_price
 * @property float|null $sale_price
 * @property float|null $last_purchase_cost  Updated on every posted GR
 * @property float|null $average_cost        Weighted average cost across all receipts
 * @property float|null $current_fifo_cost   Cost of the oldest available receipt layer
 * @property string|null $last_purchase_date ISO date of most recent GR post
 * @property string|null $last_supplier_id   UUID of last supplier (historical, no FK)
 * @property string|null $short_description
 * @property string|null $long_description
 * @property \Modules\Inventory\Products\Domain\Enums\ProductStockStatus|null $stock_status
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const TYPE_FINISHED_GOOD = 'finished_good';

    public const TYPE_RAW_MATERIAL = 'raw_material';

    /** @var list<string> */
    public const TYPES = [self::TYPE_FINISHED_GOOD, self::TYPE_RAW_MATERIAL];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'description',
        'category_id',
        'unit_id',
        'product_type',
        'is_active',
        'image_url',
        'regular_price',
        'sale_price',
        'last_purchase_cost',
        'average_cost',
        'current_fifo_cost',
        'last_purchase_date',
        'last_supplier_id',
        'short_description',
        'long_description',
        'stock_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active'           => 'boolean',
            'regular_price'       => 'float',
            'sale_price'          => 'float',
            'last_purchase_cost'  => 'float',
            'average_cost'        => 'float',
            'current_fifo_cost'   => 'float',
            'last_purchase_date'  => 'date:Y-m-d',
            'stock_status'        => \Modules\Inventory\Products\Domain\Enums\ProductStockStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
