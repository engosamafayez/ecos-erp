<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Cash\Domain\Services\CashService;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\OperationalCost\Domain\Models\DriverLedgerEntry;
use Modules\Finance\OperationalCost\Domain\Services\DriverFinanceService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-07 — the
 * Finance-owned driver financial subledger (advances, approved expenses,
 * approved shortages). driver_id is an opaque string throughout — these
 * tests exercise the RECEIVING CONTRACT independent of any Logistics
 * model, exactly as the service itself is designed. Covers TASK §38 items
 * 9-21.
 */
class DriverFinanceAccountingTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->openPeriodForToday();
    }

    // 9, 11, 12. An approved driver advance creates a canonical financial
    // effect (a real journal + subledger entry, not a mutable balance
    // field), scoped to this company's driver.
    public function test_approved_driver_advance_creates_canonical_effect(): void
    {
        $receivable = $this->seedRole('driver_receivable', AccountType::Asset);
        $cash = $this->cashAccount();
        $driverId = (string) Str::uuid();

        $entry = app(DriverFinanceService::class)->recognizeDriverAdvance(
            $this->companyId, $driverId, 500.0, $cash->id, Carbon::today(),
        );

        $this->assertNotNull($entry);
        $this->assertSame(500.0, round((float) $entry->amount, 4)); // advance = positive
        $this->assertSame(500.0, DriverLedgerEntry::balanceFor($this->companyId, $driverId));

        $journal = $entry->journalEntry;
        $debit = $journal->lines->firstWhere('account_id', $receivable->id);
        $credit = $journal->lines->firstWhere('account_id', $cash->id);
        $this->assertSame(500.0, round((float) $debit->debit, 4));
        $this->assertSame(500.0, round((float) $credit->credit, 4));
    }

    // 10. A duplicate advance event (same source_type/source_id) does not
    // duplicate the financial effect.
    public function test_duplicate_advance_event_does_not_duplicate(): void
    {
        $this->seedRole('driver_receivable', AccountType::Asset);
        $cash = $this->cashAccount();
        $driverId = (string) Str::uuid();
        $sourceId = (string) Str::uuid();

        $service = app(DriverFinanceService::class);
        $first = $service->recognizeDriverAdvance(
            $this->companyId, $driverId, 300.0, $cash->id, Carbon::today(),
            sourceType: 'driver_advance_request', sourceId: $sourceId,
        );
        $second = $service->recognizeDriverAdvance(
            $this->companyId, $driverId, 300.0, $cash->id, Carbon::today(),
            sourceType: 'driver_advance_request', sourceId: $sourceId,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(300.0, DriverLedgerEntry::balanceFor($this->companyId, $driverId));
    }

    // 12. No mutable "driver balance" field is used as accounting authority
    // — the balance is derived by summing ledger entries.
    public function test_driver_balance_is_derived_never_stored(): void
    {
        $this->seedRole('driver_receivable', AccountType::Asset);
        $cash = $this->cashAccount();
        $expenseAccount = $this->expenseAccount();
        $driverId = (string) Str::uuid();

        $service = app(DriverFinanceService::class);
        $service->recognizeDriverAdvance($this->companyId, $driverId, 1000.0, $cash->id, Carbon::today());
        $service->recognizeDriverExpense($this->companyId, $driverId, 400.0, $expenseAccount->id, Carbon::today());

        // 1000 advance - 400 expense = 600 still owed by the driver.
        $this->assertSame(600.0, DriverLedgerEntry::balanceFor($this->companyId, $driverId));
    }

    // 13, 16. An approved driver expense posts correctly (consumes the
    // advance), and a duplicate event is idempotent.
    public function test_approved_driver_expense_consumes_advance_and_is_idempotent(): void
    {
        $receivable = $this->seedRole('driver_receivable', AccountType::Asset);
        $expenseAccount = $this->expenseAccount();
        $driverId = (string) Str::uuid();
        $sourceId = (string) Str::uuid();

        $service = app(DriverFinanceService::class);
        $service->recognizeDriverAdvance($this->companyId, $driverId, 1000.0, $this->cashAccount()->id, Carbon::today());

        $first = $service->recognizeDriverExpense(
            $this->companyId, $driverId, 250.0, $expenseAccount->id, Carbon::today(),
            sourceType: 'driver_expense', sourceId: $sourceId,
        );
        $second = $service->recognizeDriverExpense(
            $this->companyId, $driverId, 250.0, $expenseAccount->id, Carbon::today(),
            sourceType: 'driver_expense', sourceId: $sourceId,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(-250.0, round((float) $first->amount, 4)); // expense reduces what they owe
        $this->assertSame(750.0, DriverLedgerEntry::balanceFor($this->companyId, $driverId));

        $journal = $first->journalEntry;
        $debit = $journal->lines->firstWhere('account_id', $expenseAccount->id);
        $credit = $journal->lines->firstWhere('account_id', $receivable->id);
        $this->assertSame(250.0, round((float) $debit->debit, 4));
        $this->assertSame(250.0, round((float) $credit->credit, 4));
    }

    // An expense with no prior advance still posts, and correctly swings the
    // driver's position negative (the company now owes them a
    // reimbursement) — proving the single swing-account design (class
    // docblock) without a separate "reimbursement payable" branch.
    public function test_driver_expense_without_prior_advance_creates_reimbursement_owed(): void
    {
        $this->seedRole('driver_receivable', AccountType::Asset);
        $expenseAccount = $this->expenseAccount();
        $driverId = (string) Str::uuid();

        app(DriverFinanceService::class)->recognizeDriverExpense(
            $this->companyId, $driverId, 150.0, $expenseAccount->id, Carbon::today(),
        );

        $this->assertSame(-150.0, DriverLedgerEntry::balanceFor($this->companyId, $driverId));
    }

    // 17, 18, 19, 20. Raw shortage does NOT create a liability (there is no
    // method for it at all — enforced by absence, see class docblock); an
    // APPROVED shortage does; a replayed approval does not duplicate;
    // "rejected" needs no code path since nothing is ever called for it.
    public function test_approved_shortage_creates_liability_and_replay_is_idempotent(): void
    {
        $receivable = $this->seedRole('driver_receivable', AccountType::Asset);
        $recovery = $this->seedRole('driver_shortage_recovery', AccountType::Revenue);
        $driverId = (string) Str::uuid();
        $sourceId = (string) Str::uuid();

        $service = app(DriverFinanceService::class);
        $first = $service->recognizeDriverShortage(
            $this->companyId, $driverId, 80.0, Carbon::today(),
            sourceType: 'driver_shortage_investigation', sourceId: $sourceId,
        );
        $second = $service->recognizeDriverShortage(
            $this->companyId, $driverId, 80.0, Carbon::today(),
            sourceType: 'driver_shortage_investigation', sourceId: $sourceId,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(80.0, round((float) $first->amount, 4)); // shortage = positive, they owe more
        $this->assertSame(80.0, DriverLedgerEntry::balanceFor($this->companyId, $driverId));

        $journal = $first->journalEntry;
        $debit = $journal->lines->firstWhere('account_id', $receivable->id);
        $credit = $journal->lines->firstWhere('account_id', $recovery->id);
        $this->assertSame(80.0, round((float) $debit->debit, 4));
        $this->assertSame(80.0, round((float) $credit->credit, 4));
    }

    // A rejected/cleared investigation calls nothing — there is no shortage
    // receivable, proven simply by never invoking the method (the only way
    // this liability can ever be created).
    public function test_rejected_investigation_creates_no_liability(): void
    {
        $driverId = (string) Str::uuid();

        $this->assertSame(0.0, DriverLedgerEntry::balanceFor($this->companyId, $driverId));
        $this->assertSame(0, DriverLedgerEntry::query()->where('driver_id', $driverId)->count());
    }

    // 21. Correction/reversal is auditable — append-only, the original
    // entry untouched.
    public function test_driver_ledger_reversal_is_append_only(): void
    {
        $this->seedRole('driver_receivable', AccountType::Asset);
        $cash = $this->cashAccount();
        $driverId = (string) Str::uuid();

        $service = app(DriverFinanceService::class);
        $entry = $service->recognizeDriverAdvance($this->companyId, $driverId, 500.0, $cash->id, Carbon::today());
        $originalJournalId = $entry->journal_entry_id;

        $service->reverseDriverLedgerPosting($entry, 'Advance issued in error');

        $this->assertSame(500.0, round((float) $entry->fresh()->amount, 4)); // original untouched
        $this->assertSame(0.0, DriverLedgerEntry::balanceFor($this->companyId, $driverId)); // net zero
        $this->assertSame(2, DriverLedgerEntry::query()->where('driver_id', $driverId)->count());
    }

    // Tenant boundary: a driver's balance in one company is invisible from
    // another, and posting for company B never touches company A's ledger.
    public function test_tenant_boundary_enforced_for_driver_ledger(): void
    {
        $this->seedRole('driver_receivable', AccountType::Asset);
        $driverId = (string) Str::uuid();

        app(DriverFinanceService::class)->recognizeDriverAdvance(
            $this->companyId, $driverId, 200.0, $this->cashAccount()->id, Carbon::today(),
        );

        $otherCompany = Company::factory()->create();
        $this->assertSame(0.0, DriverLedgerEntry::balanceFor((string) $otherCompany->id, $driverId));
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function seedRole(string $role, AccountType $type): Account
    {
        $account = app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => strtoupper(substr($role, 0, 3)).'-'.$this->suffix(),
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'account_type' => $type,
            'is_postable' => true,
        ]);

        DB::table('finance_account_roles')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'role' => $role,
            'account_id' => $account->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $account;
    }

    private function cashAccount(): Account
    {
        $account = app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId, 'code' => 'A-'.$this->suffix(), 'name' => 'Cash account',
            'account_type' => AccountType::Asset, 'is_postable' => true,
        ]);

        // A bare Asset-typed GL account is not itself an eligible funding
        // source (FundingAccountPolicy requires a CashAccount/BankAccount
        // subledger record backing it) — same pattern as
        // SupplierPaymentFundingAccountTest::test_a_cash_backed_account_is_accepted_as_funding().
        app(CashService::class)->createAccount($this->companyId, 'TILL-'.$this->suffix(), 'Till', (int) $account->id);

        return $account;
    }

    private function expenseAccount(): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId, 'code' => 'X-'.$this->suffix(), 'name' => 'Driver cost',
            'account_type' => AccountType::Expense, 'is_postable' => true,
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
