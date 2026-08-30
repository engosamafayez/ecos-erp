<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable Distribution Group CONFIGURATION — never a Group, never a snapshot.
 *
 * What it holds: a name, a maximum order count, and the Zones a Group made from it
 * should start with. That is the whole of it.
 *
 * What it deliberately cannot hold — and why the model has no such relations:
 *   • orders / assignments   `distribution_window_orders` is the only claim on an
 *                            Order's membership, and it is per-Window.
 *   • vehicle / driver       Logistics owns fleet identity
 *                            (`driver_vehicle_assignments`).
 *   • trip                   `distribution_trips` is produced by Finalize.
 *   • prepared quantities    `distribution_group_product_preparation` owns Prepared.
 *   • window / wave          a template outlives every operational cycle.
 *
 * Applying a template COPIES these values into a new Group and then has nothing
 * further to do with it. There is no link back: editing a template later must not
 * reach into a Group that was already created from it, and archiving one must not
 * disturb any live plan. That is why no `template_id` column exists on
 * `distribution_virtual_slots` — a template is not a Group's owner or its source of
 * truth, only its starting point.
 *
 * ARCHIVING IS `deleted_at`, following `distribution_zones`. An archived template
 * disappears from the picker and can no longer be applied; the Groups it already
 * produced are untouched.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property int|null $capacity_orders
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class DistributionGroupTemplate extends Model
{
    use HasUuids;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'distribution_group_templates';

    protected $fillable = [
        'company_id',
        'name',
        // NULL means unconstrained, never zero — the same contract as
        // VirtualCapacitySlot::$capacity_orders, which this column populates.
        'capacity_orders',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity_orders' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return HasMany<DistributionGroupTemplateZone, $this> */
    public function zones(): HasMany
    {
        return $this->hasMany(
            DistributionGroupTemplateZone::class,
            'distribution_group_template_id',
        );
    }

    /**
     * The Zone ids this template would attach, as plain ints.
     *
     * @return list<int>
     */
    public function zoneIds(): array
    {
        return $this->zones
            ->map(static fn (DistributionGroupTemplateZone $z): int => (int) $z->distribution_zone_id)
            ->values()
            ->all();
    }

    /**
     * Drivers this template RECOMMENDS to the operator. A suggestion only: it is
     * never read by apply and never becomes a Group assignment. The same Driver may
     * be recommended by many templates.
     *
     * @return HasMany<DistributionGroupTemplateDriver, $this>
     */
    public function recommendedDrivers(): HasMany
    {
        return $this->hasMany(
            DistributionGroupTemplateDriver::class,
            'distribution_group_template_id',
        );
    }

    /**
     * The recommended Driver ids, as plain ints.
     *
     * @return list<int>
     */
    public function recommendedDriverIds(): array
    {
        return $this->recommendedDrivers
            ->map(static fn (DistributionGroupTemplateDriver $d): int => (int) $d->logistics_driver_id)
            ->values()
            ->all();
    }
}
