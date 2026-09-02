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
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingLine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingRequest;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Posting\Domain\Services\PostingCoordinator;
use Modules\Finance\Shared\Domain\Models\FinanceCommandReceipt;
use Modules\Finance\Shared\Domain\Services\CommandIdempotencyGuard;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-TRANSACTION-SAFETY-FOUNDATION-002 — the Finance command
 * idempotency foundation (CommandIdempotencyGuard / finance_command_receipts).
 * Proven against a real command (AccountsPayableService::createPayment)
 * rather than a synthetic one, with no controller/endpoint wiring — that is
 * Task 3's scope, not this one.
 */
class CommandIdempotencyGuardTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->openPeriodForToday($this->companyId);
    }

    // 11. First execution proceeds and is recorded.
    public function test_first_execution_is_accepted_and_recorded(): void
    {
        $key = (string) Str::uuid();
        $payload = ['supplier_id' => 'S1', 'amount' => 100.0];

        $result = app(CommandIdempotencyGuard::class)->execute(
            $this->companyId, 'ap.payment.create', $key, $payload,
            fn () => $this->createDraftPayment('S1', 100.0),
        );

        $this->assertFalse($result->wasReplayed);
        $this->assertInstanceOf(SupplierPayment::class, $result->result);
        $this->assertSame(1, FinanceCommandReceipt::query()->where('idempotency_key', $key)->count());
        $this->assertSame(1, SupplierPayment::query()->count());
    }

    // 12. Same key + same request replays the original result — no second
    // SupplierPayment is created.
    public function test_same_key_and_same_request_replays_the_original_result(): void
    {
        $key = (string) Str::uuid();
        $payload = ['supplier_id' => 'S1', 'amount' => 100.0];
        $command = fn () => $this->createDraftPayment('S1', 100.0);

        $first = app(CommandIdempotencyGuard::class)->execute($this->companyId, 'ap.payment.create', $key, $payload, $command);
        $second = app(CommandIdempotencyGuard::class)->execute($this->companyId, 'ap.payment.create', $key, $payload, $command);

        $this->assertFalse($first->wasReplayed);
        $this->assertTrue($second->wasReplayed);
        $this->assertSame($first->result->uuid, $second->result->uuid);
        $this->assertSame(1, SupplierPayment::query()->count());
        $this->assertSame(1, FinanceCommandReceipt::query()->where('idempotency_key', $key)->count());
    }

    // 13. Same key + a materially different payload is a deterministic
    // conflict — refused, not guessed at, and no second row is created.
    public function test_same_key_and_different_request_conflicts(): void
    {
        $key = (string) Str::uuid();
        app(CommandIdempotencyGuard::class)->execute(
            $this->companyId, 'ap.payment.create', $key, ['supplier_id' => 'S1', 'amount' => 100.0],
            fn () => $this->createDraftPayment('S1', 100.0),
        );

        try {
            app(CommandIdempotencyGuard::class)->execute(
                $this->companyId, 'ap.payment.create', $key, ['supplier_id' => 'S1', 'amount' => 999.0],
                fn () => $this->createDraftPayment('S1', 999.0),
            );
            $this->fail('Expected a conflict exception for the same key with a different payload.');
        } catch (FinanceException) {
            // expected
        }

        $this->assertSame(1, SupplierPayment::query()->count());
    }

    // 14. Same key: a fresh attempt always resolves to the existing receipt
    // first and never re-runs the command, so a duplicate financial
    // transaction cannot form. (True thread-level parallelism is not
    // exercisable in a single test connection — the concurrent-race path
    // itself, where two attempts are genuinely in flight together, is proven
    // structurally by the next test.)
    public function test_same_key_never_executes_the_command_twice(): void
    {
        $key = (string) Str::uuid();
        $payload = ['supplier_id' => 'S1', 'amount' => 100.0];
        $calls = 0;
        $command = function () use (&$calls) {
            $calls++;

            return $this->createDraftPayment('S1', 100.0);
        };

        app(CommandIdempotencyGuard::class)->execute($this->companyId, 'ap.payment.create', $key, $payload, $command);
        app(CommandIdempotencyGuard::class)->execute($this->companyId, 'ap.payment.create', $key, $payload, $command);
        app(CommandIdempotencyGuard::class)->execute($this->companyId, 'ap.payment.create', $key, $payload, $command);

        $this->assertSame(1, $calls);
        $this->assertSame(1, SupplierPayment::query()->count());
    }

    // Structural proof for the genuinely concurrent case: the command must
    // run INSIDE the same transaction that claims the receipt (not before
    // it) — see the class docblock for why this is what makes a losing
    // concurrent attempt's effects roll back atomically with its failed claim.
    public function test_the_command_runs_inside_the_same_transaction_as_the_receipt_claim(): void
    {
        $source = (string) file_get_contents(base_path(
            'Modules/Finance/Shared/Domain/Services/CommandIdempotencyGuard.php',
        ));

        $start = (int) strpos($source, 'DB::transaction(function ()');
        $end = (int) strpos($source, 'catch (UniqueConstraintViolationException)', $start);
        $body = substr($source, $start, $end - $start);

        $this->assertMatchesRegularExpression('/\$result\s*=\s*\$command\(\).*FinanceCommandReceipt::create/s', $body);
    }

    // 15. Different keys are fully independent — each is its own legitimate
    // command, per the required invariant that a genuine second financial
    // transaction must remain possible even for identical business inputs.
    public function test_different_keys_remain_independent(): void
    {
        $payload = ['supplier_id' => 'S1', 'amount' => 100.0];

        $first = app(CommandIdempotencyGuard::class)->execute(
            $this->companyId, 'ap.payment.create', (string) Str::uuid(), $payload,
            fn () => $this->createDraftPayment('S1', 100.0),
        );
        $second = app(CommandIdempotencyGuard::class)->execute(
            $this->companyId, 'ap.payment.create', (string) Str::uuid(), $payload,
            fn () => $this->createDraftPayment('S1', 100.0),
        );

        $this->assertNotSame($first->result->uuid, $second->result->uuid);
        $this->assertSame(2, SupplierPayment::query()->count());
    }

    // 16. The same idempotency key in a DIFFERENT company is a different
    // claim — company_id is part of the uniqueness boundary, so tenants
    // never collide on a caller-chosen key.
    public function test_the_same_key_in_a_different_company_does_not_collide(): void
    {
        $otherCompany = Company::factory()->create();
        $this->openPeriodForToday((string) $otherCompany->id);
        $key = (string) Str::uuid();
        $payload = ['supplier_id' => 'S1', 'amount' => 100.0];

        $mine = app(CommandIdempotencyGuard::class)->execute(
            $this->companyId, 'ap.payment.create', $key, $payload,
            fn () => $this->createDraftPayment('S1', 100.0, $this->companyId),
        );
        $theirs = app(CommandIdempotencyGuard::class)->execute(
            (string) $otherCompany->id, 'ap.payment.create', $key, $payload,
            fn () => $this->createDraftPayment('S1', 100.0, (string) $otherCompany->id),
        );

        $this->assertFalse($mine->wasReplayed);
        $this->assertFalse($theirs->wasReplayed);
        $this->assertNotSame($mine->result->uuid, $theirs->result->uuid);
    }

    // 17. PostingCoordinator's own event-level dedup — untouched by this
    // task — still holds: two posts of the same source event return the
    // same journal, never two.
    public function test_posting_coordinator_event_dedup_remains_untouched(): void
    {
        $expense = $this->account(AccountType::Expense);
        $cash = $this->account(AccountType::Asset);
        $sourceEventId = 'idempotency-proof-'.$this->suffix();

        $request = new PostingRequest(
            companyId: $this->companyId,
            entryDate: Carbon::today(),
            lines: [
                PostingLine::debit((int) $expense->id, 100.0, $this->companyId),
                PostingLine::credit((int) $cash->id, 100.0, $this->companyId),
            ],
            reference: $sourceEventId,
            description: 'PostingCoordinator dedup proof',
            source: 'posting',
            sourceModule: 'test.idempotency',
            sourceEventId: $sourceEventId,
        );

        $first = app(PostingCoordinator::class)->post('test.idempotency', $sourceEventId, $request);
        $second = app(PostingCoordinator::class)->post('test.idempotency', $sourceEventId, $request);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::query()->where('reference', $sourceEventId)->count());
    }

    // Fingerprint semantics: key-construction order must not matter, and the
    // 4dp rounding Finance already applies to every stored amount must not
    // produce a spurious mismatch between two logically identical payloads.
    public function test_fingerprint_is_order_independent_and_rounds_like_finance_does(): void
    {
        $a = CommandIdempotencyGuard::fingerprint(['amount' => 100.0, 'supplier_id' => 'S1']);
        $b = CommandIdempotencyGuard::fingerprint(['supplier_id' => 'S1', 'amount' => 100.00001]);

        $this->assertSame($a, $b);

        $c = CommandIdempotencyGuard::fingerprint(['supplier_id' => 'S1', 'amount' => 100.01]);
        $this->assertNotSame($a, $c);
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function createDraftPayment(string $supplier, float $amount, ?string $companyId = null): SupplierPayment
    {
        $companyId ??= $this->companyId;
        $gl = $this->fundingAccount($companyId);

        return app(AccountsPayableService::class)->createPayment(
            companyId: $companyId, supplierId: $supplier, number: 'PAY-'.$this->suffix(),
            paymentDate: Carbon::today(), amount: $amount, fundingAccountId: (int) $gl->id, createdBy: 1,
        );
    }

    private function suffix(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    private function openPeriodForToday(string $companyId): FiscalPeriod
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

    private function account(AccountType $type, ?string $companyId = null, bool $postable = true): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId ?? $this->companyId,
            'code' => strtoupper($type->value[0]).'-'.$this->suffix(),
            'name' => ucfirst($type->value).' account',
            'account_type' => $type,
            'is_postable' => $postable,
        ]);
    }

    /** A GL asset account designated as a legitimate funding source (bank-backed). */
    private function fundingAccount(?string $companyId = null): Account
    {
        $companyId ??= $this->companyId;
        $gl = $this->account(AccountType::Asset, $companyId);
        app(BankingService::class)->createAccount($companyId, 'Bank-'.$this->suffix(), (int) $gl->id);

        return $gl;
    }
}
