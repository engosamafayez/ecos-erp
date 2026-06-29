<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Products\Domain\Models\Product;

final class BillOfMaterial extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'bills_of_materials';

    protected $fillable = [
        'bom_number',
        'product_id',
        'version',
        'bom_version_number',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active'          => 'boolean',
            'bom_version_number' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillOfMaterialLine::class, 'bom_id');
    }
}
