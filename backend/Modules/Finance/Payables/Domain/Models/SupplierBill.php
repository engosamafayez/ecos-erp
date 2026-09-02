<?php

declare(strict_types=1);

namespace Modules\Finance\Payables\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Enums\SupplierLedgerEntryType;
use Modules\Finance\Shared\Domain\Enums\DocumentStatus;

/**
 * A supplier bill / credit note / debit note (AP subledger document).
 *
 * The mirror of the customer invoice. Posting it asks the Posting Engine to move
 * the AP control account in the GL; AP never writes the ledger. Once posted the
 * document is frozen; settlement is derived from payment allocations.
 */
class SupplierBill extends Model
{
    private const FROZEN_ONCE_POSTED = [
        'company_id', 'supplier_id', 'document_type', 'number',
        'bill_date', 'currency', 'subtotal', 'tax_total', 'total',
        'ap_control_account_id', 'journal_entry_id',
    ];

    protected $table = 'finance_supplier_bills';

    /** @var array<string, mixed> */
    protected $attributes = [
        'document_type' => 'bill',
        'status' => 'draft',
        'currency' => 'EGP',
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected $fillable = [
        'uuid', 'company_id', 'supplier_id', 'document_type', 'number',
        'bill_date', 'due_date', 'currency', 'subtotal', 'tax_total', 'total',
        'status', 'ap_control_account_id', 'journal_entry_id', 'description',
        'created_by', 'approved_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => SupplierDocumentType::class,
            'status' => DocumentStatus::class,
            'bill_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $doc): void {
            if ($doc->uuid === null) {
                $doc->uuid = (string) Str::uuid();
            }
        });

        static::updating(function (self $doc): bool {
            if ($doc->getRawOriginal('status') === DocumentStatus::Posted->value) {
                foreach (self::FROZEN_ONCE_POSTED as $frozen) {
                    if ($doc->isDirty($frozen)) {
                        return false;
                    }
                }
            }

            return true;
        });

        static::deleting(static fn (self $doc): bool => $doc->status === DocumentStatus::Draft);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class, 'supplier_bill_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'supplier_bill_id');
    }

    public function controlAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ap_control_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::Posted;
    }

    public function allocatedAmount(): float
    {
        $fromAllocations = (float) $this->allocations()->sum('amount');

        // Advance settlement (SupplierOpeningBalanceService::applyAdvanceToBill) mirrors this
        // bill's AP reduction as a −ve ledger entry tagged to it, rather than a PaymentAllocation
        // row (an advance is not a cash payment — see that method's docblock). Folding the tagged
        // entries in here is what makes outstanding() reflect an advance settlement without
        // either side needing to know about the other's bookkeeping.
        $fromAdvances = (float) SupplierLedgerEntry::query()
            ->where('source_type', 'advance_settlement')
            ->where('source_id', $this->uuid)
            ->where('entry_type', SupplierLedgerEntryType::Payment->value)
            ->sum('amount');

        return round($fromAllocations - $fromAdvances, 4);
    }

    /** What we still owe on this document — derived from payment allocations. */
    public function outstanding(): float
    {
        $signed = (float) $this->total * ($this->document_type?->payableSign() ?? 1);

        return round($signed - $this->allocatedAmount(), 4);
    }
}
