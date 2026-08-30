<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Virtual Capacity Slot — planned outbound capacity, NOT a Vehicle.
 *
 * There is deliberately no `vehicle_id` and no `driver_id` on this model. A real
 * Vehicle becomes operationally attached only once a Driver is assigned, and
 * that is the next task's boundary. Slots exist so capacity can be planned
 * before any of that is known.
 *
 * Capacity dimensions mirror Modules\Logistics\Network\Domain\Models\CapacitySlot.
 * A null dimension means "not constrained on this axis", which is not a capacity
 * of zero — the difference decides whether an overflow is even possible.
 *
 * @property string $id
 * @property string $company_id
 * @property string $distribution_window_id
 * @property string $warehouse_id
 * @property string $code
 * @property string|null $name
 * @property int|null $capacity_orders
 * @property int|null $capacity_stops
 * @property string|null $capacity_weight_kg
 * @property string|null $capacity_volume_m3
 */
class VirtualCapacitySlot extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'distribution_virtual_slots';

    protected $fillable = [
        'company_id',
        'distribution_window_id',
        /*
         * TASK-DISTRIBUTION-DAILY-GROUP-WAVE-LIFECYCLE-002 — durable identity.
         *
         * The Wave this Group is the operational instance OF, and the Template that
         * stamped it. Both are set once at creation.
         *
         * `distribution_group_template_id` is PROVENANCE, never a live reference:
         * nothing reads it to derive this Group's zones or capacity, so a later
         * Template edit cannot reach a Group that already exists.
         */
        'preparation_wave_id',
        'distribution_group_template_id',
        // The Group belongs to exactly ONE warehouse. It is a planning container
        // for that warehouse's outbound work and may never aggregate another's.
        'warehouse_id',
        'code',
        'name',
        'capacity_orders',
        'capacity_stops',
        'capacity_weight_kg',
        'capacity_volume_m3',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity_orders' => 'integer',
            'capacity_stops' => 'integer',
            // Closure is a lifecycle FACT, cast but deliberately NOT fillable: a Group
            // becomes historical through closeForWave(), never through a mass-assign.
            'closed_at' => 'datetime',
            'capacity_weight_kg' => 'decimal:2',
            'capacity_volume_m3' => 'decimal:3',
            // TASK-1-B-A2. Cast but NOT fillable, deliberately: an overflow approval is
            // an operator decision recorded by GroupFinalizationService, never something
            // a request payload may mass-assign. `updateSlot` validates and fills its own
            // fields, so leaving these out of $fillable makes the write path structural
            // rather than a rule someone has to remember.
            'overflow_approved_orders' => 'integer',
            'overflow_approved_at' => 'datetime',
            'overflow_approved_by' => 'integer',
        ];
    }

    /**
     * Has the operator approved this Group to exceed its planning capacity, for the
     * occupancy it holds right now?
     *
     * BOUNDED BY THE APPROVED COUNT, not a blanket waiver. An approval at 25 does not
     * license 40: growth past the approved figure makes this false again and the
     * operator is asked once more. That is what keeps the approval from meaning
     * "capacity is unlimited", which the contract explicitly forbids.
     *
     * `capacity_orders` is NOT consulted and NOT modified here. It remains the planning
     * limit; this answers a different question — whether an exception to it was accepted.
     */
    public function hasApprovedOverflowFor(int $occupancy): bool
    {
        return $this->overflow_approved_orders !== null
            && $occupancy <= (int) $this->overflow_approved_orders;
    }

    /** @return BelongsTo<DistributionWindow, $this> */
    public function window(): BelongsTo
    {
        return $this->belongsTo(DistributionWindow::class, 'distribution_window_id');
    }

    /** @return HasMany<DistributionSlotZone, $this> */
    public function zoneLinks(): HasMany
    {
        return $this->hasMany(DistributionSlotZone::class, 'virtual_slot_id');
    }

    /** @return HasMany<DistributionWindowOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(DistributionWindowOrder::class, 'virtual_slot_id');
    }

    /**
     * Is the Order dimension constrained at all?
     *
     * Overflow is only meaningful on a dimension that carries a limit.
     */
    public function constrainsOrders(): bool
    {
        return $this->capacity_orders !== null;
    }
}
