<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Domain\Services\BankingService;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Presentation\Http\Controllers\SupplierPaymentController;
use Modules\Finance\Shared\Domain\Models\FinanceCommandReceipt;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-AP-AR-GL-WIRING-003 — CommandIdempotencyGuard wired into
 * SupplierPaymentController::store(). Exercises the real controller method
 * with a constructed Request (this codebase's Finance tests consistently
 * call services/controllers directly rather than through full HTTP routing
 * — see SupplierPaymentTransactionIntegrityTest et al.), so this proves the
 * WIRING, not a re-test of CommandIdempotencyGuardTest's own guard-level
 * coverage from Task 2.
 */
class SupplierPaymentIdempotencyEndpointTest extends TestCase
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
    }

    // 19. First command succeeds.
    public function test_first_command_succeeds(): void
    {
        $response = app(SupplierPaymentController::class)->store(
            $this->storeRequest(supplier: (string) Str::uuid(), key: (string) Str::uuid()),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('false', $response->headers->get('Idempotent-Replay'));
        $this->assertSame(1, SupplierPayment::query()->count());
    }

    // 20. Same key + same payload returns the original result — no duplicate.
    public function test_same_key_and_same_payload_returns_original_result(): void
    {
        $key = (string) Str::uuid();
        $supplier = (string) Str::uuid();
        $number = 'PAY-'.substr(md5(uniqid('', true)), 0, 8);

        $first = app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key, $number));
        $second = app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key, $number));

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));

        $firstId = json_decode((string) $first->getContent(), true)['data']['id'];
        $secondId = json_decode((string) $second->getContent(), true)['data']['id'];
        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, SupplierPayment::query()->count());
    }

    // 21. Same key + a materially different payload conflicts.
    public function test_same_key_and_different_payload_conflicts(): void
    {
        $key = (string) Str::uuid();
        $supplier = (string) Str::uuid();

        app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key, amount: 100.0));

        $this->expectException(\Modules\Finance\Ledger\Domain\Exceptions\FinanceException::class);
        app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key, amount: 999.0));
    }

    // 22. A different key permits a second, legitimate, independent payment.
    public function test_different_key_permits_a_second_legitimate_payment(): void
    {
        $supplier = (string) Str::uuid();

        app(SupplierPaymentController::class)->store($this->storeRequest($supplier, (string) Str::uuid()));
        app(SupplierPaymentController::class)->store($this->storeRequest($supplier, (string) Str::uuid()));

        $this->assertSame(2, SupplierPayment::query()->count());
    }

    // 23. A failed first attempt (invalid funding account) leaves no
    // command receipt — the key is not falsely marked successful.
    public function test_failed_first_attempt_leaves_no_false_success_receipt(): void
    {
        $key = (string) Str::uuid();

        try {
            app(SupplierPaymentController::class)->store($this->storeRequest(
                supplier: (string) Str::uuid(),
                key: $key,
                fundingAccountUuid: (string) Str::uuid(), // does not exist -> createPayment() throws
            ));
            $this->fail('Expected the invalid funding account to raise an exception.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, FinanceCommandReceipt::query()->where('idempotency_key', $key)->count());
        $this->assertSame(0, SupplierPayment::query()->count());
    }

    // 24. Retry with the SAME key after a failed first attempt, now with a
    // valid payload, succeeds — the key was never poisoned.
    public function test_safe_retry_after_failed_first_attempt(): void
    {
        $key = (string) Str::uuid();
        $supplier = (string) Str::uuid();

        try {
            app(SupplierPaymentController::class)->store($this->storeRequest(
                supplier: $supplier,
                key: $key,
                fundingAccountUuid: (string) Str::uuid(),
            ));
        } catch (\Throwable) {
            // expected first-attempt failure
        }

        $response = app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, SupplierPayment::query()->count());
    }

    // 25. Same key, called repeatedly: never executes the underlying command
    // twice, so a duplicate payment cannot form (the concurrent-race case
    // itself is proven structurally at the guard level in Task 2's
    // CommandIdempotencyGuardTest; this proves the controller wiring
    // preserves that guarantee end to end).
    public function test_same_key_called_repeatedly_cannot_duplicate_the_payment(): void
    {
        $key = (string) Str::uuid();
        $supplier = (string) Str::uuid();

        app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key));
        app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key));
        app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key));

        $this->assertSame(1, SupplierPayment::query()->count());
    }

    // 38. Key ownership respects company/tenant: the same key in a different
    // company is a different claim.
    public function test_key_ownership_respects_company_tenant(): void
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => (string) $otherCompany->id]);
        $this->openPeriodForCompany((string) $otherCompany->id);
        $otherGl = $this->fundingAccountFor((string) $otherCompany->id);

        $key = (string) Str::uuid();
        $supplier = (string) Str::uuid();

        $mine = app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key));
        $theirs = app(SupplierPaymentController::class)->store($this->requestAs(
            $otherUser, $supplier, $key, 100.0, 'PAY-'.substr(md5(uniqid('', true)), 0, 8), $otherGl->uuid,
        ));

        $this->assertSame(201, $mine->getStatusCode());
        $this->assertSame(201, $theirs->getStatusCode());
        $this->assertSame(2, SupplierPayment::query()->count());
    }

    // 40. No duplicate underlying row on replay (the create path posts no GL
    // entry itself — postPayment() does that separately — so "no duplicate
    // GL side effect" here means no duplicate SupplierPayment row, the
    // thing that would later be posted).
    public function test_no_duplicate_row_created_on_replay(): void
    {
        $key = (string) Str::uuid();
        $supplier = (string) Str::uuid();
        $number = 'PAY-'.substr(md5(uniqid('', true)), 0, 8);

        app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key, $number));
        app(SupplierPaymentController::class)->store($this->storeRequest($supplier, $key, $number));

        $this->assertSame(1, SupplierPayment::query()->where('number', $number)->count());
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function storeRequest(
        string $supplier,
        ?string $key,
        ?string $number = null,
        float $amount = 100.0,
        ?string $fundingAccountUuid = null,
    ): Request {
        return $this->requestAs(
            $this->actingUser,
            $supplier,
            $key,
            $amount,
            $number ?? 'PAY-'.substr(md5(uniqid('', true)), 0, 8),
            $fundingAccountUuid ?? $this->fundingAccountFor($this->companyId)->uuid,
        );
    }

    private function requestAs(
        User $user,
        string $supplier,
        ?string $key,
        float $amount,
        string $number,
        string $fundingAccountUuid,
    ): Request {
        $request = Request::create('/finance/ap/payments', 'POST', [
            'supplier_id' => $supplier,
            'number' => $number,
            'payment_date' => Carbon::today()->toDateString(),
            'amount' => $amount,
            'funding_account_id' => $fundingAccountUuid,
        ]);
        $request->setUserResolver(fn () => $user);

        if ($key !== null) {
            $request->headers->set('Idempotency-Key', $key);
        }

        return $request;
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

    private function fundingAccountFor(string $companyId): Account
    {
        $gl = app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => 'A-'.$this->suffix(),
            'name' => 'Asset account',
            'account_type' => AccountType::Asset,
            'is_postable' => true,
        ]);
        app(BankingService::class)->createAccount($companyId, 'Bank-'.$this->suffix(), (int) $gl->id);

        return $gl;
    }
}
