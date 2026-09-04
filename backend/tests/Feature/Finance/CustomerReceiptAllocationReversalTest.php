<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Finance\Allocation\Domain\Services\AllocationEngine;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Receivables\Domain\Enums\CustomerDocumentType;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Models\CustomerReceipt;
use Modules\Finance\Receivables\Domain\Models\ReceiptAllocation;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-TRANSACTION-SAFETY-FOUNDATION-002 — the AR mirror of
 * SupplierPaymentAllocationReversalTest: append-only contra-allocation via
 * AllocationEngine::reverseReceiptAllocation(). The original ReceiptAllocation
 * row is never edited or deleted; a correction is a second, negative-amount,
 * append-only row referencing it. Effective outstanding stays DERIVED as
 * SUM(amount) — no change to CustomerInvoice::outstanding()/
 * CustomerReceipt::unallocatedAmount().
 */
class CustomerReceiptAllocationReversalTest extends TestCase
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
        $this->controlAccount('ar', AccountType::Asset);
    }

    // 1/2/3. The original row is untouched; the reversal is a genuinely new
    // row that references it correctly.
    public function test_reversal_creates_a_new_row_and_leaves_the_original_untouched(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);

        $original = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);
        $originalUpdatedAt = $original->updated_at;

        $reversal = app(AllocationEngine::class)->reverseReceiptAllocation($original, 400.0, 'Receipt reversed by bank', 1);

        $original->refresh();
        $this->assertSame(400.0, (float) $original->amount);
        $this->assertNull($original->reverses_allocation_id);
        $this->assertEquals($originalUpdatedAt, $original->updated_at);

        $this->assertNotSame($original->id, $reversal->id);
        $this->assertSame(-400.0, (float) $reversal->amount);
        $this->assertSame($original->id, $reversal->reverses_allocation_id);
        $this->assertSame('Receipt reversed by bank', $reversal->reversal_reason);
        $this->assertSame(2, ReceiptAllocation::query()->where('receipt_id', $receipt->id)->count());
    }

    // 4. A reversal with no reason is refused before anything is written.
    public function test_reversal_requires_a_reason(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $original = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->reverseReceiptAllocation($original, 400.0, '', 1);
    }

    // 6. Full reversal restores the invoice's outstanding and the receipt's
    // unallocated amount exactly to their pre-allocation state.
    public function test_full_reversal_restores_the_effective_ar_balance(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $original = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        $this->assertSame(600.0, $invoice->fresh()->outstanding());
        $this->assertSame(0.0, $receipt->fresh()->unallocatedAmount());

        app(AllocationEngine::class)->reverseReceiptAllocation($original, 400.0, 'Wrong invoice', 1);

        $this->assertSame(1000.0, $invoice->fresh()->outstanding());
        $this->assertSame(400.0, $receipt->fresh()->unallocatedAmount());
    }

    // Partial reversal, in two steps: each restores exactly the requested
    // amount, and the running cap is enforced across both steps together.
    public function test_partial_reversal_restores_exactly_the_requested_amount(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $original = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        app(AllocationEngine::class)->reverseReceiptAllocation($original, 150.0, 'Partial correction', 1);
        $this->assertSame(750.0, $invoice->fresh()->outstanding());
        $this->assertSame(150.0, $receipt->fresh()->unallocatedAmount());

        app(AllocationEngine::class)->reverseReceiptAllocation($original, 100.0, 'Second partial correction', 1);
        $this->assertSame(850.0, $invoice->fresh()->outstanding());
        $this->assertSame(250.0, $receipt->fresh()->unallocatedAmount());

        // 250 already reversed of 400; only 150 remains reversible.
        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->reverseReceiptAllocation($original, 200.0, 'Exceeds what remains reversible', 1);
    }

    // 8. Reversing more than the original allocation is refused outright.
    public function test_over_reversal_is_rejected(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $original = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->reverseReceiptAllocation($original, 400.01, 'Too much', 1);
    }

    // 7. A reversal always targets the SAME receipt+invoice pair as the
    // allocation it reverses — reverseReceiptAllocation() takes only the
    // allocation being corrected, with no parameter to redirect it, so a
    // reversal against an unrelated document pair is structurally
    // impossible, not merely validated away.
    public function test_reversal_always_targets_the_same_receipt_and_invoice_as_the_original(): void
    {
        $customer = (string) Str::uuid();
        $invoiceA = $this->postedInvoice($customer, 1000.0);
        $invoiceB = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 700.0);

        $toA = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoiceA, 400.0);
        app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoiceB, 300.0);

        $reversal = app(AllocationEngine::class)->reverseReceiptAllocation($toA, 400.0, 'Wrong invoice', 1);

        $this->assertSame($invoiceA->id, $reversal->customer_invoice_id);
        $this->assertSame($receipt->id, $reversal->receipt_id);
        // Invoice B, untouched by the reversal against A, keeps its own allocation.
        $this->assertSame(700.0, $invoiceB->fresh()->outstanding());
    }

    // One-step correction only: a reversal cannot itself be reversed.
    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $original = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);
        $reversal = app(AllocationEngine::class)->reverseReceiptAllocation($original, 400.0, 'Undo', 1);

        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->reverseReceiptAllocation($reversal, 400.0, 'Undo the undo', 1);
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

        $start = (int) strpos($source, 'public function reverseReceiptAllocation');
        $end = (int) strpos($source, '// ── Accounts Payable', $start);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString('lockForUpdate()->firstOrFail()', $body);
        $this->assertStringNotContainsString('->fresh()->outstanding()', $body);
        $this->assertStringNotContainsString('->fresh()->unallocatedAmount()', $body);
    }

    // 10. Ordinary allocation is unaffected by the additive change — a narrow
    // smoke-test that the new columns/relations did not disturb it. Full
    // coverage of the ordinary path remains CustomerReceiptAllocationConcurrencyTest.
    public function test_ordinary_allocation_still_works_unchanged(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);

        $allocation = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        $this->assertSame(400.0, (float) $allocation->amount);
        $this->assertNull($allocation->reverses_allocation_id);
        $this->assertSame(600.0, $invoice->fresh()->outstanding());
    }

    // ═══ HELPERS (mirrors CustomerReceiptAllocationConcurrencyTest) ═══════════

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
