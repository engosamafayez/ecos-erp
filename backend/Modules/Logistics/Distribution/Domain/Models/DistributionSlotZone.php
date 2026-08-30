<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a Zone in a Virtual Capacity Slot, within one Window.
 *
 * A Slot may hold many Zones; a Zone belongs to at most one Slot per Window AND
 * WAREHOUSE. That asymmetry is enforced by a unique index on
 * (window, warehouse, zone), not by application code — which is why an Order can
 * inherit its Slot from its Zone without ambiguity.
 *
 * `warehouse_id` is denormalised from the owning Slot for exactly one reason:
 * MySQL cannot express a unique index that reaches through `virtual_slot_id`
 * into the Slot's warehouse. It is the same reason `distribution_window_id` is
 * already denormalised here.
 *
 * @property int $id
 * @property string $distribution_window_id
 * @property string $virtual_slot_id
 * @property string $warehouse_id
 * @property int $distribution_zone_id
 */
class DistributionSlotZone extends Model
{
    protected $table = 'distribution_slot_zones';

    protected $fillable = [
        'distribution_window_id',
        'virtual_slot_id',
        'warehouse_id',
        'distribution_zone_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'distribution_zone_id' => 'integer',
        ];
    }

    /** @return BelongsTo<VirtualCapacitySlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(VirtualCapacitySlot::class, 'virtual_slot_id');
    }

    /** @return BelongsTo<DistributionWindow, $this> */
    public function window(): BelongsTo
    {
        return $this->belongsTo(DistributionWindow::class, 'distribution_window_id');
    }

    /** @return BelongsTo<DistributionZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DistributionZone::class, 'distribution_zone_id');
    }
}
