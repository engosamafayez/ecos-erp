<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * InventoryItem aggregate root — the authoritative source for current stock levels.
 *
 * One row exists per (warehouse_id, product_id) pair.
 * - on_hand_qty:  physically present stock
 * - reserved_qty: on_hand stock that is allocated to unfulfilled orders
 * - available_qty: computed accessor (on_hand_qty − reserved_qty)
 *
 * All mutations MUST go through domain actions, not direct Eloquent updates.
 *
 * @property string $id
 * @property string $warehouse_id
 * @property string $product_id
 * @property string $company_id
 * @property numeric-string $on_hand_qty
 * @property numeric-string $reserved_qty
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class InventoryItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'company_id',
        'on_hand_qty',
        'reserved_qty',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'on_hand_qty' => 'decimal:4',
            'reserved_qty' => 'decimal:4',
        ];
    }

    /**
     * SIGNED availability — `on_hand − reserved`, never clamped.
     *
     * A negative result is meaningful, not an error: it says commitment exceeds
     * physical stock. Clamping it to zero destroyed that fact and is the reason a
     * material with Allow Negative ON still rendered as "Out of Stock".
     *
     * Two consequences worth stating, because both were previously hidden:
     *
     *  - Signed subtraction is ASSOCIATIVE, clamping is not. `Σ(on_hand − reserved)`
     *    now equals `Σon_hand − Σreserved`, so the multi-warehouse summary and the
     *    list query can no longer disagree (they did — see the report).
     *  - This is AVAILABLE, not SHORTAGE. Shortage stays clamped at zero wherever
     *    it is computed (`max(0, required − available)`); negative shortage is
     *    meaningless. Do not "fix" those call sites to match this one.
     *
     * Whether a negative availability may be COMMITTED is a separate question,
     * answered by `allow_negative_stock` — never by this arithmetic.
     */
    public function availableQty(): float
    {
        return (float) $this->on_hand_qty - (float) $this->reserved_qty;
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<StockLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class);
    }
}
