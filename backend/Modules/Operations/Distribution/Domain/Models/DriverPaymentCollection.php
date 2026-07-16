<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverPaymentCollection extends Model
{
    protected $table = 'driver_payment_collections';

    public $timestamps = false;

    protected $fillable = [
        'stop_id',
        'distribution_trip_id',
        'payment_type',
        'amount',
        'reference_number',
        'notes',
        'image_path',
        'status',
        'verified_at',
        'verified_by',
        'created_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'verified_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DriverDeliveryStop::class, 'stop_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }
}
