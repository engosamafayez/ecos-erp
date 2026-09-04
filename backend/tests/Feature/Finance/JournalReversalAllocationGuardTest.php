<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Finance\Allocation\Domain\Services\AllocationEngine;
use Modules\Finance\Banking\Domain\Services\BankingService;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Ledger\Domain\Services\JournalEngine;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Receivables\Domain\Enums\CustomerDocumentType;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Models\CustomerReceipt;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-AP-AR-GL-WIRING-003 — a payment/receipt-sourced GL
 * journal must not be reversed while its allocation remains EFFECTIVELY
 * active (JournalEngine::assertNoActiveSubledgerAllocations(), keyed off the
 * journal's own source_module/source_event_id — no new source-linkage
 * column). Every other journal source (bill postings, manual journals) is
 * unaffected, and every pre-existing reversal rule still applies on top.
 */
class JournalReversalAllocationGuardTest extends TestCase
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
        $this->controlAccount('ar', AccountType::Asset);
    }

    // 31. Payment journal with effective AP allocation > 0 cannot reverse.
    public function test_payment_journal_with_active_allocation_cannot_be_reversed(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        $journal = JournalEntry::query()->findOrFail($payment->fresh()->journal_entry_id);

        $this->expectException(FinanceException::class);
        app(JournalEngine::class)->reverse($journal, 'Attempted reversal', 1);
    }

    // 32. Partially reversed allocation still blocks (effective still > 0).
    public function test_partially_reversed_allocation_still_blocks_reversal(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $allocation = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);
        app(AllocationEngine::class)->reversePaymentAllocation($allocation, 150.0, 'Partial correction', 1);

        $journal = JournalEntry::query()->findOrFail($payment->fresh()->journal_entry_id);

        $this->expectException(FinanceException::class);
        app(JournalEngine::class)->reverse($journal, 'Attempted reversal', 1);
    }

    // 33. Fully contra-allocated payment is no longer blocked by the guard.
    public function test_fully_reversed_allocation_no_longer_blocks_reversal(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $allocation = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);
        app(AllocationEngine::class)->reversePaymentAllocation($allocation, 400.0, 'Full correction', 1);

        $journal = JournalEntry::query()->findOrFail($payment->fresh()->journal_entry_id);
        $reversal = app(JournalEngine::class)->reverse($journal, 'Payment bounced', 1);

        $this->assertNotNull($reversal->id);
        $this->assertSame($journal->id, $reversal->reverses_journal_id);
    }

    // 34. Receipt journal with effective AR allocation > 0 cannot reverse.
    public function test_receipt_journal_with_active_allocation_cannot_be_reversed(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        $journal = JournalEntry::query()->findOrFail($receipt->fresh()->journal_entry_id);

        $this->expectException(FinanceException::class);
        app(JournalEngine::class)->reverse($journal, 'Attempted reversal', 1);
    }

    // 35. Fully contra-allocated receipt no longer blocked by the guard.
    public function test_fully_reversed_receipt_allocation_no_longer_blocks_reversal(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $allocation = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);
        app(AllocationEngine::class)->reverseReceiptAllocation($allocation, 400.0, 'Receipt reversed by bank', 1);

        $journal = JournalEntry::query()->findOrFail($receipt->fresh()->journal_entry_id);
        $reversal = app(JournalEngine::class)->reverse($journal, 'Bank reversal', 1);

        $this->assertNotNull($reversal->id);
    }

    // 36. Unrelated journals are unaffected: a supplier BILL posting is also
    // source_module='finance.ap', but its source_event_id is 'bill:'.$uuid,
    // not 'payment:'.$uuid — the guard must not over-match on module alone.
    public function test_bill_posting_journal_is_unaffected_by_the_payment_allocation_guard(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);

        $journal = JournalEntry::query()->findOrFail($bill->fresh()->journal_entry_id);

        $reversal = app(JournalEngine::class)->reverse($journal, 'Bill correction', 1);

        $this->assertNotNull($reversal->id);
    }

    // 37. Other pre-existing reversal rules still apply on top of this guard:
    // once unblocked and reversed, the SAME journal cannot be reversed again.
    public function test_other_pre_existing_reversal_rules_still_apply(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $allocation = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);
        app(AllocationEngine::class)->reversePaymentAllocation($allocation, 400.0, 'Full correction', 1);

        $journal = JournalEntry::query()->findOrFail($payment->fresh()->journal_entry_id);
        app(JournalEngine::class)->reverse($journal, 'First reversal', 1);

        $this->expectException(FinanceException::class);
        app(JournalEngine::class)->reverse($journal->fresh(), 'Second reversal attempt', 1);
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function postedPayment(string $supplier, float $amount): SupplierPayment
    {
        $gl = $this->fundingAccount();
        $payment = app(AccountsPayableService::class)->createPayment(
            companyId: $this->companyId, supplierId: $supplier, number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(), amount: $amount, fundingAccountId: (int) $gl->id, createdBy: 1,
        );
        app(AccountsPayableService::class)->approvePayment($payment, 2);

        return app(AccountsPayableService::class)->postPayment($payment->fresh());
    }

    private function postedBill(string $supplier, float $amount): SupplierBill
    {
        $expense = $this->account(AccountType::Expense);
        $bill = app(AccountsPayableService::class)->createDocument(
            companyId: $this->companyId, supplierId: $supplier, number: 'BILL-'.$this->suffix(),
            documentDate: Carbon::today(), lines: [['expense_account_id' => (int) $expense->id, 'net_amount' => $amount]],
            type: SupplierDocumentType::Bill, dueDate: Carbon::today(),
        );

        return app(AccountsPayableService::class)->postDocument($bill);
    }

    private function postedReceipt(string $customer, float $amount): CustomerReceipt
    {
        $cash = $this->account(AccountType::Asset);
        $receipt = app(AccountsReceivableService::class)->createReceipt(
            companyId: $this->companyId, customerId: $customer, number: 'RC-'.$this->suffix(),
            receiptDate: Carbon::today(), amount: $amount, depositAccountId: (int) $cash->id,
        );

        return app(AccountsReceivableService::class)->postReceipt($receipt);
    }

    private function postedInvoice(string $customer, float $amount): CustomerInvoice
    {
        $revenue = $this->account(AccountType::Revenue);
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
}
