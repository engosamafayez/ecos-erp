<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Finance\CostAllocation\Domain\Enums\CostAllocationMethod;
use Modules\Finance\CostAllocation\Domain\Services\CostAllocationService;
use Modules\Finance\Expenses\Domain\Models\Expense;
use Modules\Finance\Expenses\Domain\Models\ExpenseCategory;
use Modules\Finance\Expenses\Domain\Services\ExpenseService;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-06 — Cost
 * Allocation, a management-dimension-only engine distinct from AP/AR's
 * AllocationEngine. Covers TASK §38 items 31-35, 37.
 */
class CostAllocationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    private User $maker;

    private User $checker;

    private Expense $postedExpense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->maker = User::factory()->create(['company_id' => $this->companyId]);
        $this->checker = User::factory()->create(['company_id' => $this->companyId]);
        $this->openPeriodForToday();
        $this->postedExpense = $this->postedExpense(1000.0);
    }

    // 31 & 32. An allocation is created against a valid, posted source cost,
    // and the destination profit-center dimension is carried through.
    public function test_allocation_created_against_valid_posted_expense(): void
    {
        $brandA = (string) Str::uuid();

        $rows = app(CostAllocationService::class)->allocate(
            $this->postedExpense,
            CostAllocationMethod::Fixed,
            [['profit_center_id' => $brandA, 'amount' => 400.0]],
        );

        $this->assertCount(1, $rows);
        $this->assertSame($brandA, $rows[0]->destination_profit_center_id);
        $this->assertSame(400.0, round((float) $rows[0]->allocated_amount, 4));
        $this->assertSame('expense', $rows[0]->source_type);
        $this->assertSame($this->postedExpense->uuid, $rows[0]->source_id);
    }

    // Percentage method derives the amount from the source's own amount.
    public function test_percentage_method_derives_amount_from_source(): void
    {
        $rows = app(CostAllocationService::class)->allocate(
            $this->postedExpense,
            CostAllocationMethod::Percentage,
            [
                ['profit_center_id' => (string) Str::uuid(), 'percentage' => 60.0],
                ['profit_center_id' => (string) Str::uuid(), 'percentage' => 40.0],
            ],
        );

        $this->assertSame(600.0, round((float) $rows[0]->allocated_amount, 4));
        $this->assertSame(400.0, round((float) $rows[1]->allocated_amount, 4));
    }

    // 33 & 34. The sum of allocated amounts cannot exceed the source's
    // amount — enforced across SEPARATE calls (the effective-allocated
    // check reads all prior non-reversed rows, so a second call cannot
    // silently over-allocate what the first call already committed).
    public function test_total_allocation_cannot_exceed_source_amount(): void
    {
        app(CostAllocationService::class)->allocate(
            $this->postedExpense, CostAllocationMethod::Fixed,
            [['profit_center_id' => (string) Str::uuid(), 'amount' => 700.0]],
        );

        $this->expectException(FinanceException::class);

        app(CostAllocationService::class)->allocate(
            $this->postedExpense, CostAllocationMethod::Fixed,
            [['profit_center_id' => (string) Str::uuid(), 'amount' => 400.0]], // 700 + 400 > 1000
        );
    }

    // 35. Correction is append-only: reversing an allocation writes a new,
    // negative row and never edits the original.
    public function test_allocation_reversal_is_append_only(): void
    {
        $rows = app(CostAllocationService::class)->allocate(
            $this->postedExpense, CostAllocationMethod::Fixed,
            [['profit_center_id' => (string) Str::uuid(), 'amount' => 500.0]],
        );
        $original = $rows[0];

        $reversal = app(CostAllocationService::class)->reverseAllocation($original, 'Wrong brand');

        $this->assertSame($original->id, $reversal->reverses_allocation_id);
        $this->assertSame(-500.0, round((float) $reversal->allocated_amount, 4));
        $this->assertSame(500.0, round((float) $original->fresh()->allocated_amount, 4)); // untouched

        // Net effective allocation is now 0 — a fresh allocation up to the
        // full source amount is possible again.
        $this->assertSame(0.0, app(CostAllocationService::class)->effectiveAllocatedAmount($this->companyId, $this->postedExpense->uuid));
    }

    // A reversal cannot itself be reversed (mirrors AllocationEngine's own
    // one-step-correction rule, structurally, not by reusing its code).
    public function test_reversal_cannot_itself_be_reversed(): void
    {
        $rows = app(CostAllocationService::class)->allocate(
            $this->postedExpense, CostAllocationMethod::Fixed,
            [['profit_center_id' => (string) Str::uuid(), 'amount' => 200.0]],
        );
        $reversal = app(CostAllocationService::class)->reverseAllocation($rows[0], 'Correction');

        $this->expectException(FinanceException::class);
        app(CostAllocationService::class)->reverseAllocation($reversal, 'Cannot reverse a reversal');
    }

    // 37. No GL reclassification journal is created by an allocation — pure
    // management-dimension enrichment (see CostAllocationService's own
    // docblock for why: the source expense already posted once).
    public function test_allocation_creates_no_new_journal_entry(): void
    {
        $countBefore = JournalEntry::query()->where('company_id', $this->companyId)->count();

        app(CostAllocationService::class)->allocate(
            $this->postedExpense, CostAllocationMethod::Fixed,
            [['profit_center_id' => (string) Str::uuid(), 'amount' => 300.0]],
        );

        $this->assertSame($countBefore, JournalEntry::query()->where('company_id', $this->companyId)->count());
    }

    // An unposted expense cannot be a source (a draft has no committed cost
    // to allocate a portion of).
    public function test_unposted_expense_cannot_be_allocated(): void
    {
        $category = $this->categoryFor($this->companyId, 'Draft Category', '5710');
        $draft = app(ExpenseService::class)->createExpense(
            $this->companyId, $category->id, 'EXP-DRAFT-1', Carbon::today(), 100.0, $this->cashAccount()->id,
        );

        $this->expectException(FinanceException::class);
        app(CostAllocationService::class)->allocate(
            $draft, CostAllocationMethod::Fixed, [['profit_center_id' => (string) Str::uuid(), 'amount' => 50.0]],
        );
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function postedExpense(float $amount): Expense
    {
        $category = $this->categoryFor($this->companyId, 'Shared Services', '5710');
        $expense = app(ExpenseService::class)->createExpense(
            $this->companyId, $category->id, 'EXP-ALLOC-'.$this->suffix(), Carbon::today(), $amount,
            $this->cashAccount()->id, createdBy: (int) $this->maker->id,
        );
        app(ExpenseService::class)->approveExpense($expense, (int) $this->checker->id);

        return app(ExpenseService::class)->postExpense($expense->fresh());
    }

    private function categoryFor(string $companyId, string $name, string $accountCode): ExpenseCategory
    {
        $account = Account::query()->where('company_id', $companyId)->where('code', $accountCode)->first()
            ?? app(ChartOfAccountsService::class)->create([
                'company_id' => $companyId, 'code' => $accountCode, 'name' => $name,
                'account_type' => AccountType::Expense, 'is_postable' => true,
            ]);

        return ExpenseCategory::create([
            'company_id' => $companyId, 'name' => $name.'-'.$this->suffix(), 'expense_account_id' => $account->id,
        ]);
    }

    private function cashAccount(): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId, 'code' => 'A-'.$this->suffix(), 'name' => 'Cash account',
            'account_type' => AccountType::Asset, 'is_postable' => true,
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
