<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FleetVehicle extends Model
{
    use SoftDeletes;

    protected $table = 'fleet_vehicles';

    protected $fillable = [
        'company_id', 'plate_number', 'type', 'make', 'model',
        'year', 'capacity_orders', 'status', 'notes', 'is_active',
    ];

    protected $casts = [
        'year'           => 'integer',
        'capacity_orders' => 'integer',
        'is_active'      => 'boolean',
    ];

    public function trips(): HasMany
    {
        return $this->hasMany(DistributionTrip::class, 'fleet_vehicle_id');
    }

    public function scopeAvailable($query, int $companyId)
    {
        return $query->where('company_id', $companyId)
            ->where('status', 'available')
            ->where('is_active', true);
    }

    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([$this->make, $this->model, "({$this->plate_number})"]);
        return implode(' ', $parts);
    }
}
