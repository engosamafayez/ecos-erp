<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DistributionTrip extends Model
{
    use HasUuids;

    protected $table = 'distribution_trips';

    protected $fillable = [
        'company_id', 'preparation_wave_id', 'distribution_zone_id',
        'trip_number', 'name', 'type', 'capacity',
        'fleet_vehicle_id', 'fleet_driver_id', 'external_carrier_id',
        'driver_name', 'driver_phone',
        'status', 'orders_count', 'collection_amount', 'notes',
        'finalized_at', 'finalized_by', 'created_by',
        // DIST-004 dispatch fields
        'dispatched_at', 'dispatched_by', 'driver_notified_at',
        // DIST-004A driver acceptance fields
        'driver_accepted_products', 'driver_accepted_custody', 'driver_accepted_equipment',
        'driver_acceptance_at', 'driver_acceptance_by',
        'has_discrepancy', 'discrepancy_notes',
        // DIST-004A departure / vehicle dispatch fields
        'departure_at', 'departure_by', 'odometer_start', 'fuel_level',
        'gps_tracking_started', 'gps_tracking_started_at',
        // DIST-005 Driver Mobile OS
        'trip_started_at', 'trip_start_lat', 'trip_start_lng',
        'trip_finished_at', 'trip_finish_lat', 'trip_finish_lng',
        'odometer_end', 'total_cash_collected', 'total_bank_transfers', 'total_already_paid',
    ];

    protected $casts = [
        'capacity'                  => 'integer',
        'orders_count'              => 'integer',
        'collection_amount'         => 'decimal:2',
        'finalized_at'              => 'datetime',
        'dispatched_at'             => 'datetime',
        'driver_acceptance_at'      => 'datetime',
        'driver_accepted_products'  => 'boolean',
        'driver_accepted_custody'   => 'boolean',
        'driver_accepted_equipment' => 'boolean',
        'has_discrepancy'           => 'boolean',
        'departure_at'              => 'datetime',
        'odometer_start'            => 'integer',
        'fuel_level'                => 'float',
        'gps_tracking_started'      => 'boolean',
        'gps_tracking_started_at'   => 'datetime',
        // DIST-005
        'trip_started_at'           => 'datetime',
        'trip_start_lat'            => 'decimal:7',
        'trip_start_lng'            => 'decimal:7',
        'trip_finished_at'          => 'datetime',
        'trip_finish_lat'           => 'decimal:7',
        'trip_finish_lng'           => 'decimal:7',
        'odometer_end'              => 'integer',
        'total_cash_collected'      => 'decimal:2',
        'total_bank_transfers'      => 'decimal:2',
        'total_already_paid'        => 'decimal:2',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'fleet_vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(FleetDriver::class, 'fleet_driver_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ExternalCarrier::class, 'external_carrier_id');
    }

    public function tripOrders(): HasMany
    {
        return $this->hasMany(DistributionTripOrder::class, 'distribution_trip_id');
    }

    public function custodyItems(): HasMany
    {
        return $this->hasMany(DistributionTripCustody::class, 'distribution_trip_id');
    }

    public function deliveryStops(): HasMany
    {
        return $this->hasMany(DriverDeliveryStop::class, 'distribution_trip_id');
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(DriverTripSettlement::class, 'distribution_trip_id');
    }

    public function getCapacityUsagePercentAttribute(): float
    {
        if ($this->capacity === 0) return 0;
        return round(($this->orders_count / $this->capacity) * 100, 1);
    }

    public function getCapacityStatusAttribute(): string
    {
        $pct = $this->capacity_usage_percent;
        if ($pct >= 95) return 'critical';
        if ($pct >= 80) return 'warning';
        return 'ok';
    }

    public function isReadyForLoading(): bool
    {
        if ($this->orders_count === 0) return false;

        return match ($this->type) {
            'company_vehicle'  => $this->fleet_driver_id !== null && $this->fleet_vehicle_id !== null,
            'personal_vehicle' => $this->driver_name !== null,
            'external_carrier' => $this->external_carrier_id !== null,
            default            => false,
        };
    }
}
