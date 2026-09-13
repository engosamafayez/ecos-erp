<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Cash\Domain\Models\CashTransaction;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Infrastructure\Database\Seeders\AccountRoleSeeder;
use Modules\Finance\Integration\Application\Listeners\PostPayrollLiabilityOnCompensationApproved;
use Modules\Finance\Integration\Domain\Services\AccountRoleResolver;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Posting\Domain\Models\PostingRule;
use Modules\Finance\Shared\Domain\Services\CompanyFinanceProvisioner;
use Modules\Hr\Compensation\Domain\Events\CompensationApproved;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FIN-03-PAYROLL-FINANCE-POSTING-CLOSURE-001, remediated by
 * TASK-ECOS-FIN-03-EMPLOYEE-ADVANCE-ACCOUNTING-INTEGRITY-001 — approved
 * payroll posting through the existing rule-driven bridge, with advance
 * recovery blocked until a Finance-side advance-disbursement authority
 * exists (see the listener's own docblock). QUEUE_CONNECTION=sync makes the
 * async post inline in this suite, the same convention FleetCostAccountingTest
 * already uses.
 *
 * Written for later consolidated execution — not run by this task (would
 * require a live connection to the shared test database).
 */
class PayrollFinanceIntegrationTest extends TestCase
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

    // 1, 8. A run with no advance recovery posts one balanced journal: salary
    // + commission expense debited, net payable / deductions credited.
    public function test_approved_payroll_with_no_advances_posts_expected_journal(): void
    {
        $roles = $this->seedPayrollRoles();
        $event = $this->approvedEvent(runId: (string) Str::uuid());

        app(PostPayrollLiabilityOnCompensationApproved::class)->handle($event);

        $journal = JournalEntry::query()
            ->where('source_module', 'hr.payroll')
            ->where('source_event_id', 'payroll_run:'.$event->payrollRunId)
            ->with('lines')->first();

        $this->assertNotNull($journal);
        $this->assertSame($this->companyId, (string) $journal->company_id);

        $lines = $journal->lines;
        $this->assertSame(3000.0, round((float) $lines->firstWhere('account_id', $roles['salaries_expense']->id)?->debit, 4));
        $this->assertSame(200.0, round((float) $lines->firstWhere('account_id', $roles['commission_expense']->id)?->debit, 4));
        $this->assertSame(2900.0, round((float) $lines->firstWhere('account_id', $roles['salaries_payable']->id)?->credit, 4));
        $this->assertSame(300.0, round((float) $lines->firstWhere('account_id', $roles['employee_deductions_payable']->id)?->credit, 4));
        // No advance recovery in this run — the receivable role is never touched.
        $this->assertNull($lines->firstWhere('account_id', $roles['employee_advance_receivable']->id));

        $totalDebit = $lines->sum(fn ($l) => (float) $l->debit);
        $totalCredit = $lines->sum(fn ($l) => (float) $l->credit);
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.0001);
    }

    // 2, 7. Advance recovery cannot create an unrecognised credit to
    // employee_advance_receivable: a run with recovered advances posts NO
    // journal at all today (see listener docblock — HR's own Advance
    // migration states Finance was always meant to own disbursement, and
    // never built it). This is the historical/unrecognised-advance case
    // too: there is no distinction Finance can draw between an old and a
    // new advance, so all of them are treated the same, safely.
    public function test_advance_recovery_is_blocked_when_no_receivable_recognized(): void
    {
        $roles = $this->seedPayrollRoles();
        $event = $this->blockedAdvanceEvent(runId: (string) Str::uuid());

        app(PostPayrollLiabilityOnCompensationApproved::class)->handle($event);

        $this->assertSame(
            0,
            JournalEntry::query()->where('source_event_id', 'payroll_run:'.$event->payrollRunId)->count(),
        );
        // No line anywhere in the company's ledger credits the receivable for
        // this run — not a partial or malformed entry, no entry at all.
        $this->assertSame(
            0,
            DB::table('finance_journal_lines')->where('account_id', $roles['employee_advance_receivable']->id)->count(),
        );
    }

    // 5. The advance-recovery block is idempotent — repeated handling of the
    // same blocked event never posts partially or accumulates side effects.
    public function test_advance_recovery_block_is_idempotent(): void
    {
        $roles = $this->seedPayrollRoles();
        $event = $this->blockedAdvanceEvent(runId: (string) Str::uuid());

        $handler = app(PostPayrollLiabilityOnCompensationApproved::class);
        $handler->handle($event);
        $handler->handle($event);

        $this->assertSame(0, JournalEntry::query()->where('source_event_id', 'payroll_run:'.$event->payrollRunId)->count());
        $this->assertSame(0, DB::table('finance_journal_lines')->where('account_id', $roles['employee_advance_receivable']->id)->count());
    }

    // 6. Two independent runs are evaluated independently: one run's
    // recovered advances never affects whether a different run (even for
    // the same company) posts.
    public function test_each_run_is_evaluated_independently_for_the_advance_guard(): void
    {
        $this->seedPayrollRoles();
        $clean = $this->approvedEvent(runId: (string) Str::uuid());
        $blocked = $this->blockedAdvanceEvent(runId: (string) Str::uuid());

        $handler = app(PostPayrollLiabilityOnCompensationApproved::class);
        $handler->handle($clean);
        $handler->handle($blocked);

        $this->assertSame(1, JournalEntry::query()->where('source_event_id', 'payroll_run:'.$clean->payrollRunId)->count());
        $this->assertSame(0, JournalEntry::query()->where('source_event_id', 'payroll_run:'.$blocked->payrollRunId)->count());
    }

    // 3. There is no duplicated JournalEntry when the same clean event is
    // redelivered (queue at-least-once, or any caller invoking handle() twice).
    public function test_duplicate_event_does_not_duplicate_journal(): void
    {
        $this->seedPayrollRoles();
        $event = $this->approvedEvent(runId: (string) Str::uuid());

        $handler = app(PostPayrollLiabilityOnCompensationApproved::class);
        $handler->handle($event);
        $handler->handle($event);

        $this->assertSame(
            1,
            JournalEntry::query()->where('source_module', 'hr.payroll')
                ->where('source_event_id', 'payroll_run:'.$event->payrollRunId)->count(),
        );
    }

    // A run with nothing approved (zero gross) posts nothing — guards the
    // degenerate/empty-run case rather than posting an empty or guessed entry.
    public function test_zero_gross_run_posts_nothing(): void
    {
        $this->seedPayrollRoles();
        $event = $this->approvedEvent(runId: (string) Str::uuid(), employees: [], totals: [
            'gross' => 0.0, 'net' => 0.0, 'deductions' => 0.0, 'advances' => 0.0,
        ]);

        app(PostPayrollLiabilityOnCompensationApproved::class)->handle($event);

        $this->assertSame(
            0,
            JournalEntry::query()->where('source_event_id', 'payroll_run:'.$event->payrollRunId)->count(),
        );
    }

    // 4. Posting is scoped to the company the event names — never a default,
    // never another tenant's ledger.
    public function test_posting_is_scoped_to_company(): void
    {
        $this->seedPayrollRoles();
        $event = $this->approvedEvent(runId: (string) Str::uuid());

        app(PostPayrollLiabilityOnCompensationApproved::class)->handle($event);

        $journal = JournalEntry::query()->where('source_event_id', 'payroll_run:'.$event->payrollRunId)->first();
        $this->assertSame($this->companyId, (string) $journal->company_id);
        $this->assertTrue($journal->lines->every(fn ($l) => (string) $l->company_id === $this->companyId));
    }

    // Deductions post exactly once, at the run's own total — there is no
    // separate subscription to Deduction approval, so there is no path to
    // double-post it.
    public function test_deductions_post_exactly_once_at_run_totals(): void
    {
        $roles = $this->seedPayrollRoles();
        $event = $this->approvedEvent(runId: (string) Str::uuid());

        app(PostPayrollLiabilityOnCompensationApproved::class)->handle($event);

        $journal = JournalEntry::query()->where('source_event_id', 'payroll_run:'.$event->payrollRunId)
            ->with('lines')->first();

        $deductionLines = $journal->lines->where('account_id', $roles['employee_deductions_payable']->id);
        $this->assertCount(1, $deductionLines);
        $this->assertSame(300.0, round((float) $deductionLines->first()->credit, 4));
    }

    // 8. Salary and commission are mutually exclusive components of the same
    // frozen gross_salary fact (HR's own gross = basic + bonus + commission),
    // summed across employees — proving no double-count: salaries (basic+
    // bonus) + commission always equals the run's total gross.
    public function test_salary_and_commission_split_does_not_double_count_gross(): void
    {
        $roles = $this->seedPayrollRoles();
        $event = $this->approvedEvent(runId: (string) Str::uuid());

        app(PostPayrollLiabilityOnCompensationApproved::class)->handle($event);

        $journal = JournalEntry::query()->where('source_event_id', 'payroll_run:'.$event->payrollRunId)
            ->with('lines')->first();

        $salariesDebit = (float) $journal->lines->firstWhere('account_id', $roles['salaries_expense']->id)?->debit;
        $commissionDebit = (float) $journal->lines->firstWhere('account_id', $roles['commission_expense']->id)?->debit;

        $this->assertEqualsWithDelta($event->totalGross, $salariesDebit + $commissionDebit, 0.0001);
    }

    // 11. No financial overtime is introduced — the posting rule's legs are
    // exactly the roles this task defines, none of them overtime.
    public function test_no_overtime_role_in_posting_rule_or_journal(): void
    {
        $roles = $this->seedPayrollRoles();
        $event = $this->approvedEvent(runId: (string) Str::uuid());

        app(PostPayrollLiabilityOnCompensationApproved::class)->handle($event);

        $rule = PostingRule::query()->where('code', 'hr.payroll_approved')->whereNull('company_id')->firstOrFail();
        foreach ($rule->legs as $leg) {
            $this->assertStringNotContainsStringIgnoringCase('overtime', (string) $leg['role']);
        }

        $journal = JournalEntry::query()->where('source_event_id', 'payroll_run:'.$event->payrollRunId)
            ->with('lines.account')->first();
        foreach ($journal->lines as $line) {
            $this->assertStringNotContainsStringIgnoringCase('overtime', (string) $line->account->name);
        }
    }

    // 10. Approving payroll recognises a liability, never a payment: no Cash
    // account is touched, and what is credited is the liability role.
    public function test_approval_does_not_post_a_cash_payment(): void
    {
        $roles = $this->seedPayrollRoles();
        $event = $this->approvedEvent(runId: (string) Str::uuid());

        app(PostPayrollLiabilityOnCompensationApproved::class)->handle($event);

        $this->assertSame(0, CashTransaction::query()->where('company_id', $this->companyId)->count());
        $this->assertSame(AccountType::Liability, $roles['salaries_payable']->account_type);
    }

    // A freshly provisioned company receives all five payroll roles through
    // the canonical, unchanged provisioning path — no bespoke payroll seeder.
    public function test_new_company_provisioning_includes_payroll_roles(): void
    {
        $fresh = Company::factory()->create();
        app(CompanyFinanceProvisioner::class)->provision((string) $fresh->id);

        $resolver = app(AccountRoleResolver::class);
        foreach (['salaries_expense', 'commission_expense', 'salaries_payable', 'employee_deductions_payable', 'employee_advance_receivable'] as $role) {
            $this->assertTrue($resolver->isMapped((string) $fresh->id, $role), "role {$role} not mapped for a freshly provisioned company");
        }
    }

    // Backfilling an existing company (re-running the seeder — the approved
    // additive pattern, e.g. `finance:provision-companies`) fills only what
    // is missing and never overwrites a company's own override.
    public function test_backfill_is_idempotent_and_preserves_existing_override(): void
    {
        app(CompanyFinanceProvisioner::class)->provision($this->companyId);

        $customAccount = app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => 'CUST-'.$this->suffix(),
            'name' => 'Custom Salaries Payable Override',
            'account_type' => AccountType::Liability,
            'is_postable' => true,
        ]);

        DB::table('finance_account_roles')
            ->where('company_id', $this->companyId)
            ->where('role', 'salaries_payable')
            ->update(['account_id' => $customAccount->id]);

        (new AccountRoleSeeder)->seedCompany($this->companyId);
        (new AccountRoleSeeder)->seedCompany($this->companyId); // run twice — idempotent

        $mapping = DB::table('finance_account_roles')
            ->where('company_id', $this->companyId)->where('role', 'salaries_payable')->first();

        $this->assertSame((int) $customAccount->id, (int) $mapping->account_id);
        $this->assertSame(
            1,
            DB::table('finance_account_roles')->where('company_id', $this->companyId)
                ->where('role', 'salaries_payable')->count(),
        );
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    /**
     * A run of two employees with no advance recovery, each with a different
     * mix of basic/bonus/commission/deduction — chosen so no two employees'
     * figures are equal, which would hide a bug that summed the wrong column.
     * Totals: salaries (basic+bonus) 3000, commission 200 → gross 3200; net
     * 2900 + deductions 300 (+ advances 0) = 3200, matching HR's own net
     * formula. Callers that need advance recovery pass an explicit override.
     */
    private function approvedEvent(string $runId, ?array $employees = null, ?array $totals = null): CompensationApproved
    {
        $employees ??= [
            [
                'employee_id' => (string) Str::uuid(), 'employee_number' => 'E-001',
                'basic_salary' => 2000.0, 'bonus_total' => 500.0, 'commission_total' => 150.0,
                'advance_total' => 0.0, 'deduction_total' => 100.0,
                'gross_salary' => 2650.0, 'net_salary' => 2550.0,
            ],
            [
                'employee_id' => (string) Str::uuid(), 'employee_number' => 'E-002',
                'basic_salary' => 500.0, 'bonus_total' => 0.0, 'commission_total' => 50.0,
                'advance_total' => 0.0, 'deduction_total' => 200.0,
                'gross_salary' => 550.0, 'net_salary' => 350.0,
            ],
        ];
        $totals ??= ['gross' => 3200.0, 'net' => 2900.0, 'deductions' => 300.0, 'advances' => 0.0];

        return new CompensationApproved(
            companyId: $this->companyId,
            payrollRunId: $runId,
            payrollPeriodId: (string) Str::uuid(),
            periodCode: '2026-01',
            periodStart: '2026-01-01',
            periodEnd: '2026-01-31',
            totalGross: $totals['gross'],
            totalNet: $totals['net'],
            totalDeductions: $totals['deductions'],
            totalAdvances: $totals['advances'],
            currency: 'EGP',
            employees: $employees,
            approvedAt: Carbon::now(),
            approvedBy: 1,
        );
    }

    /**
     * A run of the same two employees as {@see approvedEvent()}'s default,
     * but with advance recovery this time (200 + 100 = 300) — the fixture
     * every "blocked" test shares, so the three of them can never silently
     * drift out of sync with each other.
     */
    private function blockedAdvanceEvent(string $runId): CompensationApproved
    {
        return $this->approvedEvent(
            runId: $runId,
            employees: [
                [
                    'employee_id' => (string) Str::uuid(), 'employee_number' => 'E-001',
                    'basic_salary' => 2000.0, 'bonus_total' => 500.0, 'commission_total' => 150.0,
                    'advance_total' => 200.0, 'deduction_total' => 100.0,
                    'gross_salary' => 2650.0, 'net_salary' => 2350.0,
                ],
                [
                    'employee_id' => (string) Str::uuid(), 'employee_number' => 'E-002',
                    'basic_salary' => 500.0, 'bonus_total' => 0.0, 'commission_total' => 50.0,
                    'advance_total' => 100.0, 'deduction_total' => 200.0,
                    'gross_salary' => 550.0, 'net_salary' => 250.0,
                ],
            ],
            totals: ['gross' => 3200.0, 'net' => 2600.0, 'deductions' => 300.0, 'advances' => 300.0],
        );
    }

    /** @return array<string, Account> role => seeded Account, keyed for assertions */
    private function seedPayrollRoles(): array
    {
        return [
            'salaries_expense' => $this->seedRole('salaries_expense', AccountType::Expense),
            'commission_expense' => $this->seedRole('commission_expense', AccountType::Expense),
            'salaries_payable' => $this->seedRole('salaries_payable', AccountType::Liability),
            'employee_deductions_payable' => $this->seedRole('employee_deductions_payable', AccountType::Liability),
            'employee_advance_receivable' => $this->seedRole('employee_advance_receivable', AccountType::Asset),
        ];
    }

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
