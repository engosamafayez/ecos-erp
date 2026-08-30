<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component's frozen cost within a {@see RecipeCostSnapshot}.
 *
 * `unit_cost` is the material's cost at approval; `extended_cost` is that multiplied
 * by the waste-adjusted quantity. Both are frozen — a later change to the material's
 * cost must not reach back and alter what an already-approved recipe said its
 * components were worth.
 *
 * sku / name / unit are denormalised so a snapshot still reads correctly after the
 * material is renamed or soft-deleted.
 *
 * @property string $id
 * @property string $snapshot_id
 * @property string|null $recipe_line_id
 * @property string $raw_material_id
 * @property float $quantity
 * @property float $waste_percentage
 * @property float $unit_cost
 * @property float $extended_cost
 * @property string|null $sku_snapshot
 * @property string|null $name_snapshot
 * @property string|null $unit_symbol_snapshot
 */
final class RecipeCostSnapshotLine extends Model
{
    use HasUuids;

    protected $table = 'recipe_cost_snapshot_lines';

    protected $fillable = [
        'snapshot_id',
        'recipe_line_id',
        'raw_material_id',
        'quantity',
        'waste_percentage',
        'unit_cost',
        'extended_cost',
        'sku_snapshot',
        'name_snapshot',
        'unit_symbol_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'waste_percentage' => 'float',
            'unit_cost' => 'float',
            'extended_cost' => 'float',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(RecipeCostSnapshot::class, 'snapshot_id');
    }
}
