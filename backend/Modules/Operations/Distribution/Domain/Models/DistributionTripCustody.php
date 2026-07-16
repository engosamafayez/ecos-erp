<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionTripCustody extends Model
{
    public $timestamps = false;

    protected $table = 'distribution_trip_custody';

    protected $fillable = [
        'distribution_trip_id', 'item_type', 'description',
        'quantity', 'notes', 'created_by',
        'received_quantity', 'is_driver_confirmed',
        'driver_confirmed_at', 'driver_confirmed_by',
    ];

    protected $casts = [
        'quantity'            => 'integer',
        'received_quantity'   => 'integer',
        'is_driver_confirmed' => 'boolean',
        'created_at'          => 'datetime',
        'driver_confirmed_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }

    public function getLabelAttribute(): string
    {
        return match ($this->item_type) {
            'cash_float'    => 'Cash Float',
            'pos_device'    => 'POS Device',
            'ice_boxes'     => 'Ice Boxes',
            'ice_packs'     => 'Ice Packs',
            'thermal_bags'  => 'Thermal Bags',
            'delivery_bags' => 'Delivery Bags',
            default         => $this->description ?? 'Other',
        };
    }
}
