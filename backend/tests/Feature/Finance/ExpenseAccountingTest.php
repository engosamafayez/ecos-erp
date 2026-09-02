<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Finance\Expenses\Domain\Enums\ExpenseStatus;
use Modules\Finance\Expenses\Domain\Models\Expense;
use Modules\Finance\Expenses\Domain\Models\ExpenseCategory;
use Modules\Finance\Expenses\Domain\Services\ExpenseService;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-05 — the
 * canonical Expense capture-and-posting path. Covers TASK §38 items 1-8.
 */
class ExpenseAccountingTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->maker = User::factory()->create(['company_id' => $this->companyId]);
        $this->checker = User::factory()->create(['company_id' => $this->companyId]);
        $this->openPeriodForToday();
    }

    // 1, 5, 6, 7. An approved expense posts once, using the category's
    // account mapping (not a hardcoded id), the chosen cash/bank counterpart,
    // and carries its source linkage when supplied.
    public function test_approved_expense_posts_once_with_mapping_and_linkage(): void
    {
        $category = $this->category('Fuel', '5580'); // Utilities — any real expense leaf
        $funding = $this->cashAccount();

        $expense = app(ExpenseService::class)->createExpense(
            companyId: $this->companyId,
            expenseCategoryId: $category->id,
            number: 'EXP-1001',
            expenseDate: Carbon::today(),
            amount: 250.0,
            fundingAccountId: $funding->id,
            createdBy: (int) $this->maker->id,
            sourceType: 'fleet_cost_entry',
            sourceId: (string) Str::uuid(),
        );

        app(ExpenseService::class)->approveExpense($expense, (int) $this->checker->id);
        $posted = app(ExpenseService::class)->postExpense($expense->fresh());

        $this->assertTrue($posted->isPosted());
        $this->assertSame('fleet_cost_entry', $posted->source_type);
        $this->assertNotNull($posted->journal_entry_id);

        $journal = $posted->journalEntry;
        $debit = $journal->lines->firstWhere('account_id', $category->expense_account_id);
        $credit = $journal->lines->firstWhere('account_id', $funding->id);
        $this->assertSame(250.0, round((float) $debit->debit, 4));
        $this->assertSame(250.0, round((float) $credit->credit, 4));
    }

    // 2 & 3. A draft (unapproved) expense cannot post.
    public function test_draft_expense_cannot_post(): void
    {
        $category = $this->category('Office', '5710');
        $expense = app(ExpenseService::class)->createExpense(
            $this->companyId, $category->id, 'EXP-2002', Carbon::today(), 100.0, $this->cashAccount()->id,
            createdBy: (int) $this->maker->id,
        );

        $this->expectException(FinanceException::class);
        app(ExpenseService::class)->postExpense($expense);
    }

    // 4. Replaying postExpense() on an already-posted expense never
    // double-posts (PostingCoordinator's exactly-once receipt).
    public function test_replayed_post_does_not_duplicate(): void
    {
        $category = $this->category('Rent', '5570');
        $expense = app(ExpenseService::class)->createExpense(
            $this->companyId, $category->id, 'EXP-3003', Carbon::today(), 400.0, $this->cashAccount()->id,
            createdBy: (int) $this->maker->id,
        );
        app(ExpenseService::class)->approveExpense($expense, (int) $this->checker->id);

        $first = app(ExpenseService::class)->postExpense($expense->fresh());

        $this->expectException(FinanceException::class); // documentAlreadyPosted
        app(ExpenseService::class)->postExpense($first);
    }

    // Segregation of duties: the maker cannot approve their own expense.
    public function test_maker_cannot_approve_own_expense(): void
    {
        $category = $this->category('Bank Charges', '5730');
        $expense = app(ExpenseService::class)->createExpense(
            $this->companyId, $category->id, 'EXP-4004', Carbon::today(), 50.0, $this->cashAccount()->id,
            createdBy: (int) $this->maker->id,
        );

        $this->expectException(FinanceException::class);
        app(ExpenseService::class)->approveExpense($expense, (int) $this->maker->id);
    }

    // 8. A foreign-company expense category is rejected (tenant boundary —
    // firstOrFail() scoped by company_id).
    public function test_foreign_company_expense_category_rejected(): void
    {
        $otherCompany = Company::factory()->create();
        $foreignCategory = $this->categoryFor((string) $otherCompany->id, 'Foreign', '5710');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(ExpenseService::class)->createExpense(
            $this->companyId, $foreignCategory->id, 'EXP-5005', Carbon::today(), 50.0, $this->cashAccount()->id,
        );
    }

    // The reversal path — the fourth instance of Task 5's reversal pattern.
    public function test_posted_expense_reversal_uses_canonical_journal_engine(): void
    {
        $category = $this->category('Professional Fees', '5720');
        $expense = app(ExpenseService::class)->createExpense(
            $this->companyId, $category->id, 'EXP-6006', Carbon::today(), 300.0, $this->cashAccount()->id,
            createdBy: (int) $this->maker->id,
        );
        app(ExpenseService::class)->approveExpense($expense, (int) $this->checker->id);
        $posted = app(ExpenseService::class)->postExpense($expense->fresh());
        $originalJournalId = $posted->journal_entry_id;

        $reversal = app(ExpenseService::class)->reverseExpensePosting($posted, 'Entered in error');

        $this->assertSame($originalJournalId, $reversal->reverses_journal_id);
        $posted->refresh();
        $this->assertSame($originalJournalId, $posted->journal_entry_id); // original row frozen
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function category(string $name, string $accountCode): ExpenseCategory
    {
        return $this->categoryFor($this->companyId, $name, $accountCode);
    }

    private function categoryFor(string $companyId, string $name, string $accountCode): ExpenseCategory
    {
        $account = Account::query()->where('company_id', $companyId)->where('code', $accountCode)->first()
            ?? app(ChartOfAccountsService::class)->create([
                'company_id' => $companyId, 'code' => $accountCode, 'name' => $name,
                'account_type' => AccountType::Expense, 'is_postable' => true,
            ]);

        return ExpenseCategory::create([
            'company_id' => $companyId,
            'name' => $name.'-'.$this->suffix(),
            'expense_account_id' => $account->id,
        ]);
    }

    private function cashAccount(): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => 'A-'.$this->suffix(),
            'name' => 'Cash account',
            'account_type' => AccountType::Asset,
            'is_postable' => true,
        ]);
    }

    private function suffix(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    private function openPeriodForToday(): void
    {
        $start = Carbon::today()->subMonths(3)->startOfMonth();
        $year = app(FiscalCalendarService::class)->createYear(
            $this->companyId, 'FY-'.$this->suffix(), $start, $start->copy()->addMonths(11)->endOfMonth(),
        );

        foreach ($year->periods as $period) {
            if ($period->status->value !== 'open') {
                app(FiscalCalendarService::class)->openPeriod($period);
            }
        }
    }
}
