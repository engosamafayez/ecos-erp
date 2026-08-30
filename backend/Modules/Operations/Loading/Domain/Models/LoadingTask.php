<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Operations\Loading\Domain\Enums\LoadingTaskStatus;

/**
 * @property string $id
 * @property string $company_id
 * @property string $loading_session_id
 * @property string $vehicle_assignment_id
 * @property string $pool_entry_id
 * @property string $product_id
 * @property string $sku_snapshot
 * @property string $name_snapshot
 * @property string $preparation_wave_id
 * @property float $quantity_planned
 * @property float $quantity_loaded
 * @property float $quantity_short
 * @property LoadingTaskStatus $status
 * @property bool $requires_refrigeration
 * @property string|null $loaded_by
 * @property \Carbon\Carbon|null $loaded_at
 * @property string|null $confirmed_by
 * @property \Carbon\Carbon|null $confirmed_at
 * @property string|null $short_reason
 * @property string|null $notes
 * @property string $created_by
 * @property string $updated_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class LoadingTask extends Model
{
    use HasUuids;

    protected $table = 'loading_tasks';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'loading_session_id',
        'vehicle_assignment_id',
        'pool_entry_id',
        'product_id',
        'sku_snapshot',
        'name_snapshot',
        'preparation_wave_id',
        'quantity_planned',
        'quantity_loaded',
        'quantity_short',
        'status',
        'requires_refrigeration',
        'loaded_by',
        'loaded_at',
        // WAREHOUSE confirmation. These columns existed from the start and were written
        // by nothing; TASK-...-CUSTODY-IMPLEMENTATION-001 claims them for the warehouse
        // half, which is why that half needed no migration.
        'confirmed_by',
        'confirmed_at',
        // DRIVER receipt — a different fact, by a different actor, with its own writer.
        // NULL means "not counted yet", which is not the same as a counted zero.
        'driver_received_qty',
        'driver_confirmed_at',
        'driver_confirmed_by',
        // The WAREHOUSE quantity the driver agreed to. This is what makes a later
        // warehouse revision invalidate the confirmation, exactly and without a clock.
        'driver_confirmed_loaded_qty',
        'short_reason',
        'notes',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => LoadingTaskStatus::class,
            'quantity_planned' => 'float',
            'quantity_loaded' => 'float',
            'quantity_short' => 'float',
            'requires_refrigeration' => 'boolean',
            'loaded_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'driver_received_qty' => 'float',
            'driver_confirmed_at' => 'datetime',
            'driver_confirmed_loaded_qty' => 'float',
        ];
    }

    /**
     * The driver's confirmation is STALE once the warehouse changes the quantity.
     *
     * ┌─ WHY THIS COMPARES A QUANTITY, NOT A TIMESTAMP ──────────────────────┐
     * │ The original rule was `driver_confirmed_at >= confirmed_at`. Two runs  │
     * │ proved it unreliable: both columns were second-precision, and widening │
     * │ them to TIMESTAMP(6) did not help because Eloquent's default           │
     * │ `$dateFormat` writes second-truncated values regardless. A warehouse   │
     * │ revision in the same second therefore compared EQUAL and the stale     │
     * │ confirmation was reported as current — the exact failure the rule      │
     * │ exists to prevent. See migration 2026_08_26_100003.                    │
     * └───────────────────────────────────────────────────────────────────────┘
     *
     * A driver confirms receipt AGAINST a specific warehouse quantity. Comparing that
     * recorded quantity to the current one is exact, needs no clock, and states the
     * real business rule: the number you agreed to has changed, so agree again.
     *
     * NULL fails CLOSED — a confirmation that cannot be verified is treated as needing
     * to be made again, never as silently valid.
     */
    public function isDriverConfirmationCurrent(): bool
    {
        if ($this->driver_confirmed_at === null || $this->driver_confirmed_loaded_qty === null) {
            return false;
        }

        // Compared against the WAREHOUSE quantity the driver agreed to — never against
        // what they received, so a legitimately partial receipt stays confirmed.
        return abs((float) $this->driver_confirmed_loaded_qty - (float) $this->quantity_loaded) <= 0.00005;
    }

    /** @return BelongsTo<LoadingSession, $this> */
    public function loadingSession(): BelongsTo
    {
        return $this->belongsTo(LoadingSession::class, 'loading_session_id');
    }

    /** @return BelongsTo<VehicleAssignment, $this> */
    public function vehicleAssignment(): BelongsTo
    {
        return $this->belongsTo(VehicleAssignment::class, 'vehicle_assignment_id');
    }

    /** @return HasOne<VehicleInventoryItem, $this> */
    public function vehicleInventoryItem(): HasOne
    {
        return $this->hasOne(VehicleInventoryItem::class, 'loading_task_id');
    }
}
