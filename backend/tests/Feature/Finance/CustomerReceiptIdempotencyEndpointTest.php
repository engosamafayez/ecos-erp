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
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Presentation\Http\Controllers\CustomerReceiptController;
use Modules\Finance\Receivables\Domain\Enums\CustomerDocumentType;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Models\CustomerReceipt;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;
use Modules\Finance\Shared\Domain\Models\FinanceCommandReceipt;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-AP-AR-GL-WIRING-003 — the AR mirror of
 * SupplierPaymentIdempotencyEndpointTest: CommandIdempotencyGuard wired into
 * CustomerReceiptController::store(), exercised through the real controller
 * method with a constructed Request, using the SAME canonical foundation
 * (no cloned guard).
 */
class CustomerReceiptIdempotencyEndpointTest extends TestCase
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
        // Only writeOff()'s new tests need this (it posts a receipt AND an
        // invoice internally); the existing createReceipt()-only tests above
        // never post anything, so this addition does not affect them.
        $this->controlAccount('ar', AccountType::Asset);
    }

    // 26. Same key + same receipt payload replays safely.
    public function test_same_key_and_same_payload_replays_safely(): void
    {
        $key = (string) Str::uuid();
        $customer = (string) Str::uuid();
        $number = 'RC-'.substr(md5(uniqid('', true)), 0, 8);

        $first = app(CustomerReceiptController::class)->store($this->storeRequest($customer, $key, $number));
        $second = app(CustomerReceiptController::class)->store($this->storeRequest($customer, $key, $number));

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));

        $firstId = json_decode((string) $first->getContent(), true)['data']['id'];
        $secondId = json_decode((string) $second->getContent(), true)['data']['id'];
        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, CustomerReceipt::query()->count());
    }

    // 27. Same key + a materially different payload conflicts.
    public function test_same_key_and_different_payload_conflicts(): void
    {
        $key = (string) Str::uuid();
        $customer = (string) Str::uuid();

        app(CustomerReceiptController::class)->store($this->storeRequest($customer, $key, amount: 100.0));

        $this->expectException(\Modules\Finance\Ledger\Domain\Exceptions\FinanceException::class);
        app(CustomerReceiptController::class)->store($this->storeRequest($customer, $key, amount: 999.0));
    }

    // 28. A different key permits a legitimate second receipt.
    public function test_different_key_permits_a_second_legitimate_receipt(): void
    {
        $customer = (string) Str::uuid();

        app(CustomerReceiptController::class)->store($this->storeRequest($customer, (string) Str::uuid()));
        app(CustomerReceiptController::class)->store($this->storeRequest($customer, (string) Str::uuid()));

        $this->assertSame(2, CustomerReceipt::query()->count());
    }

    // 29. A failed-first receipt command is safely retryable — no false-
    // success receipt, and a valid retry with the same key succeeds.
    public function test_failed_first_receipt_command_is_safely_retryable(): void
    {
        $key = (string) Str::uuid();
        $customer = (string) Str::uuid();

        try {
            app(CustomerReceiptController::class)->store($this->storeRequest(
                customer: $customer,
                key: $key,
                depositAccountUuid: (string) Str::uuid(), // does not exist -> throws
            ));
            $this->fail('Expected the invalid deposit account to raise an exception.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, FinanceCommandReceipt::query()->where('idempotency_key', $key)->count());
        $this->assertSame(0, CustomerReceipt::query()->count());

        $retry = app(CustomerReceiptController::class)->store($this->storeRequest($customer, $key));

        $this->assertSame(201, $retry->getStatusCode());
        $this->assertSame(1, CustomerReceipt::query()->count());
    }

    // 30. Same key, called repeatedly: never executes the underlying command
    // twice, so a duplicate receipt cannot form (the true concurrent-race
    // case is proven structurally at the guard level in Task 2's
    // CommandIdempotencyGuardTest).
    public function test_same_key_called_repeatedly_cannot_duplicate_the_receipt(): void
    {
        $key = (string) Str::uuid();
        $customer = (string) Str::uuid();

        app(CustomerReceiptController::class)->store($this->storeRequest($customer, $key));
        app(CustomerReceiptController::class)->store($this->storeRequest($customer, $key));
        app(CustomerReceiptController::class)->store($this->storeRequest($customer, $key));

        $this->assertSame(1, CustomerReceipt::query()->count());
    }

    // 38. Key ownership respects company/tenant.
    public function test_key_ownership_respects_company_tenant(): void
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => (string) $otherCompany->id]);
        $this->openPeriodForCompany((string) $otherCompany->id);
        $otherCash = $this->cashAccountFor((string) $otherCompany->id);

        $key = (string) Str::uuid();
        $customer = (string) Str::uuid();

        $mine = app(CustomerReceiptController::class)->store($this->storeRequest($customer, $key));
        $theirs = app(CustomerReceiptController::class)->store($this->requestAs(
            $otherUser, $customer, $key, 100.0, 'RC-'.substr(md5(uniqid('', true)), 0, 8), $otherCash->uuid,
        ));

        $this->assertSame(201, $mine->getStatusCode());
        $this->assertSame(201, $theirs->getStatusCode());
        $this->assertSame(2, CustomerReceipt::query()->count());
    }

    // TASK-ECOS-FINANCE-FOUNDATION-GATE-004 finding: writeOff() is also a
    // real receipt-creation path (a CustomerReceipt funded by a bad-debt
    // account) and was not wired to the idempotency guard in Task 3.
    public function test_write_off_first_command_succeeds(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 500.0);

        $response = app(CustomerReceiptController::class)->writeOff(
            $this->writeOffRequest($invoice->uuid, (string) Str::uuid()),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(0.0, $invoice->fresh()->outstanding());
        $this->assertSame(1, CustomerReceipt::query()->count());
    }

    // Same key + same payload replays the original write-off — no second
    // bad-debt receipt is created (previously this would have thrown a raw
    // unique-constraint violation on the deterministic 'WO-'.number instead
    // of replaying gracefully; both were safe, only this one is graceful).
    public function test_write_off_same_key_and_same_payload_replays_safely(): void
    {
        $customer = (string) Str::uuid();
        $invoice = $this->postedInvoice($customer, 500.0);
        $key = (string) Str::uuid();

        $first = app(CustomerReceiptController::class)->writeOff($this->writeOffRequest($invoice->uuid, $key));
        $second = app(CustomerReceiptController::class)->writeOff($this->writeOffRequest($invoice->uuid, $key));

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));

        $firstReceipt = json_decode((string) $first->getContent(), true)['data']['write_off_receipt'];
        $secondReceipt = json_decode((string) $second->getContent(), true)['data']['write_off_receipt'];
        $this->assertSame($firstReceipt, $secondReceipt);
        $this->assertSame(1, CustomerReceipt::query()->count());
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function storeRequest(
        string $customer,
        ?string $key,
        ?string $number = null,
        float $amount = 100.0,
        ?string $depositAccountUuid = null,
    ): Request {
        return $this->requestAs(
            $this->actingUser,
            $customer,
            $key,
            $amount,
            $number ?? 'RC-'.substr(md5(uniqid('', true)), 0, 8),
            $depositAccountUuid ?? $this->cashAccountFor($this->companyId)->uuid,
        );
    }

    private function requestAs(
        User $user,
        string $customer,
        ?string $key,
        float $amount,
        string $number,
        string $depositAccountUuid,
    ): Request {
        $request = Request::create('/finance/ar/receipts', 'POST', [
            'customer_id' => $customer,
            'number' => $number,
            'receipt_date' => Carbon::today()->toDateString(),
            'amount' => $amount,
            'deposit_account_id' => $depositAccountUuid,
        ]);
        $request->setUserResolver(fn () => $user);

        if ($key !== null) {
            $request->headers->set('Idempotency-Key', $key);
        }

        return $request;
    }

    private function writeOffRequest(string $invoiceId, ?string $key): Request
    {
        $badDebt = $this->accountFor($this->companyId, AccountType::Expense);
        $request = Request::create('/finance/ar/write-off', 'POST', [
            'invoice_id' => $invoiceId,
            'bad_debt_account_id' => $badDebt->uuid,
        ]);
        $request->setUserResolver(fn () => $this->actingUser);

        if ($key !== null) {
            $request->headers->set('Idempotency-Key', $key);
        }

        return $request;
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

    private function cashAccountFor(string $companyId): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => 'A-'.$this->suffix(),
            'name' => 'Cash account',
            'account_type' => AccountType::Asset,
            'is_postable' => true,
        ]);
    }
}
