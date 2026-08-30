<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Enums\DistributionAssignmentSource;

/**
 * An Order's Distribution assignment — Window, Zone and Slot.
 *
 * This row is the ENTIRETY of an Order's Distribution state. It carries nothing
 * about the Order's lifecycle: Distribution never reads or writes
 * `orders.status`. Moving an Order between Zones or Slots touches this row and
 * nothing else, which is what keeps assignment and lifecycle independent.
 *
 * `order_id` is globally unique. An Order belongs to exactly one Window at a
 * time, so a Manual Late-Order Assignment MOVES this row rather than creating a
 * second one — and that is also what makes repeated automatic collection
 * idempotent without any explicit de-duplication pass.
 *
 * @property string $id
 * @property string $company_id
 * @property string $distribution_window_id
 * @property string $order_id
 * @property int|null $distribution_zone_id
 * @property string|null $virtual_slot_id
 * @property DistributionAssignmentSource $assignment_source
 * @property int|null $assigned_by
 * @property \Illuminate\Support\Carbon $assigned_at
 * @property string|null $previous_window_id
 * @property string|null $assignment_reason
 */
class DistributionWindowOrder extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'distribution_window_orders';

    /** @var array<string, mixed> */
    protected $attributes = [
        'assignment_source' => DistributionAssignmentSource::Automatic->value,
    ];

    protected $fillable = [
        'company_id',
        'distribution_window_id',
        'order_id',
        'distribution_zone_id',
        'virtual_slot_id',
        'assignment_source',
        'assigned_by',
        'assigned_at',
        'previous_window_id',
        'assignment_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'distribution_zone_id' => 'integer',
            'assigned_by' => 'integer',
            'assigned_at' => 'datetime',
            'assignment_source' => DistributionAssignmentSource::class,
        ];
    }

    /** @return BelongsTo<DistributionWindow, $this> */
    public function window(): BelongsTo
    {
        return $this->belongsTo(DistributionWindow::class, 'distribution_window_id');
    }

    /** @return BelongsTo<VirtualCapacitySlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(VirtualCapacitySlot::class, 'virtual_slot_id');
    }

    /** @return BelongsTo<DistributionZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DistributionZone::class, 'distribution_zone_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
