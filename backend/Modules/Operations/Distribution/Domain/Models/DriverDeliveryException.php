<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDeliveryException extends Model
{
    protected $table = 'driver_delivery_exceptions';

    public $timestamps = false;

    protected $fillable = [
        'distribution_trip_id',
        'stop_id',
        'order_id',
        'exception_type',
        'description',
        'photos',
        'synced_to_cs',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
        'reported_by',
        'created_at',
    ];

    protected $casts = [
        'photos'       => 'array',
        'synced_to_cs' => 'boolean',
        'resolved_at'  => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DriverDeliveryStop::class, 'stop_id');
    }
}
