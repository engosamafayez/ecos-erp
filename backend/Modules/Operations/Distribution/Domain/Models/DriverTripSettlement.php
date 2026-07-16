<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverTripSettlement extends Model
{
    protected $table = 'driver_trip_settlements';

    protected $fillable = [
        'distribution_trip_id',
        'cash_collected',
        'bank_transfers_pending',
        'already_paid',
        'total_collected',
        'cash_expected',
        'driver_cash_submitted',
        'discrepancy',
        'status',
        'finalized_at',
        'finalized_by',
        'notes',
    ];

    protected $casts = [
        'cash_collected'         => 'decimal:2',
        'bank_transfers_pending' => 'decimal:2',
        'already_paid'           => 'decimal:2',
        'total_collected'        => 'decimal:2',
        'cash_expected'          => 'decimal:2',
        'driver_cash_submitted'  => 'decimal:2',
        'discrepancy'            => 'decimal:2',
        'finalized_at'           => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }
}
