<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $company_id
 * @property string $preparation_wave_id
 * @property string $order_id
 * @property string $order_number
 * @property \Carbon\Carbon $order_confirmed_at
 * @property string|null $customer_name_snapshot
 * @property string|null $delivery_zone_snapshot
 * @property string|null $delivery_window_id
 * @property string|null $delivery_window_label
 * @property string|null $delivery_window_starts_at
 * @property string|null $delivery_window_ends_at
 * @property string|null $governorate_snapshot
 * @property string|null $master_governorate_id
 * @property string|null $zone_code_snapshot
 * @property string|null $master_zone_id
 * @property float|null $shipping_cost_snapshot
 * @property int $preparation_priority
 * @property bool $is_paid
 * @property \Carbon\Carbon $added_at
 * @property string $added_by
 */
class PreparationWaveOrder extends Model
{
    use HasUuids;

    protected $table = 'preparation_wave_orders';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'preparation_wave_id',
        'order_id',
        'order_number',
        'order_confirmed_at',
        'customer_name_snapshot',
        'delivery_zone_snapshot',
        'delivery_window_id',
        'delivery_window_label',
        'delivery_window_starts_at',
        'delivery_window_ends_at',
        'governorate_snapshot',
        'master_governorate_id',
        'zone_code_snapshot',
        'master_zone_id',
        'shipping_cost_snapshot',
        'preparation_priority',
        'is_paid',
        'added_at',
        'added_by',
        'postponed_at',
        'released_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_confirmed_at' => 'datetime',
            'added_at' => 'datetime',
            'shipping_cost_snapshot' => 'decimal:2',
            'preparation_priority' => 'integer',
            'is_paid' => 'boolean',
            'postponed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * Membership rows still counted by the current preparation cycle.
     *
     * A postponed row is deliberately RETAINED rather than deleted, so the collector's
     * `whereNotExists` keeps excluding the order and the scheduler cannot re-attach it
     * (see the 2026_08_13_100000 migration). Every consumer that means "orders being
     * prepared now" therefore has to say so explicitly — this scope is that statement.
     * No global scope is used: five consumers query this table through the raw query
     * builder, which would silently bypass one and make the filter unreliable.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<$this>  $query
     * @return \Illuminate\Database\Eloquent\Builder<$this>
     */
    public function scopeActive($query)
    {
        return $query->whereNull('postponed_at');
    }

    /**
     * Memberships that still bind the order to a wave — the ACTIVE-membership predicate.
     *
     * Deliberately distinct from {@see scopeActive()}, and the distinction is the whole
     * of the carry-over design:
     *
     *   scopeActive()           postponed_at IS NULL — "counts toward THIS cycle's work".
     *                           Demand, missing materials and loading allocation use it.
     *   scopeActiveMembership() released_at  IS NULL — "this order belongs to this wave".
     *                           Exclusivity and re-collection use it.
     *
     * A postponed member is still a MEMBER: it holds the order's one active membership
     * until the wave ends. That is what stops `attachEligibleOrders` re-attaching a
     * postponed order to the very same wave on the next tick — the guarantee
     * REFINEMENT-002 installed — while still letting the order carry over once the wave
     * closes and every membership is released.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<$this>  $query
     * @return \Illuminate\Database\Eloquent\Builder<$this>
     */
    public function scopeActiveMembership($query)
    {
        return $query->whereNull('released_at');
    }

    /** True once the order has been postponed out of the current cycle. */
    public function isPostponed(): bool
    {
        return $this->postponed_at !== null;
    }

    /** True once this membership has been closed out — the wave it belonged to ended. */
    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /** @return BelongsTo<PreparationWave, $this> */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(PreparationWave::class, 'preparation_wave_id');
    }
}
