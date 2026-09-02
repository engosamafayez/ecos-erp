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
use Modules\Finance\Payables\Domain\Models\PaymentAllocation;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-TRANSACTION-SAFETY-FOUNDATION-002 — append-only
 * contra-allocation for AP (AllocationEngine::reversePaymentAllocation()).
 * The original PaymentAllocation row is never edited or deleted; a
 * correction is a second, negative-amount, append-only row referencing it.
 * Effective Paid/Remaining stay DERIVED as SUM(amount) over both — no change
 * to SupplierBill::outstanding()/SupplierPayment::unallocatedAmount().
 */
class SupplierPaymentAllocationReversalTest extends TestCase
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

    // 1/2/3. The original row is untouched; the reversal is a genuinely new
    // row that references it correctly.
    public function test_reversal_creates_a_new_row_and_leaves_the_original_untouched(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);

        $original = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);
        $originalUpdatedAt = $original->updated_at;

        $reversal = app(AllocationEngine::class)->reversePaymentAllocation($original, 400.0, 'Payment bounced', 1);

        $original->refresh();
        $this->assertSame(400.0, (float) $original->amount);
        $this->assertNull($original->reverses_allocation_id);
        $this->assertEquals($originalUpdatedAt, $original->updated_at);

        $this->assertNotSame($original->id, $reversal->id);
        $this->assertSame(-400.0, (float) $reversal->amount);
        $this->assertSame($original->id, $reversal->reverses_allocation_id);
        $this->assertSame('Payment bounced', $reversal->reversal_reason);
        $this->assertSame(2, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
    }

    // 4. A reversal with no reason is refused before anything is written.
    public function test_reversal_requires_a_reason(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $original = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->reversePaymentAllocation($original, 400.0, '   ', 1);
    }

    // 5. Full reversal restores the bill's outstanding and the payment's
    // unallocated amount exactly to their pre-allocation state.
    public function test_full_reversal_restores_the_effective_ap_balance(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $original = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        $this->assertSame(600.0, $bill->fresh()->outstanding());
        $this->assertSame(0.0, $payment->fresh()->unallocatedAmount());

        app(AllocationEngine::class)->reversePaymentAllocation($original, 400.0, 'Wrong bill', 1);

        $this->assertSame(1000.0, $bill->fresh()->outstanding());
        $this->assertSame(400.0, $payment->fresh()->unallocatedAmount());
    }

    // Partial reversal, in two steps: each restores exactly the requested
    // amount, and the running cap is enforced across both steps together.
    public function test_partial_reversal_restores_exactly_the_requested_amount(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $original = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        app(AllocationEngine::class)->reversePaymentAllocation($original, 150.0, 'Partial correction', 1);
        $this->assertSame(750.0, $bill->fresh()->outstanding());
        $this->assertSame(150.0, $payment->fresh()->unallocatedAmount());

        app(AllocationEngine::class)->reversePaymentAllocation($original, 100.0, 'Second partial correction', 1);
        $this->assertSame(850.0, $bill->fresh()->outstanding());
        $this->assertSame(250.0, $payment->fresh()->unallocatedAmount());

        // 250 already reversed of 400; only 150 remains reversible.
        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->reversePaymentAllocation($original, 200.0, 'Exceeds what remains reversible', 1);
    }

    // 8. Reversing more than the original allocation is refused outright.
    public function test_over_reversal_is_rejected(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $original = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->reversePaymentAllocation($original, 400.01, 'Too much', 1);
    }

    // 7. A reversal always targets the SAME payment+bill pair as the
    // allocation it reverses — reversePaymentAllocation() takes only the
    // allocation being corrected, with no parameter to redirect it, so a
    // reversal against an unrelated document pair is structurally
    // impossible, not merely validated away.
    public function test_reversal_always_targets_the_same_payment_and_bill_as_the_original(): void
    {
        $supplier = (string) Str::uuid();
        $billA = $this->postedBill($supplier, 1000.0);
        $billB = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 700.0);

        $toA = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $billA, 400.0);
        app(AllocationEngine::class)->allocatePayment($payment->fresh(), $billB, 300.0);

        $reversal = app(AllocationEngine::class)->reversePaymentAllocation($toA, 400.0, 'Wrong bill', 1);

        $this->assertSame($billA->id, $reversal->supplier_bill_id);
        $this->assertSame($payment->id, $reversal->payment_id);
        // Bill B, untouched by the reversal against A, keeps its own allocation.
        $this->assertSame(700.0, $billB->fresh()->outstanding());
    }

    // One-step correction only: a reversal cannot itself be reversed.
    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $original = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);
        $reversal = app(AllocationEngine::class)->reversePaymentAllocation($original, 400.0, 'Undo', 1);

        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->reversePaymentAllocation($reversal, 400.0, 'Undo the undo', 1);
    }

    // 9. Concurrency: reversal must serialize on the same aggregate-row locks
    // as allocation, re-deriving the reversible amount only after the lock is
    // held. True parallelism is not exercisable in a single test connection,
    // so this guards the mechanism against regression at the source — the
    // same technique the approved de10aca3/d561516b tests already use.
    public function test_reversal_serializes_on_the_same_locks_as_allocation(): void
    {
        $source = (string) file_get_contents(base_path(
            'Modules/Finance/Allocation/Domain/Services/AllocationEngine.php',
        ));

        $start = (int) strpos($source, 'public function reversePaymentAllocation');
        $end = (int) strpos($source, '// ── Guards', $start);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString('lockForUpdate()->firstOrFail()', $body);
        $this->assertStringNotContainsString('->fresh()->outstanding()', $body);
        $this->assertStringNotContainsString('->fresh()->unallocatedAmount()', $body);
    }

    // 10. Ordinary allocation is unaffected by the additive change — a narrow
    // smoke-test that the new columns/relations did not disturb it. Full
    // coverage of the ordinary path remains SupplierPaymentTransactionIntegrityTest.
    public function test_ordinary_allocation_still_works_unchanged(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);

        $allocation = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        $this->assertSame(400.0, (float) $allocation->amount);
        $this->assertNull($allocation->reverses_allocation_id);
        $this->assertSame(600.0, $bill->fresh()->outstanding());
    }

    // ═══ HELPERS (mirrors SupplierPaymentTransactionIntegrityTest) ════════════

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
