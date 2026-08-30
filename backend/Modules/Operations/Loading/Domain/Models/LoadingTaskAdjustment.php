<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One round of the driver ↔ warehouse quantity conversation — APPEND ONLY.
 *
 * ┌─ NO OVERWRITE WITHOUT TRACE ─────────────────────────────────────────────┐
 * │ A decision APPENDS a row; it never edits an earlier one except to close    │
 * │ the single `open` request it resolves. Round 1 survives round 2, which is  │
 * │ the whole reason this is a table and not a column on `loading_tasks`.      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `actor_type` is the structural expression of "one writer per fact": a `driver` row
 * may carry `driver_reported_qty` but never moves `quantity_loaded`; only a
 * `warehouse` row carries `quantity_after`.
 */
class LoadingTaskAdjustment extends Model
{
    protected $table = 'loading_task_adjustment_log';

    public $incrementing = false;

    protected $keyType = 'string';

    /** Requested by the driver, awaiting warehouse review. The only actionable state. */
    public const STATUS_OPEN = 'open';

    /** Warehouse took the driver's number. */
    public const STATUS_ACCEPTED = 'accepted';

    /** Warehouse entered a third number of its own. */
    public const STATUS_REVISED = 'revised';

    /** Warehouse declined; the canonical Loaded quantity is unchanged. */
    public const STATUS_REJECTED = 'rejected';

    public const ACTION_DRIVER_REQUESTED = 'driver_requested';

    public const ACTION_WAREHOUSE_ACCEPTED = 'warehouse_accepted';

    public const ACTION_WAREHOUSE_REVISED = 'warehouse_revised';

    public const ACTION_WAREHOUSE_REJECTED = 'warehouse_rejected';

    public const ACTOR_WAREHOUSE = 'warehouse';

    public const ACTOR_DRIVER = 'driver';

    protected $fillable = [
        'id',
        'company_id',
        'loading_task_id',
        'action_type',
        'actor_type',
        'actor_id',
        'quantity_before',
        'quantity_after',
        'driver_reported_qty',
        'reason',
        'status',
        'resolved_by',
        'resolved_at',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'float',
            'quantity_after' => 'float',
            'driver_reported_qty' => 'float',
            'resolved_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if ($row->id === null || $row->id === '') {
                $row->id = (string) Str::uuid();
            }

            if ($row->recorded_at === null) {
                $row->recorded_at = now();
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(LoadingTask::class, 'loading_task_id');
    }

    /** Only an open request is actionable; everything else is history. */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
