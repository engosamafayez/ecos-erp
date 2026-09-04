<?php

declare(strict_types=1);

namespace Modules\Finance\Payables\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An append-only link applying part (or all) of a payment to one bill. The AP
 * mirror of the receipt allocation: partial, full and multiple-bill matching;
 * outstanding is the SUM of these rows, never stored; immutable once written.
 *
 * A correction is never an edit: it is a second, negative-amount row created
 * by {@see \Modules\Finance\Allocation\Domain\Services\AllocationEngine::reversePaymentAllocation()}
 * with `reverses_allocation_id` pointing back at the row it corrects. The
 * effective allocation for any bill/payment is still just SUM(amount) — a
 * reversal nets out automatically, with no change to allocatedAmount() on
 * either side. A row that is itself a reversal can never be reversed again
 * (one-step correction only).
 */
class PaymentAllocation extends Model
{
    protected $table = 'finance_payment_allocations';

    protected $fillable = [
        'uuid', 'company_id', 'payment_id', 'supplier_bill_id',
        'amount', 'allocated_at', 'allocated_by',
        'reverses_allocation_id', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'allocated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $allocation): void {
            if ($allocation->uuid === null) {
                $allocation->uuid = (string) Str::uuid();
            }
        });

        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'payment_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    /** The original allocation this row reverses, if it is a reversal. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_allocation_id');
    }

    /** Reversal rows created against this allocation, if any. */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_allocation_id');
    }
}
