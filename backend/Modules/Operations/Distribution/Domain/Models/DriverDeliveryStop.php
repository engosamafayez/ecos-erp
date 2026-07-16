<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Commerce\Orders\Domain\Models\Order;

class DriverDeliveryStop extends Model
{
    use HasUuids;

    protected $table = 'driver_delivery_stops';

    protected $fillable = [
        'distribution_trip_id',
        'order_id',
        'sequence',
        'status',
        'delivery_type',
        'collected_amount',
        'payment_method',
        'attempted_at',
        'completed_at',
        'gps_lat',
        'gps_lng',
        'notes',
    ];

    protected $casts = [
        'sequence'         => 'integer',
        'collected_amount' => 'decimal:2',
        'attempted_at'     => 'datetime',
        'completed_at'     => 'datetime',
        'gps_lat'          => 'decimal:7',
        'gps_lng'          => 'decimal:7',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(DriverDeliveryAction::class, 'stop_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DriverPaymentCollection::class, 'stop_id');
    }

    public function proof(): HasOne
    {
        return $this->hasOne(DriverDeliveryProof::class, 'stop_id');
    }
}
