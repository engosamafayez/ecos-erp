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
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-FINANCE-GAP-CLOSURE-TRANSACTION-INTEGRITY-001 — Supplier AP transaction
 * integrity: partial payment / remaining-balance correctness, over-allocation
 * prevention (including under concurrency), lifecycle recognition, and duplicate
 * safety. Paid/Remaining stay DERIVED from the immutable allocations on the
 * canonical payable (the SupplierBill); nothing is stored on the invoice.
 */
class SupplierPaymentTransactionIntegrityTest extends TestCase
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

    // A. Partial payment leaves the correct remaining balance.
    public function test_a_partial_payment_leaves_the_correct_remaining_balance(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);

        $this->allocatePostedPayment($supplier, $bill, 400.0);

        $this->assertSame(400.0, $bill->fresh()->allocatedAmount());
        $this->assertSame(600.0, $bill->fresh()->outstanding());
        $this->assertSame(600.0, app(SupplierLedgerService::class)->balance($this->companyId, $supplier));
    }

    // B. A second partial payment accumulates correctly (400 + 300 = 700 paid, 300 left).
    public function test_a_second_partial_payment_accumulates_correctly(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);

        $this->allocatePostedPayment($supplier, $bill, 400.0);
        $this->allocatePostedPayment($supplier, $bill, 300.0);

        // The first allocation is untouched; the second adds to it — derived, never overwritten.
        $this->assertSame(700.0, $bill->fresh()->allocatedAmount());
        $this->assertSame(300.0, $bill->fresh()->outstanding());
        $this->assertSame(2, $bill->fresh()->allocations()->count());
    }

    // C. Exact settlement reaches zero remaining (the canonical fully-settled state).
    public function test_exact_settlement_reaches_zero_remaining(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);

        $this->allocatePostedPayment($supplier, $bill, 600.0);
        $this->allocatePostedPayment($supplier, $bill, 400.0);

        $this->assertSame(1000.0, $bill->fresh()->allocatedAmount());
        $this->assertSame(0.0, $bill->fresh()->outstanding());
        $this->assertSame(0.0, app(SupplierLedgerService::class)->balance($this->companyId, $supplier));
    }

    // D. Over-allocation is rejected inside the write boundary (not a frontend max check).
    public function test_over_allocation_is_rejected(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 500.0);
        $payment = $this->postedPayment($supplier, 800.0); // more than the payable

        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 800.0);
    }

    // D-bis. A second allocation that would exceed the remaining payable is refused,
    // even though each individual amount is within the payment.
    public function test_a_second_allocation_cannot_exceed_the_remaining_payable(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 500.0);

        $this->allocatePostedPayment($supplier, $bill, 400.0); // remaining now 100

        $payment = $this->postedPayment($supplier, 400.0);
        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 200.0); // > 100 remaining
    }

    // E. Concurrency: the allocation write boundary must serialize on the aggregate
    // rows with a pessimistic lock. True parallelism is not exercisable in a single
    // test connection, so this guards the mechanism against regression at the source.
    public function test_allocation_serializes_the_payable_with_a_pessimistic_lock(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Finance/Allocation/Domain/Services/AllocationEngine.php',
        ));

        // allocatePayment must lock BOTH aggregate rows the constraints derive from,
        // and must not fall back to a non-locking ->fresh() re-read for the amounts.
        $this->assertStringContainsString('lockForUpdate', (string) $source);
        $allocatePaymentBody = substr(
            (string) $source,
            (int) strpos((string) $source, 'public function allocatePayment'),
            (int) strpos((string) $source, 'public function autoAllocatePayment') - (int) strpos((string) $source, 'public function allocatePayment'),
        );
        $this->assertStringContainsString('lockForUpdate()->firstOrFail()', $allocatePaymentBody);
        $this->assertStringNotContainsString('->fresh()->unallocatedAmount()', $allocatePaymentBody);
        $this->assertStringNotContainsString('->fresh()->outstanding()', $allocatePaymentBody);
    }

    // F. Duplicate submission: the payment natural key (company + number) is unique,
    // so a retried create with the same number cannot produce a duplicate payment.
    public function test_duplicate_payment_number_is_rejected(): void
    {
        $supplier = (string) Str::uuid();
        $gl = $this->fundingAccount();
        $number = 'PAY-DUP-'.$this->suffix();

        app(AccountsPayableService::class)->createPayment(
            companyId: $this->companyId, supplierId: $supplier, number: $number,
            paymentDate: Carbon::today(), amount: 100.0, fundingAccountId: (int) $gl->id, createdBy: 1,
        );

        $this->expectException(\Illuminate\Database\QueryException::class);
        app(AccountsPayableService::class)->createPayment(
            companyId: $this->companyId, supplierId: $supplier, number: $number,
            paymentDate: Carbon::today(), amount: 100.0, fundingAccountId: (int) $gl->id, createdBy: 1,
        );
    }

    // F-bis. Posting is exactly-once: a posted payment cannot be posted again.
    public function test_a_posted_payment_cannot_be_posted_again(): void
    {
        $supplier = (string) Str::uuid();
        $payment = $this->postedPayment($supplier, 100.0);

        $this->expectException(FinanceException::class);
        app(AccountsPayableService::class)->postPayment($payment->fresh());
    }

    // G. Lifecycle recognition: an unposted (draft/approved) payment does NOT count as
    // paid — allocation requires a POSTED payment, so it cannot reduce the payable.
    public function test_an_unposted_payment_cannot_be_allocated_and_does_not_reduce_the_payable(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $gl = $this->fundingAccount();

        $draft = app(AccountsPayableService::class)->createPayment(
            companyId: $this->companyId, supplierId: $supplier, number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(), amount: 500.0, fundingAccountId: (int) $gl->id, createdBy: 1,
        );

        try {
            app(AllocationEngine::class)->allocatePayment($draft, $bill, 500.0);
            $this->fail('A draft payment must not be allocatable.');
        } catch (FinanceException) {
            // expected — allocation requires a posted payment.
        }

        $this->assertSame(0.0, $bill->fresh()->allocatedAmount());
        $this->assertSame(1000.0, $bill->fresh()->outstanding());
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function allocatePostedPayment(string $supplier, SupplierBill $bill, float $amount): void
    {
        $payment = $this->postedPayment($supplier, $amount);
        app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, $amount);
    }

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

    /** A GL asset account designated as a legitimate funding source (bank-backed). */
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
