<?php

declare(strict_types=1);

namespace Modules\Finance\Expenses\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Expenses\Domain\Enums\ExpenseStatus;
use Modules\Finance\Expenses\Domain\Models\Expense;
use Modules\Finance\Expenses\Domain\Models\ExpenseCategory;
use Modules\Finance\Ledger\Domain\Enums\JournalType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\JournalEngine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingLine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingRequest;
use Modules\Finance\Posting\Domain\Services\PostingCoordinator;
use Modules\Finance\Shared\Domain\Services\FundingAccountPolicy;

/**
 * Finance Expenses (TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007,
 * FIN-EXEC-05) — the canonical capture-and-posting path Task 5 found
 * genuinely missing anywhere in this codebase. No other domain owns
 * operational expense approval, so Finance owns this end to end (maker,
 * checker, poster) — the exact AccountsPayableService::createPayment /
 * approvePayment / postPayment shape, reused because the underlying risk is
 * identical: money leaving the business.
 */
final class ExpenseService
{
    private const SOURCE_MODULE = 'finance.expense';

    public function __construct(
        private readonly PostingCoordinator $coordinator,
        private readonly JournalEngine $journalEngine,
        private readonly FundingAccountPolicy $fundingAccounts,
    ) {}

    /** Maker: create a draft expense. */
    public function createExpense(
        string $companyId,
        int $expenseCategoryId,
        string $number,
        Carbon $expenseDate,
        float $amount,
        int $fundingAccountId,
        string $currency = 'EGP',
        ?string $description = null,
        ?int $createdBy = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $branchId = null,
        ?int $costCenterId = null,
        ?string $profitCenterId = null,
    ): Expense {
        // Same canonical funding-eligibility gate AccountsPayableService uses —
        // money must draw on a real cash/bank/payable source, never an
        // arbitrary GL node.
        $this->fundingAccounts->assertEligible($companyId, $fundingAccountId);

        $category = ExpenseCategory::query()
            ->where('company_id', $companyId)
            ->whereKey($expenseCategoryId)
            ->firstOrFail();

        return Expense::create([
            'company_id' => $companyId,
            'expense_category_id' => $category->id,
            'number' => $number,
            'expense_date' => $expenseDate->toDateString(),
            'amount' => round($amount, 4),
            'funding_account_id' => $fundingAccountId,
            'currency' => $currency,
            'description' => $description,
            'created_by' => $createdBy,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'branch_id' => $branchId,
            'cost_center_id' => $costCenterId,
            'profit_center_id' => $profitCenterId,
        ]);
    }

    /** Checker: approve a draft expense (must differ from the maker). */
    public function approveExpense(Expense $expense, int $approverId): Expense
    {
        if ($expense->status === ExpenseStatus::Approved) {
            throw FinanceException::expenseAlreadyApproved($expense->number);
        }
        if ($expense->status !== ExpenseStatus::Draft) {
            throw FinanceException::documentVoided('Expense', $expense->number);
        }
        if ((int) $expense->created_by === $approverId) {
            throw FinanceException::expenseApproverCannotBeMaker();
        }

        $expense->update([
            'status' => ExpenseStatus::Approved->value,
            'approved_by' => $approverId,
            'approved_at' => Carbon::now(),
        ]);

        return $expense->refresh();
    }

    /**
     * Post an APPROVED expense: Dr the category's expense account, Cr the
     * funding account. Idempotent through PostingCoordinator — a retry
     * returns the same journal, never a second posting.
     */
    public function postExpense(Expense $expense, ?int $actorId = null): Expense
    {
        if ($expense->status === ExpenseStatus::Posted) {
            throw FinanceException::documentAlreadyPosted('Expense', $expense->number);
        }
        if ($expense->status === ExpenseStatus::Void) {
            throw FinanceException::documentVoided('Expense', $expense->number);
        }
        if ($expense->status !== ExpenseStatus::Approved) {
            throw FinanceException::expenseNotApproved($expense->number);
        }

        $expense->loadMissing('category');
        $amount = round((float) $expense->amount, 4);

        $request = new PostingRequest(
            companyId: $expense->company_id,
            entryDate: Carbon::parse($expense->expense_date),
            lines: [
                PostingLine::debit(
                    (int) $expense->category->expense_account_id,
                    $amount,
                    $expense->company_id,
                    [
                        'branchId' => $expense->branch_id,
                        'costCenterId' => $expense->cost_center_id,
                        'profitCenterId' => $expense->profit_center_id,
                        'description' => $expense->description,
                    ],
                ),
                PostingLine::credit((int) $expense->funding_account_id, $amount, $expense->company_id),
            ],
            reference: $expense->number,
            description: 'Expense '.$expense->number,
            source: 'posting',
            sourceModule: self::SOURCE_MODULE,
            sourceEventId: 'expense:'.$expense->uuid,
            journalType: JournalType::General->value,
        );

        return DB::transaction(function () use ($expense, $request, $actorId): Expense {
            $journal = $this->coordinator->post(self::SOURCE_MODULE, 'expense:'.$expense->uuid, $request, null, $actorId);

            $expense->update([
                'status' => ExpenseStatus::Posted->value,
                'journal_entry_id' => $journal->id,
                'posted_at' => Carbon::now(),
            ]);

            return $expense->refresh();
        });
    }

    /**
     * Reverse a posted expense's journal — the fourth instance of Task 5's
     * reversal pattern (reversePaymentPosting / reverseReceiptPosting /
     * reverseDocumentPosting). Expense has no separate ledger-entry
     * subledger to mirror (unlike AR/AP, it carries no running customer/
     * supplier balance) — reversing its journal via the unchanged
     * JournalEngine::reverse() is the complete correction.
     */
    public function reverseExpensePosting(Expense $expense, string $reason, ?int $actorId = null): JournalEntry
    {
        if ($expense->journal_entry_id === null) {
            throw FinanceException::documentNotPosted('Expense', $expense->number);
        }

        return DB::transaction(function () use ($expense, $reason, $actorId): JournalEntry {
            $journal = JournalEntry::query()->whereKey($expense->journal_entry_id)->firstOrFail();

            return $this->journalEngine->reverse($journal, $reason, $actorId);
        });
    }
}
