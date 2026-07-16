<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverCustodyReturn extends Model
{
    protected $table = 'driver_custody_returns';

    public $timestamps = false;

    protected $fillable = [
        'distribution_trip_id',
        'custody_type',
        'dispatched_qty',
        'returned_qty',
        'driver_liable',
        'notes',
        'confirmed_by',
        'confirmed_at',
        'created_at',
    ];

    protected $casts = [
        'dispatched_qty' => 'integer',
        'returned_qty'   => 'integer',
        'driver_liable'  => 'boolean',
        'confirmed_at'   => 'datetime',
        'created_at'     => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }
}
