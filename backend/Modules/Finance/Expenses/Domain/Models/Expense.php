<?php

declare(strict_types=1);

namespace Modules\Finance\Expenses\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Finance\Expenses\Domain\Enums\ExpenseStatus;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;

/**
 * A Finance expense document (money out via an operating expense, not a
 * supplier bill). Maker creates the draft; a different checker approves it;
 * only an approved expense may post — the same segregation-of-duties
 * lifecycle as SupplierPayment, since this is the same underlying risk
 * (money leaving the business).
 */
class Expense extends Model
{
    private const FROZEN_ONCE_POSTED = [
        'company_id', 'expense_category_id', 'number', 'expense_date', 'amount',
        'currency', 'funding_account_id', 'journal_entry_id',
    ];

    protected $table = 'finance_expenses';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'EGP',
    ];

    protected $fillable = [
        'uuid', 'company_id', 'expense_category_id', 'number', 'expense_date', 'amount',
        'currency', 'funding_account_id', 'journal_entry_id', 'status',
        'source_type', 'source_id', 'branch_id', 'cost_center_id', 'profit_center_id',
        'description', 'created_by', 'approved_by', 'approved_at', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'expense_date' => 'date',
            'amount' => 'decimal:4',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $expense): void {
            if ($expense->uuid === null) {
                $expense->uuid = (string) Str::uuid();
            }
        });

        static::updating(function (self $expense): bool {
            if ($expense->getRawOriginal('status') === ExpenseStatus::Posted->value) {
                foreach (self::FROZEN_ONCE_POSTED as $frozen) {
                    if ($expense->isDirty($frozen)) {
                        return false;
                    }
                }
            }

            return true;
        });

        static::deleting(static fn (self $expense): bool => $expense->status === ExpenseStatus::Draft);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function fundingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'funding_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function isApproved(): bool
    {
        return $this->status === ExpenseStatus::Approved;
    }

    public function isPosted(): bool
    {
        return $this->status === ExpenseStatus::Posted;
    }
}
