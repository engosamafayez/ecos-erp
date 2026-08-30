<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable per-component cost of a recipe AT THE MOMENT IT WAS APPROVED.
 *
 * The authoritative basis for disassembly valuation: what a component is worth when
 * a finished product is taken apart is what the approved recipe said it cost — never
 * the product's shipping cost, never its current FIFO cost, never the material's
 * price today.
 *
 * IMMUTABLE BY CONTRACT. Nothing in the platform updates these rows. A recipe edit
 * produces a NEW snapshot against a new version; the old one stays exactly as it was,
 * because goods built under version 1 must still be valued at version 1 prices long
 * after version 2 exists.
 *
 * @property string $id
 * @property string|null $company_id
 * @property string $bom_id
 * @property int $bom_version_number
 * @property float $total_cost
 * @property float $yield_quantity
 * @property \Illuminate\Support\Carbon $approved_at
 * @property string|null $approved_by
 * @property \Illuminate\Database\Eloquent\Collection<int, RecipeCostSnapshotLine> $lines
 */
final class RecipeCostSnapshot extends Model
{
    use HasUuids;

    protected $table = 'recipe_cost_snapshots';

    protected $fillable = [
        'company_id',
        'bom_id',
        'bom_version_number',
        'total_cost',
        'yield_quantity',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'bom_version_number' => 'integer',
            'total_cost' => 'float',
            'yield_quantity' => 'float',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Cost of one component per FINISHED-GOOD UNIT.
     *
     * Lines are stored per recipe batch, exactly as the recipe states them, so a
     * per-unit figure must divide by the yield frozen at approval — never by
     * whatever `bills_of_materials.yield_quantity` says today, which would re-price
     * historical stock the moment somebody edited the recipe's output quantity.
     */
    public function perUnitCost(RecipeCostSnapshotLine $line): float
    {
        return round((float) $line->extended_cost / $this->safeYield(), 4);
    }

    private function safeYield(): float
    {
        return max((float) $this->yield_quantity, 0.0001);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class, 'bom_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecipeCostSnapshotLine::class, 'snapshot_id');
    }
}
