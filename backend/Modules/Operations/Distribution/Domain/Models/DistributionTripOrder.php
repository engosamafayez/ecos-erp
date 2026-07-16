<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionTripOrder extends Model
{
    public $timestamps = false;

    protected $table = 'distribution_trip_orders';

    protected $fillable = [
        'distribution_trip_id', 'order_id', 'zone_code_snapshot',
        'governorate_snapshot', 'assignment_type', 'assigned_by', 'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }
}
