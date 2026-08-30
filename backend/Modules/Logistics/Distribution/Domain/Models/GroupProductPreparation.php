<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one Product a warehouse has physically separated for one Distribution Group.
 *
 * ┌─ WHAT THIS IS, AND WHAT IT IS NOT ───────────────────────────────────────┐
 * │ IS:  an operational record of floor work — "these units are on THIS       │
 * │      Group's staging pallet".                                            │
 * │                                                                          │
 * │ NOT: an inventory movement, not a reservation, not an order-fulfilment    │
 * │      quantity, and not a claim that the Group is loaded, approved,        │
 * │      finalized or dispatched.                                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * IT IS NOT `wave_product_demand.prepared_qty`, even when the two numbers happen
 * to be equal. They differ in scope (one Group vs one wave), in owner (the Group's
 * loading operator vs the wave's preparation operator) and in lifecycle (the
 * Window's day vs the wave's cycle). They must never be summed, compared or
 * reconciled against one another:
 *
 *     Σ Prepared(group) over a wave's Groups  ≠  Prepared(wave)
 *
 * because a wave's orders need not all be grouped, and a Group's orders can span
 * several waves.
 *
 * NO TENANT GLOBAL SCOPE. Nothing in this module has one — all 17 Distribution
 * models extend Model directly and scope company_id explicitly in the controller
 * and service layers. This model follows that: every read and write of this table
 * MUST be reached through a Group that has already been tenant-resolved. It never
 * scopes itself, and it must never be queried by bare id.
 *
 * @property string $id
 * @property string $company_id
 * @property string $distribution_window_id
 * @property string $virtual_slot_id
 * @property string $product_id
 * @property float $prepared_qty
 * @property int|null $last_recorded_by
 * @property \Carbon\Carbon|null $last_recorded_at
 */
class GroupProductPreparation extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'distribution_group_product_preparation';

    protected $fillable = [
        'company_id',
        'distribution_window_id',
        // The owner. `virtual_slot_id` is the Distribution Group — the physical table
        // is `distribution_virtual_slots`, and this is the column name every other
        // table in the module uses for that reference.
        'virtual_slot_id',
        'product_id',
        'prepared_qty',
        'last_recorded_by',
        'last_recorded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // decimal(12,4) in the schema, matching order_lines.quantity — the column
            // Required is summed from, and the only quantity this one is compared to.
            'prepared_qty' => 'float',
            'last_recorded_by' => 'integer',
            'last_recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<VirtualCapacitySlot, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(VirtualCapacitySlot::class, 'virtual_slot_id');
    }

    /** @return BelongsTo<DistributionWindow, $this> */
    public function window(): BelongsTo
    {
        return $this->belongsTo(DistributionWindow::class, 'distribution_window_id');
    }
}
