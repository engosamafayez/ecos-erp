<?php

declare(strict_types=1);

namespace Modules\Finance\Expenses\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Finance\Ledger\Domain\Models\Account;

/**
 * An Expense Category — a company-owned mapping from a name ops recognises
 * ("Fuel", "Office Supplies") to the one existing Chart-of-Accounts expense
 * leaf it posts to. No account is created here; every category names an
 * account that already exists.
 */
class ExpenseCategory extends Model
{
    protected $table = 'finance_expense_categories';

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    protected $fillable = [
        'uuid', 'company_id', 'name', 'expense_account_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            if ($category->uuid === null) {
                $category->uuid = (string) Str::uuid();
            }
        });
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
