<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Ledger\Domain\Services\JournalEngine;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Models\SupplierLedgerEntry;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\Finance\Presentation\Http\Controllers\SupplierPaymentController;
use Modules\Finance\Receivables\Domain\Enums\CustomerDocumentType;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Models\CustomerLedgerEntry;
use Modules\Finance\Receivables\Domain\Models\CustomerReceipt;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-FULL-ACCOUNTING-RECONCILIATION-005 — closes the gap
 * Task 4 found: JournalEngine::reverse() (unchanged, still the sole GL
 * writer/reversal path) had no way to know a payment/receipt's ledger entry
 * needed a compensating entry too. AccountsPayableService::
 * reversePaymentPosting()/AccountsReceivableService::reverseReceiptPosting()
 * now call the SAME reverse() method and additionally write a new,
 * append-only, sign-flipped ledger entry — never editing the original.
 */
class GlSubledgerReversalReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    private User $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->actingUser = User::factory()->create(['company_id' => $this->companyId]);
        $this->openPeriodForToday();
        $this->controlAccount('ap', AccountType::Liability);
        $this->controlAccount('ar', AccountType::Asset);
    }

    // 1. Supplier payment journal reversal creates/reconciles the correct
    // supplier subledger projection.
    public function test_supplier_payment_reversal_reconciles_the_supplier_ledger(): void
    {
        $supplier = (string) Str::uuid();
        $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);

        $this->assertSame(600.0, app(SupplierLedgerService::class)->balance($this->companyId, $supplier));

        app(AccountsPayableService::class)->reversePaymentPosting($payment->fresh(), 'Payment bounced', 1);

        $this->assertSame(1000.0, app(SupplierLedgerService::class)->balance($this->companyId, $supplier));
    }

    // 2. Customer receipt journal reversal creates/reconciles the correct
    // customer subledger projection.
    public function test_customer_receipt_reversal_reconciles_the_customer_ledger(): void
    {
        $customer = (string) Str::uuid();
        $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);

        $this->assertSame(600.0, app(CustomerLedgerService::class)->balance($this->companyId, $customer));

        app(AccountsReceivableService::class)->reverseReceiptPosting($receipt->fresh(), 'Receipt reversed by bank', 1);

        $this->assertSame(1000.0, app(CustomerLedgerService::class)->balance($this->companyId, $customer));
    }

    // 3. The original subledger entry remains — untouched, auditable —
    // after reversal; a new row is appended, nothing is edited or removed.
    public function test_original_ledger_entry_remains_untouched_and_auditable(): void
    {
        $supplier = (string) Str::uuid();
        $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);

        $original = SupplierLedgerEntry::query()
            ->where('source_type', 'supplier_payment')->where('source_id', $payment->uuid)->firstOrFail();
        $originalUpdatedAt = $original->updated_at;

        app(AccountsPayableService::class)->reversePaymentPosting($payment->fresh(), 'Correction', 1);

        $original->refresh();
        $this->assertSame(-400.0, (float) $original->amount);
        $this->assertEquals($originalUpdatedAt, $original->updated_at);
        $this->assertSame(2, SupplierLedgerEntry::query()->where('supplier_id', $supplier)->where('entry_type', 'payment')->count());
    }

    // 4. Repeated reversal cannot duplicate the compensating entry — the
    // second attempt fails at JournalEngine's own already-reversed guard,
    // inside the same transaction, before any second ledger row is written.
    public function test_repeated_reversal_cannot_duplicate_the_compensating_entry(): void
    {
        $supplier = (string) Str::uuid();
        $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);

        app(AccountsPayableService::class)->reversePaymentPosting($payment->fresh(), 'First reversal', 1);

        try {
            app(AccountsPayableService::class)->reversePaymentPosting($payment->fresh(), 'Second attempt', 1);
            $this->fail('Expected the second reversal attempt to be rejected (already reversed).');
        } catch (\Modules\Finance\Ledger\Domain\Exceptions\FinanceException) {
            // expected
        }

        $this->assertSame(
            2, // original payment entry + exactly one reversal, never two
            SupplierLedgerEntry::query()->where('supplier_id', $supplier)->where('entry_type', 'payment')->count(),
        );
    }

    // 5. Foreign-company path rejected: the payment lookup itself is
    // company-scoped, so a foreign-company payment 404s before reversal is
    // ever considered — proven at the controller (the real entry point).
    public function test_foreign_company_payment_reversal_is_unreachable(): void
    {
        $otherCompany = Company::factory()->create();
        $this->openPeriodForCompany((string) $otherCompany->id);
        $this->controlAccountFor((string) $otherCompany->id, 'ap', AccountType::Liability);

        $supplier = (string) Str::uuid();
        $this->postedBillFor((string) $otherCompany->id, $supplier, 1000.0);
        $foreignPayment = $this->postedPaymentFor((string) $otherCompany->id, $supplier, 400.0);

        $request = Request::create('/', 'POST', ['reason' => 'Cross-tenant attempt']);
        $request->setUserResolver(fn () => $this->actingUser); // belongs to $this->companyId, not $otherCompany

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(SupplierPaymentController::class)->reversePosting($request, $foreignPayment->uuid);
    }

    // 6. Unrelated journal reversal is unaffected: reversing a supplier
    // BILL's journal through the plain, unchanged JournalEngine::reverse()
    // writes no compensating ledger entry, because that logic exists only
    // inside the new, explicit reversePaymentPosting()/reverseReceiptPosting()
    // methods — never inside JournalEngine itself.
    public function test_bill_journal_reversal_via_the_generic_path_writes_no_compensating_entry(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);

        $journal = JournalEntry::query()->findOrFail($bill->fresh()->journal_entry_id);
        app(JournalEngine::class)->reverse($journal, 'Bill correction', 1);

        $this->assertSame(1, SupplierLedgerEntry::query()->where('supplier_id', $supplier)->count());
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function postedPayment(string $supplier, float $amount): SupplierPayment
    {
        return $this->postedPaymentFor($this->companyId, $supplier, $amount);
    }

    private function postedPaymentFor(string $companyId, string $supplier, float $amount): SupplierPayment
    {
        $gl = $this->fundingAccountFor($companyId);
        $payment = app(AccountsPayableService::class)->createPayment(
            companyId: $companyId, supplierId: $supplier, number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(), amount: $amount, fundingAccountId: (int) $gl->id, createdBy: 1,
        );
        app(AccountsPayableService::class)->approvePayment($payment, 2);

        return app(AccountsPayableService::class)->postPayment($payment->fresh());
    }

    private function postedBill(string $supplier, float $amount): SupplierBill
    {
        return $this->postedBillFor($this->companyId, $supplier, $amount);
    }

    private function postedBillFor(string $companyId, string $supplier, float $amount): SupplierBill
    {
        $expense = $this->accountFor($companyId, AccountType::Expense);
        $bill = app(AccountsPayableService::class)->createDocument(
            companyId: $companyId, supplierId: $supplier, number: 'BILL-'.$this->suffix(),
            documentDate: Carbon::today(), lines: [['expense_account_id' => (int) $expense->id, 'net_amount' => $amount]],
            type: SupplierDocumentType::Bill, dueDate: Carbon::today(),
        );

        return app(AccountsPayableService::class)->postDocument($bill);
    }

    private function postedReceipt(string $customer, float $amount): CustomerReceipt
    {
        $cash = $this->accountFor($this->companyId, AccountType::Asset);
        $receipt = app(AccountsReceivableService::class)->createReceipt(
            companyId: $this->companyId, customerId: $customer, number: 'RC-'.$this->suffix(),
            receiptDate: Carbon::today(), amount: $amount, depositAccountId: (int) $cash->id,
        );

        return app(AccountsReceivableService::class)->postReceipt($receipt);
    }

    private function postedInvoice(string $customer, float $amount): CustomerInvoice
    {
        $revenue = $this->accountFor($this->companyId, AccountType::Revenue);
        $invoice = app(AccountsReceivableService::class)->createDocument(
            companyId: $this->companyId, customerId: $customer, number: 'INV-'.$this->suffix(),
            documentDate: Carbon::today(), lines: [['revenue_account_id' => (int) $revenue->id, 'net_amount' => $amount]],
            type: CustomerDocumentType::Invoice, dueDate: Carbon::today(),
        );

        return app(AccountsReceivableService::class)->postDocument($invoice);
    }

    private function suffix(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    private function openPeriodForToday(): FiscalPeriod
    {
        return $this->openPeriodForCompany($this->companyId);
    }

    private function openPeriodForCompany(string $companyId): FiscalPeriod
    {
        $start = Carbon::today()->subMonths(3)->startOfMonth();
        $year = app(FiscalCalendarService::class)->createYear(
            $companyId, 'FY-'.$this->suffix(), $start, $start->copy()->addMonths(11)->endOfMonth(),
        );

        foreach ($year->periods as $period) {
            if ($period->status->value !== 'open') {
                app(FiscalCalendarService::class)->openPeriod($period);
            }
        }

        return $year->periods()->where('period_number', 1)->firstOrFail();
    }

    private function accountFor(string $companyId, AccountType $type): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => strtoupper($type->value[0]).'-'.$this->suffix(),
            'name' => ucfirst($type->value).' account',
            'account_type' => $type,
            'is_postable' => true,
        ]);
    }

    private function fundingAccountFor(string $companyId): Account
    {
        $gl = $this->accountFor($companyId, AccountType::Asset);
        app(\Modules\Finance\Banking\Domain\Services\BankingService::class)->createAccount($companyId, 'Bank-'.$this->suffix(), (int) $gl->id);

        return $gl;
    }

    private function controlAccount(string $subledger, AccountType $type): Account
    {
        return $this->controlAccountFor($this->companyId, $subledger, $type);
    }

    private function controlAccountFor(string $companyId, string $subledger, AccountType $type): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => strtoupper($subledger).'-CTRL-'.$this->suffix(),
            'name' => strtoupper($subledger).' control',
            'account_type' => $type,
            'is_postable' => true,
            'is_control' => true,
            'control_subledger' => $subledger,
        ]);
    }
}
