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
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-FINANCE-AR-ALLOCATION-CONCURRENCY-INTEGRITY-001 — the AR mirror of the
 * approved AP allocation concurrency fix. A customer receipt may only be applied
 * to an invoice up to the invoice's outstanding and the receipt's own unapplied
 * amount, and that re-derivation now happens under a pessimistic lock so two
 * concurrent receipts cannot both settle the same receivable (write skew). Paid /
 * remaining stay DERIVED from the immutable receipt allocations.
 */
class CustomerReceiptAllocationConcurrencyTest extends TestCase
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

    // A. Partial receipt allocation leaves the correct outstanding.
    public function test_a_partial_receipt_allocation_leaves_the_correct_outstanding(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);

        $this->allocatePostedReceipt($customer, $invoice, 400.0);

        $this->assertSame(600.0, $invoice->fresh()->outstanding());
        $this->assertSame(600.0, app(CustomerLedgerService::class)->balance($this->companyId, $customer));
    }

    // B. A second receipt allocation accumulates correctly (400 + 300 → 300 left).
    public function test_a_second_receipt_allocation_accumulates_correctly(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);

        $this->allocatePostedReceipt($customer, $invoice, 400.0);
        $this->allocatePostedReceipt($customer, $invoice, 300.0);

        $this->assertSame(300.0, $invoice->fresh()->outstanding());
        $this->assertSame(2, $invoice->fresh()->allocations()->count());
    }

    // C. Exact settlement reaches zero outstanding.
    public function test_exact_settlement_reaches_zero_outstanding(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);

        $this->allocatePostedReceipt($customer, $invoice, 600.0);
        $this->allocatePostedReceipt($customer, $invoice, 400.0);

        $this->assertSame(0.0, $invoice->fresh()->outstanding());
        $this->assertSame(0.0, app(CustomerLedgerService::class)->balance($this->companyId, $customer));
    }

    // D. Over-allocation against the receivable is rejected in the write boundary.
    public function test_over_allocation_against_the_receivable_is_rejected(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 500.0);
        $receipt = $this->postedReceipt($customer, 800.0); // more than the receivable

        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 800.0);
    }

    // D-bis. A second allocation that would exceed the remaining receivable is refused.
    public function test_a_second_allocation_cannot_exceed_the_remaining_receivable(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 500.0);

        $this->allocatePostedReceipt($customer, $invoice, 400.0); // outstanding now 100

        $receipt = $this->postedReceipt($customer, 400.0);
        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 200.0); // > 100 remaining
    }

    // E. Concurrency: the AR allocation write boundary must serialize on the aggregate
    // rows with a pessimistic lock, not a non-locking ->fresh(). Guards the mechanism.
    public function test_receipt_allocation_serializes_the_receivable_with_a_pessimistic_lock(): void
    {
        $source = (string) file_get_contents(base_path(
            'Modules/Finance/Allocation/Domain/Services/AllocationEngine.php',
        ));

        $allocateReceiptBody = substr(
            $source,
            (int) strpos($source, 'public function allocateReceipt'),
            (int) strpos($source, 'public function autoAllocateReceipt') - (int) strpos($source, 'public function allocateReceipt'),
        );

        $this->assertStringContainsString('lockForUpdate()->firstOrFail()', $allocateReceiptBody);
        $this->assertStringNotContainsString('->fresh()->unallocatedAmount()', $allocateReceiptBody);
        $this->assertStringNotContainsString('->fresh()->outstanding()', $allocateReceiptBody);
    }

    // F. The receipt itself cannot be over-applied beyond its unallocated amount.
    public function test_a_receipt_cannot_be_allocated_beyond_its_available_amount(): void
    {
        $customer = (string) Str::uuid();
        $a = $this->postedInvoice($customer, 1000.0);
        $b = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 500.0);

        // Apply the whole receipt to invoice A, then a further allocation to B must fail:
        // the receipt has nothing unapplied left.
        app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $a, 500.0);

        $this->assertSame(0.0, $receipt->fresh()->unallocatedAmount());
        $this->expectException(FinanceException::class);
        app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $b, 1.0);
    }

    // G. Lifecycle: an unposted receipt cannot be allocated and does not reduce the receivable.
    public function test_an_unposted_receipt_cannot_be_allocated(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $cash = $this->account(AccountType::Asset);

        $draft = app(AccountsReceivableService::class)->createReceipt(
            companyId: $this->companyId, customerId: $customer, number: 'RC-'.$this->suffix(),
            receiptDate: Carbon::today(), amount: 500.0, depositAccountId: (int) $cash->id,
        );

        try {
            app(AllocationEngine::class)->allocateReceipt($draft, $invoice, 500.0);
            $this->fail('An unposted receipt must not be allocatable.');
        } catch (FinanceException) {
            // expected — allocation requires a posted receipt.
        }

        $this->assertSame(1000.0, $invoice->fresh()->outstanding());
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function allocatePostedReceipt(string $customer, CustomerInvoice $invoice, float $amount): void
    {
        $receipt = $this->postedReceipt($customer, $amount);
        app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, $amount);
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
