<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDeliveryReturn extends Model
{
    protected $table = 'driver_delivery_returns';

    public $timestamps = false;

    protected $fillable = [
        'distribution_trip_id',
        'order_id',
        'product_id',
        'product_name',
        'return_type',
        'returned_qty',
        'reason',
        'photos',
        'warehouse_confirmed_qty',
        'warehouse_confirmed_at',
        'warehouse_confirmed_by',
        'discrepancy_qty',
        'driver_liability',
        'reported_by',
        'created_at',
    ];

    protected $casts = [
        'photos'                 => 'array',
        'returned_qty'           => 'decimal:3',
        'warehouse_confirmed_qty'=> 'decimal:3',
        'discrepancy_qty'        => 'decimal:3',
        'driver_liability'       => 'boolean',
        'warehouse_confirmed_at' => 'datetime',
        'created_at'             => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }
}
