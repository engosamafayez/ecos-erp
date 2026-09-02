<?php

declare(strict_types=1);

namespace Modules\Finance\CostAllocation\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Finance\CostAllocation\Domain\Enums\CostAllocationMethod;

/**
 * One management-cost allocation — a slice of an already-posted source cost
 * attributed to a destination dimension (Brand). Append-only: a correction is
 * a new, negative-amount row referencing the one it reverses via
 * reverses_allocation_id, never an edit (the same audit principle as
 * PaymentAllocation/ReceiptAllocation — a DIFFERENT table, DIFFERENT engine).
 */
class CostAllocation extends Model
{
    protected $table = 'finance_cost_allocations';

    protected $fillable = [
        'uuid', 'company_id', 'source_type', 'source_id', 'source_amount',
        'method', 'destination_profit_center_id', 'allocated_amount', 'percentage',
        'reverses_allocation_id', 'reversal_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'method' => CostAllocationMethod::class,
            'source_amount' => 'decimal:4',
            'allocated_amount' => 'decimal:4',
            'percentage' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $allocation): void {
            if ($allocation->uuid === null) {
                $allocation->uuid = (string) Str::uuid();
            }
        });

        // Append-only: never updated or deleted once written.
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_allocation_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_allocation_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_allocation_id !== null;
    }
}
