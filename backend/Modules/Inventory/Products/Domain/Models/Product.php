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
 * @property string $name
 * @property string $category_id
 * @property string $unit_id
 * @property string $product_type
 * @property bool $is_active
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
