<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementCategory;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementDirection;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementStatus;

/**
 * DRIVER TRIP OPERATIONAL MOVEMENT — TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §34.
 *
 * A minimal OPERATIONAL cash-movement record for one driver's active trip/custody: fuel, road
 * toll, other expense (cash OUT) or an advance (cash IN). It is NOT a General-Ledger entry and
 * NOT a settlement — it is the operational fact a future Driver Closing / Operations approval
 * step will consume. One writer of "the driver spent/received this on this trip".
 *
 * Direction is derived from category and stored for query clarity; a driver-created movement is
 * always Pending (the driver never self-approves — §35). Company/driver/trip are resolved
 * server-side from the authenticated driver's current active custody, never trusted from the
 * client (§36/§43).
 *
 * @property string $id
 * @property string $company_id
 * @property int $driver_id
 * @property int $trip_id
 * @property DriverTripMovementCategory $category
 * @property DriverTripMovementDirection $direction
 * @property float $amount
 * @property string|null $note
 * @property \Carbon\Carbon $occurred_at
 * @property DriverTripMovementStatus $status
 * @property string|null $storage_disk
 * @property string|null $receipt_path
 * @property string|null $receipt_mime
 * @property int|null $receipt_size
 * @property string|null $reviewed_by
 * @property \Carbon\Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property string $created_by
 * @property string $updated_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class DriverTripMovement extends Model
{
    use HasUuids;

    protected $table = 'driver_trip_movements';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'driver_id',
        'trip_id',
        'category',
        'direction',
        'amount',
        'note',
        'occurred_at',
        'status',
        'storage_disk',
        'receipt_path',
        'receipt_mime',
        'receipt_size',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => DriverTripMovementCategory::class,
            'direction' => DriverTripMovementDirection::class,
            'status' => DriverTripMovementStatus::class,
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'receipt_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function hasReceipt(): bool
    {
        return $this->receipt_path !== null && $this->receipt_path !== '';
    }
}
