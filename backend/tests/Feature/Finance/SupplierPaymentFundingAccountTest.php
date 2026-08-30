<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Domain\Services\BankingService;
use Modules\Finance\Cash\Domain\Services\CashService;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Payables\Application\Services\PaySupplierInvoiceService;
use Modules\Finance\Payables\Domain\Enums\PaymentStatus;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Shared\Domain\Services\FundingAccountPolicy;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-FINANCE-AP-FUNDING-ACCOUNT-INTEGRITY-CLOSURE-001 — a supplier payment may
 * only draw on a company-owned account that is a canonical source of funds.
 *
 * The rule lives in the shared {@see FundingAccountPolicy} and is enforced at the
 * single choke point every supplier payment passes through,
 * {@see AccountsPayableService::createPayment()} — so both the generic AP path and
 * the invoice-anchored {@see PaySupplierInvoiceService} inherit it. An eligible
 * funding source is a GL account that backs an active cash or bank account; a
 * revenue/expense/inventory/receivable/payable-control account is refused before
 * any journal is requested, so an ineligible account can never produce a payment.
 */
class SupplierPaymentFundingAccountTest extends TestCase
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
        $this->controlAccount('ap', AccountType::Liability);
    }

    public function test_a_cash_backed_account_is_accepted_as_funding(): void
    {
        $gl = $this->account(AccountType::Asset);
        app(CashService::class)->createAccount($this->companyId, 'TILL-'.$this->suffix(), 'Till', (int) $gl->id);

        $payment = $this->createPayment((int) $gl->id);

        $this->assertSame(PaymentStatus::Draft, $payment->status);
        $this->assertTrue(app(FundingAccountPolicy::class)->isEligible($this->companyId, (int) $gl->id));
    }

    public function test_a_bank_backed_account_is_accepted_as_funding(): void
    {
        $gl = $this->account(AccountType::Asset);
        app(BankingService::class)->createAccount($this->companyId, 'Main', (int) $gl->id);

        $payment = $this->createPayment((int) $gl->id);

        $this->assertSame(PaymentStatus::Draft, $payment->status);
        $this->assertTrue(app(FundingAccountPolicy::class)->isEligible($this->companyId, (int) $gl->id));
    }

    public function test_a_same_company_non_funding_account_is_rejected(): void
    {
        // Each of these belongs to the company but is not a held-cash source.
        $cases = [
            $this->account(AccountType::Revenue),
            $this->account(AccountType::Expense),
            $this->account(AccountType::Asset), // a bare asset GL, NOT backed by cash/bank (e.g. inventory/receivable)
        ];

        foreach ($cases as $account) {
            $this->assertFalse(app(FundingAccountPolicy::class)->isEligible($this->companyId, (int) $account->id));

            try {
                $this->createPayment((int) $account->id);
                $this->fail("Account {$account->code} must not be accepted as a funding source.");
            } catch (FinanceException $e) {
                $this->assertStringContainsString('not an eligible funding source', $e->getMessage());
            }
        }
    }

    public function test_a_funding_account_from_another_company_is_rejected(): void
    {
        $foreignCompany = Company::factory()->create();
        $foreignGl = app(ChartOfAccountsService::class)->create([
            'company_id' => (string) $foreignCompany->id,
            'code' => 'X-'.$this->suffix(),
            'name' => 'Foreign bank',
            'account_type' => AccountType::Asset,
            'is_postable' => true,
        ]);
        // Even though it is a real bank account in its OWN company, it is invisible here.
        app(BankingService::class)->createAccount((string) $foreignCompany->id, 'Foreign', (int) $foreignGl->id);

        $this->assertFalse(app(FundingAccountPolicy::class)->isEligible($this->companyId, (int) $foreignGl->id));
        $this->expectException(FinanceException::class);
        $this->createPayment((int) $foreignGl->id);
    }

    public function test_an_inactive_cash_account_is_not_an_eligible_funding_source(): void
    {
        $gl = $this->account(AccountType::Asset);
        $cash = app(CashService::class)->createAccount($this->companyId, 'OLD-'.$this->suffix(), 'Old till', (int) $gl->id);
        $cash->update(['is_active' => false]);

        $this->assertFalse(app(FundingAccountPolicy::class)->isEligible($this->companyId, (int) $gl->id));
        $this->expectException(FinanceException::class);
        $this->createPayment((int) $gl->id);
    }

    public function test_an_ineligible_funding_account_never_produces_a_payment(): void
    {
        $expense = $this->account(AccountType::Expense);

        try {
            $this->createPayment((int) $expense->id);
            $this->fail('An ineligible funding account must not create a payment.');
        } catch (FinanceException) {
            // expected — the rejection happens before any row is written.
        }

        $this->assertSame(0, SupplierPayment::query()->where('company_id', $this->companyId)->count());
    }

    public function test_pay_supplier_invoice_service_inherits_the_funding_rule(): void
    {
        $supplier = (string) Str::uuid();
        $invoiceId = (string) Str::uuid();
        $expense = $this->account(AccountType::Expense);

        // Establish the canonical payable so the use case gets past payable resolution.
        $bill = app(AccountsPayableService::class)->createDocument(
            companyId: $this->companyId, supplierId: $supplier, number: 'SI-'.$invoiceId,
            documentDate: Carbon::today(), lines: [['expense_account_id' => (int) $expense->id, 'net_amount' => 100.0]],
            type: SupplierDocumentType::Bill, dueDate: Carbon::today(),
        );
        app(AccountsPayableService::class)->postDocument($bill);

        // A same-company non-funding account (the expense account) must be refused
        // through the invoice-anchored path too — it shares the one canonical rule.
        $this->expectException(FinanceException::class);
        app(PaySupplierInvoiceService::class)->initiatePayment(
            companyId: $this->companyId,
            invoiceId: $invoiceId,
            number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(),
            amount: 100.0,
            fundingAccountId: (int) $expense->id,
            createdBy: 1,
        );
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function createPayment(int $fundingAccountId): SupplierPayment
    {
        return app(AccountsPayableService::class)->createPayment(
            companyId: $this->companyId,
            supplierId: (string) Str::uuid(),
            number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(),
            amount: 50.0,
            fundingAccountId: $fundingAccountId,
            createdBy: 1,
        );
    }

    private function suffix(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    private function openPeriodForToday(): FiscalPeriod
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

        return $year->periods()->where('period_number', 1)->firstOrFail();
    }

    private function account(AccountType $type, bool $postable = true): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => strtoupper($type->value[0]).'-'.$this->suffix(),
            'name' => ucfirst($type->value).' account',
            'account_type' => $type,
            'is_postable' => $postable,
        ]);
    }

    private function controlAccount(string $subledger, AccountType $type): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => strtoupper($subledger).'-CTRL-'.$this->suffix(),
            'name' => strtoupper($subledger).' control',
            'account_type' => $type,
            'is_postable' => true,
            'is_control' => true,
            'control_subledger' => $subledger,
        ]);
    }
}
