<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DRIVER LOCATION PING — TASK-ECOS-SHIPPING-OS-REDESIGN-004 §13/§14.
 *
 * ONE recorded GPS sample, tied to a Trip's live execution — the append-only
 * time series no part of this platform persisted before this task (see the
 * creating migration's own docblock for the audit that established this).
 * A ping is only ever written while its Trip is in a trackable
 * custody+execution state (enforced in `DriverRuntimeController::gps()`,
 * not here) — this model does not itself decide trackability, it only
 * stores what was already proven trackable at write time.
 *
 * Company/driver/trip are always resolved server-side from the
 * authenticated driver's own owned Trip — never trusted from the client.
 *
 * @property string $id
 * @property string $company_id
 * @property int $driver_id
 * @property int $trip_id
 * @property float $latitude
 * @property float $longitude
 * @property float|null $accuracy_meters
 * @property float|null $speed_kph
 * @property \Carbon\Carbon $recorded_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class DriverLocationPing extends Model
{
    use HasUuids;

    protected $table = 'distribution_driver_location_pings';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'driver_id',
        'trip_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'speed_kph',
        'recorded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'decimal:2',
            'speed_kph' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    /**
     * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §6 — Fresh vs Stale, classified
     * against `distribution.tracking.stale_after_seconds`, the one named
     * threshold this task introduced. The single home for this rule so every
     * caller (Live Map, Route History) reads the same verdict; never
     * re-derive the comparison inline.
     */
    public function isFresh(): bool
    {
        $staleAfterSeconds = (int) config('distribution.tracking.stale_after_seconds');

        return $this->recorded_at->copy()->addSeconds($staleAfterSeconds)->isFuture();
    }
}
