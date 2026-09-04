<?php

declare(strict_types=1);

namespace Modules\Finance\Shared\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A processed Finance command, keyed by (company, command type, idempotency
 * key) — the command-level mirror of
 * {@see \Modules\Finance\Posting\Domain\Models\PostedEventReceipt}, one layer
 * up: that table deduplicates an EVENT reaching the ledger; this one
 * deduplicates an interactive/API COMMAND (e.g. "create this supplier
 * payment") reaching its domain service, before any posting request exists.
 *
 * Write-once: {@see \Modules\Finance\Shared\Domain\Services\CommandIdempotencyGuard}
 * creates a row only after its command has completed, inside the same
 * database transaction — never before, never partially. A failed or
 * lost attempt leaves no row at all, so the key remains freely retryable;
 * there is no "processing" state to expire or reconcile.
 */
class FinanceCommandReceipt extends Model
{
    protected $table = 'finance_command_receipts';

    public $timestamps = false;

    protected $fillable = [
        'uuid', 'company_id', 'command_type', 'idempotency_key',
        'request_fingerprint', 'result_type', 'result_id', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $receipt): void {
            if ($receipt->uuid === null) {
                $receipt->uuid = (string) Str::uuid();
            }
            if ($receipt->created_at === null) {
                $receipt->created_at = now();
            }
        });

        // Write-once, exactly like PostedEventReceipt: nothing ever updates or
        // deletes a command receipt.
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }
}
