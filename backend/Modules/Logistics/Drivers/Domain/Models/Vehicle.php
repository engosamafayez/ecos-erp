<?php

declare(strict_types=1);

namespace Modules\Logistics\Drivers\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Logistics\ShippingCompanies\Domain\Models\ShippingCompany;

/**
 * Minimal vehicle registry entry.
 *
 * Scope note: TASK-LOG-002 owns only what driver assignment needs. The future
 * Vehicles module (TASK-LOG-003) should extend this model and its table rather
 * than introduce a second vehicle aggregate.
 */
class Vehicle extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ARCHIVED];

    public const TYPES = ['van', 'truck', 'motorcycle', 'pickup', 'car'];

    protected $table = 'logistics_vehicles';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'plate_number',
        'type',
        'make',
        'model',
        'year',
        'capacity_orders',
        'shipping_company_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'capacity_orders' => 'integer',
        ];
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class, 'shipping_company_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DriverVehicleAssignment::class, 'vehicle_id')
            ->orderByDesc('assigned_at');
    }

    /** The single live assignment, if any (BR-7). */
    public function activeAssignment(): HasOne
    {
        return $this->hasOne(DriverVehicleAssignment::class, 'vehicle_id')
            ->whereNotNull('active_flag');
    }

    public function isAssigned(): bool
    {
        return $this->activeAssignment()->exists();
    }

    public function label(): string
    {
        $descriptor = trim(implode(' ', array_filter([$this->make, $this->model])));

        return $descriptor === ''
            ? $this->plate_number
            : "{$this->plate_number} — {$descriptor}";
    }
}
