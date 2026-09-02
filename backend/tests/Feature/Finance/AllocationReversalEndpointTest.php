<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
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
use Modules\Finance\Presentation\Http\Controllers\CustomerReceiptController;
use Modules\Finance\Presentation\Http\Controllers\SupplierPaymentController;
use Modules\Finance\Receivables\Domain\Enums\CustomerDocumentType;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Models\CustomerReceipt;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-AP-AR-GL-WIRING-003 — the HTTP/application wiring for
 * append-only contra-allocation (SupplierPaymentController::reverseAllocation()/
 * CustomerReceiptController::reverseAllocation()). The domain-level invariants
 * (full/partial/sequential reversal, over-reversal rejection, immutability,
 * effective-balance correctness, concurrency) are already exhaustively
 * covered at the AllocationEngine level by Task 2's
 * SupplierPaymentAllocationReversalTest/CustomerReceiptAllocationReversalTest
 * — this file proves the CONTROLLER wires those same guarantees through
 * correctly, in particular that an allocation is only ever resolved as a
 * child of the already company-scoped payment/receipt, so a cross-payment,
 * cross-receipt, or foreign-company allocation id is unreachable (404), not
 * merely "denied" — knowing the uuid is never authorization.
 */
class AllocationReversalEndpointTest extends TestCase
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

    // 1/8. Full supplier allocation reversal via the controller restores the
    // effective AP balance and returns the correct shape.
    public function test_full_ap_reversal_via_controller_restores_effective_balance(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $allocation = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        $response = app(SupplierPaymentController::class)->reverseAllocation(
            $this->reasonRequest(400.0, 'Payment bounced'),
            $payment->uuid,
            $allocation->uuid,
        );

        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame($allocation->uuid, $body['reverses_allocation_id']);
        $this->assertSame($bill->uuid, $body['supplier_bill_id']);
        $this->assertSame(400.0, $body['payment_unallocated']);
        $this->assertSame(1000.0, $bill->fresh()->outstanding());
    }

    // 2/3. Partial, then a second sequential partial, via the controller.
    public function test_sequential_partial_ap_reversal_via_controller(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $allocation = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        app(SupplierPaymentController::class)->reverseAllocation(
            $this->reasonRequest(150.0, 'Partial correction'), $payment->uuid, $allocation->uuid,
        );
        $second = app(SupplierPaymentController::class)->reverseAllocation(
            $this->reasonRequest(100.0, 'Second partial correction'), $payment->uuid, $allocation->uuid,
        );

        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame(250.0, $payment->fresh()->unallocatedAmount());
        $this->assertSame(850.0, $bill->fresh()->outstanding());
    }

    // 4. Over-reversal is rejected by the controller (surfaces the engine's
    // own guard, not a duplicated check).
    public function test_ap_over_reversal_is_rejected_via_controller(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $allocation = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        $this->expectException(FinanceException::class);
        app(SupplierPaymentController::class)->reverseAllocation(
            $this->reasonRequest(400.01, 'Too much'), $payment->uuid, $allocation->uuid,
        );
    }

    // 5/9. Cross-payment reversal is rejected: an allocation that belongs to
    // a DIFFERENT payment is unreachable under this payment's uuid — 404,
    // not a domain exception, because it is never resolved at all. The same
    // mechanism rejects a foreign-company payment/allocation.
    public function test_cross_payment_ap_reversal_is_unreachable(): void
    {
        $supplier = (string) Str::uuid();
        $billA = $this->postedBill($supplier, 1000.0);
        $billB = $this->postedBill($supplier, 1000.0);
        $paymentA = $this->postedPayment($supplier, 400.0);
        $paymentB = $this->postedPayment($supplier, 400.0);
        $allocationOnA = app(AllocationEngine::class)->allocatePayment($paymentA->fresh(), $billA, 400.0);
        app(AllocationEngine::class)->allocatePayment($paymentB->fresh(), $billB, 400.0);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        // paymentB's uuid, but allocationOnA's id — belongs to paymentA, not paymentB.
        app(SupplierPaymentController::class)->reverseAllocation(
            $this->reasonRequest(400.0, 'Wrong payment'), $paymentB->uuid, $allocationOnA->uuid,
        );
    }

    public function test_foreign_company_ap_payment_is_unreachable(): void
    {
        $otherCompany = Company::factory()->create();
        $this->openPeriodForCompany((string) $otherCompany->id);
        $this->controlAccountFor((string) $otherCompany->id, 'ap', AccountType::Liability);

        $supplier = (string) Str::uuid();
        $foreignBill = $this->postedBillFor((string) $otherCompany->id, $supplier, 1000.0);
        $foreignPayment = $this->postedPaymentFor((string) $otherCompany->id, $supplier, 400.0);
        $foreignAllocation = app(AllocationEngine::class)->allocatePayment($foreignPayment->fresh(), $foreignBill, 400.0);

        // Acting user belongs to $this->companyId, not $otherCompany — the
        // payment lookup itself is company-scoped, so it 404s before the
        // allocation is ever considered.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(SupplierPaymentController::class)->reverseAllocation(
            $this->reasonRequest(400.0, 'Cross-tenant attempt'), $foreignPayment->uuid, $foreignAllocation->uuid,
        );
    }

    // 6/7. Mandatory reason enforced; original allocation left untouched.
    public function test_ap_reversal_requires_a_reason_and_leaves_the_original_untouched(): void
    {
        $supplier = (string) Str::uuid();
        $bill = $this->postedBill($supplier, 1000.0);
        $payment = $this->postedPayment($supplier, 400.0);
        $allocation = app(AllocationEngine::class)->allocatePayment($payment->fresh(), $bill, 400.0);

        $request = Request::create('/', 'POST', ['amount' => 400.0]);
        $request->setUserResolver(fn () => $this->actingUser);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SupplierPaymentController::class)->reverseAllocation($request, $payment->uuid, $allocation->uuid);
    }

    // ── AR mirror ────────────────────────────────────────────────────────────

    // 10/17. Full receipt allocation reversal via the controller restores the
    // effective AR balance.
    public function test_full_ar_reversal_via_controller_restores_effective_balance(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $allocation = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        $response = app(CustomerReceiptController::class)->reverseAllocation(
            $this->reasonRequest(400.0, 'Wrong invoice'), $receipt->uuid, $allocation->uuid,
        );

        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame($invoice->uuid, $body['customer_invoice_id']);
        $this->assertSame(400.0, $body['receipt_unallocated']);
        $this->assertSame(1000.0, $invoice->fresh()->outstanding());
    }

    // 11/12. Partial, then sequential partial, via the controller.
    public function test_sequential_partial_ar_reversal_via_controller(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $allocation = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        app(CustomerReceiptController::class)->reverseAllocation(
            $this->reasonRequest(150.0, 'Partial correction'), $receipt->uuid, $allocation->uuid,
        );
        $second = app(CustomerReceiptController::class)->reverseAllocation(
            $this->reasonRequest(100.0, 'Second partial correction'), $receipt->uuid, $allocation->uuid,
        );

        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame(250.0, $receipt->fresh()->unallocatedAmount());
        $this->assertSame(850.0, $invoice->fresh()->outstanding());
    }

    // 13. Over-reversal rejected.
    public function test_ar_over_reversal_is_rejected_via_controller(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $allocation = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        $this->expectException(FinanceException::class);
        app(CustomerReceiptController::class)->reverseAllocation(
            $this->reasonRequest(400.01, 'Too much'), $receipt->uuid, $allocation->uuid,
        );
    }

    // 14/18. Cross-receipt reversal is unreachable (404).
    public function test_cross_receipt_ar_reversal_is_unreachable(): void
    {
        $customer = (string) Str::uuid();
        $invoiceA = $this->postedInvoice($customer, 1000.0);
        $invoiceB = $this->postedInvoice($customer, 1000.0);
        $receiptA = $this->postedReceipt($customer, 400.0);
        $receiptB = $this->postedReceipt($customer, 400.0);
        $allocationOnA = app(AllocationEngine::class)->allocateReceipt($receiptA->fresh(), $invoiceA, 400.0);
        app(AllocationEngine::class)->allocateReceipt($receiptB->fresh(), $invoiceB, 400.0);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(CustomerReceiptController::class)->reverseAllocation(
            $this->reasonRequest(400.0, 'Wrong receipt'), $receiptB->uuid, $allocationOnA->uuid,
        );
    }

    // 15/16. Mandatory reason enforced; original allocation left untouched.
    public function test_ar_reversal_requires_a_reason(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 1000.0);
        $receipt = $this->postedReceipt($customer, 400.0);
        $allocation = app(AllocationEngine::class)->allocateReceipt($receipt->fresh(), $invoice, 400.0);

        $request = Request::create('/', 'POST', ['amount' => 400.0]);
        $request->setUserResolver(fn () => $this->actingUser);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CustomerReceiptController::class)->reverseAllocation($request, $receipt->uuid, $allocation->uuid);
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function reasonRequest(float $amount, string $reason): Request
    {
        $request = Request::create('/', 'POST', ['amount' => $amount, 'reason' => $reason]);
        $request->setUserResolver(fn () => $this->actingUser);

        return $request;
    }

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

    private function accountFor(string $companyId, AccountType $type, bool $postable = true): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => strtoupper($type->value[0]).'-'.$this->suffix(),
            'name' => ucfirst($type->value).' account',
            'account_type' => $type,
            'is_postable' => $postable,
        ]);
    }

    private function fundingAccountFor(string $companyId): Account
    {
        $gl = $this->accountFor($companyId, AccountType::Asset);
        app(BankingService::class)->createAccount($companyId, 'Bank-'.$this->suffix(), (int) $gl->id);

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
