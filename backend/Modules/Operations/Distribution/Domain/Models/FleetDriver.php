<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FleetDriver extends Model
{
    use SoftDeletes;

    protected $table = 'fleet_drivers';

    protected $fillable = [
        'company_id', 'name_en', 'name_ar', 'phone', 'national_id',
        'license_type', 'license_expiry', 'status', 'notes', 'is_active',
    ];

    protected $casts = [
        'license_expiry' => 'date',
        'is_active'      => 'boolean',
    ];

    public function trips(): HasMany
    {
        return $this->hasMany(DistributionTrip::class, 'fleet_driver_id');
    }

    public function scopeAvailable($query, int $companyId)
    {
        return $query->where('company_id', $companyId)
            ->where('status', 'available')
            ->where('is_active', true);
    }
}
