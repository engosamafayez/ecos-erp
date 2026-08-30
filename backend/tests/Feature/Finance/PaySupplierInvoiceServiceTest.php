<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Domain\Services\BankingService;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Payables\Application\Services\PaySupplierInvoiceService;
use Modules\Finance\Payables\Domain\Enums\PaymentStatus;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-FINANCE-GAP-CLOSURE-SUPPLIER-AP-PAYMENT-WRITE-001 — the invoice-anchored
 * "Pay Supplier Invoice" orchestration.
 *
 * These protect the ONE thing the use case adds over the raw AP authorities:
 * it resolves a supplier invoice's canonical payable ('SI-'.<invoice id>), pays
 * it end-to-end through the existing authorities, and refuses rather than
 * fabricate when the payable is absent — all WITHOUT weakening the maker/checker
 * identity gate. Paid/Remaining stay derived from the immutable allocation.
 */
class PaySupplierInvoiceServiceTest extends TestCase
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

    public function test_it_pays_a_supplier_invoices_payable_end_to_end_and_moves_paid_remaining(): void
    {
        $supplier = (string) Str::uuid();
        $invoiceId = (string) Str::uuid();
        $this->controlAccount('ap', AccountType::Liability);
        $expense = $this->account(AccountType::Expense);
        $bank = $this->fundingAccount();

        $bill = $this->postedInvoicePayable($invoiceId, $supplier, $expense, 500.0);

        $maker = 101;
        $checker = 202;

        // Maker: initiate a partial payment against the invoice's payable.
        $payment = app(PaySupplierInvoiceService::class)->initiatePayment(
            companyId: $this->companyId,
            invoiceId: $invoiceId,
            number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(),
            amount: 300.0,
            fundingAccountId: (int) $bank->id,
            createdBy: $maker,
        );

        $this->assertSame(PaymentStatus::Draft, $payment->status);
        $this->assertSame($supplier, (string) $payment->supplier_id);

        // Checker (a DIFFERENT person) approves and posts through the canonical authority.
        app(AccountsPayableService::class)->approvePayment($payment, $checker);
        app(AccountsPayableService::class)->postPayment($payment->fresh());

        // Settle: allocate the posted payment to the invoice — caller never knew the bill id.
        app(PaySupplierInvoiceService::class)->settleInvoice($payment->fresh(), $invoiceId, 300.0);

        // Paid / Remaining are DERIVED from the immutable allocation, never stored.
        $this->assertSame(300.0, $bill->fresh()->allocatedAmount());
        $this->assertSame(200.0, $bill->fresh()->outstanding());
        $this->assertSame(200.0, app(SupplierLedgerService::class)->balance($this->companyId, $supplier));
    }

    public function test_it_refuses_to_pay_an_invoice_with_no_canonical_payable(): void
    {
        $bank = $this->fundingAccount();

        // No 'SI-<id>' bill was ever established (e.g. a Mode-1 commercial invoice
        // whose lines carry no receipt anchor). Nothing is fabricated.
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('No canonical payable');

        app(PaySupplierInvoiceService::class)->initiatePayment(
            companyId: $this->companyId,
            invoiceId: (string) Str::uuid(),
            number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(),
            amount: 100.0,
            fundingAccountId: (int) $bank->id,
            createdBy: 1,
        );
    }

    public function test_the_maker_still_cannot_approve_the_payment_it_initiated(): void
    {
        $supplier = (string) Str::uuid();
        $invoiceId = (string) Str::uuid();
        $this->controlAccount('ap', AccountType::Liability);
        $expense = $this->account(AccountType::Expense);
        $bank = $this->fundingAccount();

        $this->postedInvoicePayable($invoiceId, $supplier, $expense, 200.0);

        $maker = 55;
        $payment = app(PaySupplierInvoiceService::class)->initiatePayment(
            companyId: $this->companyId,
            invoiceId: $invoiceId,
            number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(),
            amount: 200.0,
            fundingAccountId: (int) $bank->id,
            createdBy: $maker,
        );

        // The use case initiates only; the identity gate on approval is untouched.
        $this->expectException(FinanceException::class);
        app(AccountsPayableService::class)->approvePayment($payment, $maker);
    }

    public function test_it_refuses_a_payment_larger_than_the_invoice_remaining(): void
    {
        $supplier = (string) Str::uuid();
        $invoiceId = (string) Str::uuid();
        $this->controlAccount('ap', AccountType::Liability);
        $expense = $this->account(AccountType::Expense);
        $bank = $this->fundingAccount();

        $this->postedInvoicePayable($invoiceId, $supplier, $expense, 150.0);

        $this->expectException(FinanceException::class);
        app(PaySupplierInvoiceService::class)->initiatePayment(
            companyId: $this->companyId,
            invoiceId: $invoiceId,
            number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(),
            amount: 250.0, // exceeds the 150 outstanding
            fundingAccountId: (int) $bank->id,
            createdBy: 1,
        );
    }

    public function test_it_refuses_a_funding_account_from_another_company(): void
    {
        $supplier = (string) Str::uuid();
        $invoiceId = (string) Str::uuid();
        $this->controlAccount('ap', AccountType::Liability);
        $expense = $this->account(AccountType::Expense);

        $this->postedInvoicePayable($invoiceId, $supplier, $expense, 100.0);

        // A funding account that does not belong to the acting company.
        $foreignCompany = Company::factory()->create();
        $foreignAccount = app(ChartOfAccountsService::class)->create([
            'company_id' => (string) $foreignCompany->id,
            'code' => 'X-'.$this->suffix(),
            'name' => 'Foreign bank',
            'account_type' => AccountType::Asset,
            'is_postable' => true,
        ]);

        $this->expectException(FinanceException::class);
        app(PaySupplierInvoiceService::class)->initiatePayment(
            companyId: $this->companyId,
            invoiceId: $invoiceId,
            number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(),
            amount: 100.0,
            fundingAccountId: (int) $foreignAccount->id,
            createdBy: 1,
        );
    }

    public function test_it_cannot_settle_with_an_unposted_payment(): void
    {
        $supplier = (string) Str::uuid();
        $invoiceId = (string) Str::uuid();
        $this->controlAccount('ap', AccountType::Liability);
        $expense = $this->account(AccountType::Expense);
        $bank = $this->fundingAccount();

        $this->postedInvoicePayable($invoiceId, $supplier, $expense, 200.0);

        // A draft payment (never approved/posted) cannot settle anything.
        $draft = app(PaySupplierInvoiceService::class)->initiatePayment(
            companyId: $this->companyId,
            invoiceId: $invoiceId,
            number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(),
            amount: 200.0,
            fundingAccountId: (int) $bank->id,
            createdBy: 1,
        );

        $this->expectException(FinanceException::class);
        app(PaySupplierInvoiceService::class)->settleInvoice($draft, $invoiceId, 200.0);
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

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

    /**
     * A GL asset account designated as a legitimate funding source — backed by a
     * bank account, as FundingAccountPolicy requires of a supplier payment.
     */
    private function fundingAccount(): Account
    {
        $gl = $this->account(AccountType::Asset);
        app(BankingService::class)->createAccount($this->companyId, 'Bank-'.$this->suffix(), (int) $gl->id);

        return $gl;
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

    /**
     * The canonical payable a posted supplier invoice would establish: a posted
     * SupplierBill numbered 'SI-'.<invoice id> — the exact convention
     * PostSupplierInvoiceService writes and the read-model reads.
     */
    private function postedInvoicePayable(string $invoiceId, string $supplier, Account $expense, float $amount): SupplierBill
    {
        $bill = app(AccountsPayableService::class)->createDocument(
            companyId: $this->companyId,
            supplierId: $supplier,
            number: 'SI-'.$invoiceId,
            documentDate: Carbon::today(),
            lines: [['expense_account_id' => (int) $expense->id, 'net_amount' => $amount]],
            type: SupplierDocumentType::Bill,
            dueDate: Carbon::today(),
        );

        return app(AccountsPayableService::class)->postDocument($bill);
    }
}
