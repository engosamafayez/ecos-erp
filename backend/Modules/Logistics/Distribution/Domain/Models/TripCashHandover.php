<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Treasury's physical receipt of a driver's handed-back cash for one trip
 * settlement (TASK-ECOS-DRIVER-SETTLEMENT-TREASURY-FINAL-IMPLEMENTATION-002).
 * Exactly one per TripSettlement.
 *
 * Append-only, mirroring the canonical idempotency-record convention
 * ({@see \Modules\Finance\Posting\Domain\Models\PostedEventReceipt}): once
 * confirmed, a handover is never edited in place. A repeat confirmation
 * either resolves to this SAME row (identical amount) or is refused (a
 * different amount) — see {@see \Modules\Logistics\Distribution\Domain\Services\CashHandoverService}.
 * `confirmed_at` being non-null IS "confirmed" — the same convention already
 * used by {@see TripReturn::isConfirmed()} (`warehouse_confirmed_at`) and
 * `VehicleShiftReconciliationLine.warehouse_receipt_at` in this codebase.
 *
 * Distinguishes three facts the calling code must never conflate:
 *   - `driver_declared_cash` — snapshot of {@see TripSettlement::$driver_cash_submitted}.
 *   - `expected_cash`        — snapshot of the canonical Net Cash formula, computed
 *     per-trip by {@see \Modules\Logistics\Distribution\Domain\Services\CashHandoverService::expectedCash()}.
 *   - `received_cash`        — the ONLY amount ever posted to Finance.
 */
class TripCashHandover extends Model
{
    protected $table = 'distribution_trip_cash_handovers';

    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'company_id',
        'trip_settlement_id',
        'trip_id',
        'driver_declared_cash',
        'expected_cash',
        'received_cash',
        'difference',
        'cash_account_id',
        'cash_transaction_id',
        'received_by',
        'confirmed_at',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'driver_declared_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'received_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $handover): void {
            if ($handover->uuid === null) {
                $handover->uuid = (string) Str::uuid();
            }
            if ($handover->confirmed_at === null) {
                $handover->confirmed_at = now();
            }
        });

        // Append-only — a confirmed handover is a permanent financial fact. Mirrors
        // PostedEventReceipt::booted() exactly (the same reasoning applies: correcting
        // a wrong confirmation is a Finance reversal, not an in-place edit).
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }

    public function tripSettlement(): BelongsTo
    {
        return $this->belongsTo(TripSettlement::class, 'trip_settlement_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    /** Within a cent of expected — the same 0.01 tolerance TripSettlement::isBalanced() uses. */
    public function isExact(): bool
    {
        return abs((float) $this->difference) < 0.01;
    }

    public function isShort(): bool
    {
        return (float) $this->difference <= -0.01;
    }

    public function isOver(): bool
    {
        return (float) $this->difference >= 0.01;
    }
}
