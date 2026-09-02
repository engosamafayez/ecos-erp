<?php

declare(strict_types=1);

namespace Modules\Finance\OperationalCost\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\OperationalCost\Domain\Enums\DriverLedgerEntryType;

/**
 * One entry on a driver's financial subledger (advance / expense / shortage
 * / settlement). Append-only, like every other party ledger in this
 * codebase — a driver's balance is SUM(amount), reconciling to the
 * "Employee Receivables" (1320) account via the driver_receivable role;
 * corrections are new entries, never edits.
 */
class DriverLedgerEntry extends Model
{
    protected $table = 'finance_driver_ledger_entries';

    protected $fillable = [
        'uuid', 'company_id', 'driver_id', 'entry_date', 'entry_type',
        'amount', 'source_type', 'source_id', 'journal_entry_id', 'description',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => DriverLedgerEntryType::class,
            'entry_date' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if ($entry->uuid === null) {
                $entry->uuid = (string) Str::uuid();
            }
        });

        // Append-only — never updated or deleted once written.
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** A driver's running balance — what they owe the company (negative = company owes driver). */
    public static function balanceFor(string $companyId, string $driverId): float
    {
        return round((float) self::query()
            ->where('company_id', $companyId)
            ->where('driver_id', $driverId)
            ->sum('amount'), 4);
    }
}
