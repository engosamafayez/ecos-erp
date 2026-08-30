<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Logistics\Distribution\Domain\Enums\DistributionWindowStatus;

/**
 * The daily Distribution Window — the unit automatic ingestion runs inside.
 *
 * Owns: its own open/cutoff times, its status, and the link to its successor.
 * Owns nothing about Orders, Zones or Vehicles — those are referenced.
 *
 * @property string $id
 * @property string $company_id
 * @property \Illuminate\Support\Carbon $window_date
 * @property \Illuminate\Support\Carbon $opens_at
 * @property \Illuminate\Support\Carbon $closes_at
 * @property DistributionWindowStatus $status
 * @property string|null $next_window_id
 * @property \Illuminate\Support\Carbon|null $cutoff_reached_at
 */
class DistributionWindow extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'distribution_windows';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => DistributionWindowStatus::Scheduled->value,
    ];

    protected $fillable = [
        'company_id',
        'window_date',
        'opens_at',
        'closes_at',
        'status',
        'next_window_id',
        'cutoff_reached_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'window_date' => 'date',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'cutoff_reached_at' => 'datetime',
            'status' => DistributionWindowStatus::class,
        ];
    }

    /** @return HasMany<DistributionWindowOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(DistributionWindowOrder::class, 'distribution_window_id');
    }

    /** @return HasMany<VirtualCapacitySlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(VirtualCapacitySlot::class, 'distribution_window_id');
    }

    /** @return HasMany<DistributionSlotZone, $this> */
    public function slotZones(): HasMany
    {
        return $this->hasMany(DistributionSlotZone::class, 'distribution_window_id');
    }

    /** @return BelongsTo<DistributionWindow, $this> */
    public function nextWindow(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_window_id');
    }

    /**
     * Is this Window currently taking Orders automatically?
     *
     * Evaluated from the clock, not from `status` alone: a Window whose cutoff
     * has passed but whose status has not yet been swept must not keep ingesting.
     */
    public function isAcceptingAutomaticIngestion(DateTimeInterface $now): bool
    {
        if (! $this->status->acceptsAutomaticIngestion()) {
            return false;
        }

        return $now >= $this->opens_at && $now < $this->closes_at;
    }

    /** Has this Window passed its cutoff, by the clock? */
    public function isPastCutoff(DateTimeInterface $now): bool
    {
        return $now >= $this->closes_at;
    }
}
