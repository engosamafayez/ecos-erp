<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExternalCarrier extends Model
{
    use SoftDeletes;

    protected $table = 'external_carriers';

    protected $fillable = [
        'company_id', 'name', 'contact_person', 'phone',
        'rate_per_order', 'notes', 'is_active',
    ];

    protected $casts = [
        'rate_per_order' => 'decimal:2',
        'is_active'      => 'boolean',
    ];

    public function trips(): HasMany
    {
        return $this->hasMany(DistributionTrip::class, 'external_carrier_id');
    }

    public function scopeActive($query, int $companyId)
    {
        return $query->where('company_id', $companyId)->where('is_active', true);
    }
}
