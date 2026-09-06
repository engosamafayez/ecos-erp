<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An order's association with a trip — one execution ATTEMPT.
 *
 * An order may accumulate MULTIPLE historical rows over time (one per
 * retryable delivery attempt), but at most one is ever ACTIVE: `superseded_at
 * IS NULL` (see `active(): Builder` below). The DB-level backstop is a STORED
 * generated column, `active_order_id` (NULL once superseded), carrying a
 * UNIQUE index — see the `add_historical_attempt_semantics_to_trip_orders`
 * migration for why this replaces the old single-column unique(order_id).
 *
 * Releasing an order (TripService::releaseOrder()) sets `superseded_at` and
 * never deletes the row — the prior Trip/Driver/outcome context stays
 * historical and auditable (contract clauses C/G).
 */
class TripOrder extends Model
{
    protected $table = 'distribution_trip_orders';

    /** @var array<string, mixed> */
    protected $attributes = [
        'assignment_type' => 'auto',
    ];

    protected $fillable = [
        'trip_id',
        'order_id',
        'zone_code_snapshot',
        'governorate_snapshot',
        'assignment_type',
        'assigned_by',
        'assigned_at',
        'superseded_at',
        'release_reason',
        'released_by',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function isManual(): bool
    {
        return $this->assignment_type === 'manual';
    }

    /** True while this is the order's current, active execution (not yet released). */
    public function isActive(): bool
    {
        return $this->superseded_at === null;
    }

    /** Scope to only the active (non-superseded) row(s) — at most one per order. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }
}
