<?php

declare(strict_types=1);

namespace Modules\MasterData\Warehouses\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Organization\Brands\Domain\Models\Brand;

/**
 * TASK-WAREHOUSE-COVERAGE-BRAND-ASSIGNMENT-001 — which brands a warehouse serves.
 *
 * The absence of rows is meaningful: a warehouse with no coverage rows serves
 * NO brands and can never be assigned an order. See the migration for the full
 * rationale. Nothing in this model may be written to imply otherwise.
 *
 * @property string $id
 * @property string $company_id
 * @property string $warehouse_id
 * @property string $brand_id
 * @property bool $is_active
 */
class WarehouseBrandCoverage extends Model
{
    use HasUuids;

    protected $table = 'warehouse_brand_coverage';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'warehouse_id',
        'brand_id',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
