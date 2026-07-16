<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverGpsWaypoint extends Model
{
    protected $table = 'driver_gps_waypoints';

    public $timestamps = false;

    protected $fillable = [
        'distribution_trip_id',
        'lat',
        'lng',
        'speed',
        'accuracy',
        'recorded_at',
    ];

    protected $casts = [
        'lat'         => 'decimal:7',
        'lng'         => 'decimal:7',
        'speed'       => 'decimal:2',
        'accuracy'    => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }
}
