<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\Finance\Payables\Domain\Services\SupplierOpeningBalanceService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-ADVANCE-BILL-SETTLEMENT-004.
 *
 * Two layers, mirroring the accepted convention for this exact bug family
 * (AllocationEngine::allocatePayment / allocateReceipt, TASK-FINANCE-GAP-CLOSURE-
 * TRANSACTION-INTEGRITY-001 and TASK-FINANCE-AR-ALLOCATION-CONCURRENCY-INTEGRITY-001):
 *
 *   1. A static source assertion — the write boundary must lock BOTH aggregate
 *      sources it derives from and must never fall back to a non-locking re-read.
 *      Cheap, always runs in CI, guards the mechanism against regression at the
 *      source. Genuine two-connection parallelism is not exercisable inside
 *      PHPUnit's single-connection DatabaseTransactions harness (same limitation
 *      noted on the AP/AR test); the real cross-connection lock-wait-timeout
 *      proof (advance 1,000, two concurrent applies of 800 each to two different
 *      bills, total applied <= 1,000) is run separately against a disposable
 *      MySQL container and quoted in the Engineering Report, exactly as the
 *      Supplier Code sequence fix (TASK-...-MASTER-DATA-002-R1) was proven.
 *
 *   2. Transaction atomicity — a settlement refused by either check leaves
 *      NO trace: no ledger entries, no journal, on either the supplier or the
 *      bill it was refused against.
 */
class SupplierAdvanceSettlementConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Company::factory()->create()->id;
        app(\Modules\Finance\Shared\Domain\Services\CompanyFinanceProvisioner::class)->provision($this->companyId);
        $this->openAllPeriods();
    }

    // ── Static source assertion (mirrors the accepted AP/AR convention) ───────

    public function test_apply_advance_to_bill_locks_both_aggregate_sources_before_re_deriving(): void
    {
        $source = (string) file_get_contents(base_path(
            'Modules/Finance/Payables/Domain/Services/SupplierOpeningBalanceService.php',
        ));

        $start = strpos($source, 'public function applyAdvanceToBill');
        $this->assertIsInt($start, 'applyAdvanceToBill must exist in SupplierOpeningBalanceService.');

        $nextMethod = strpos($source, 'private function existingEntry');
        $this->assertIsInt($nextMethod, 'existingEntry must exist immediately after applyAdvanceToBill.');

        $body = substr($source, $start, $nextMethod - $start);

        // Both the shared ledger-entry range (the "advance" source) and the specific
        // bill (the document) must be taken under a pessimistic lock ...
        $this->assertStringContainsString('->lockForUpdate()', $body);
        $this->assertStringContainsString('SupplierLedgerEntry::query()', $body);
        $this->assertStringContainsString('SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail()', $body);

        // ... and the amounts re-derived only AFTER those locks are held — never a
        // non-locking ->fresh() (or un-locked service call) re-read, which is exactly
        // the write-skew bug this fix closes.
        $this->assertStringNotContainsString('->fresh()->', $body);
    }

    // ── Transaction atomicity: a refused settlement leaves no trace ───────────

    public function test_a_settlement_refused_for_exceeding_available_advance_leaves_no_trace(): void
    {
        $supplier = (string) Str::uuid();
        $expense = $this->account(AccountType::Expense);

        app(SupplierOpeningBalanceService::class)->postOpeningAdvance(
            $this->companyId, $supplier, 'SUP-ATOM', 100.0, Carbon::today(), null, null, 1,
        );
        $bill = $this->postedBill($supplier, $expense, 1000.0);

        $ledgerCountBefore = DB::table('finance_supplier_ledger_entries')->where('supplier_id', $supplier)->count();
        $journalCountBefore = DB::table('finance_journal_entries')->count();

        try {
            app(SupplierOpeningBalanceService::class)->applyAdvanceToBill($bill->fresh(), 150.0, 1); // > 100 available
            $this->fail('Expected the over-application to be refused.');
        } catch (FinanceException) {
            // expected
        }

        $this->assertSame($ledgerCountBefore, DB::table('finance_supplier_ledger_entries')->where('supplier_id', $supplier)->count());
        $this->assertSame($journalCountBefore, DB::table('finance_journal_entries')->count());
        $this->assertSame(100.0, app(SupplierLedgerService::class)->availableAdvance($this->companyId, $supplier));
        $this->assertSame(1000.0, $bill->fresh()->outstanding());
    }

    public function test_a_settlement_refused_for_exceeding_the_bills_outstanding_leaves_no_trace(): void
    {
        $supplier = (string) Str::uuid();
        $expense = $this->account(AccountType::Expense);

        app(SupplierOpeningBalanceService::class)->postOpeningAdvance(
            $this->companyId, $supplier, 'SUP-ATOM2', 10000.0, Carbon::today(), null, null, 1,
        );
        $bill = $this->postedBill($supplier, $expense, 100.0);

        $ledgerCountBefore = DB::table('finance_supplier_ledger_entries')->where('supplier_id', $supplier)->count();

        try {
            app(SupplierOpeningBalanceService::class)->applyAdvanceToBill($bill->fresh(), 500.0, 1); // > bill's own 100
            $this->fail('Expected the over-application to be refused.');
        } catch (FinanceException) {
            // expected
        }

        $this->assertSame($ledgerCountBefore, DB::table('finance_supplier_ledger_entries')->where('supplier_id', $supplier)->count());
        $this->assertSame(10000.0, app(SupplierLedgerService::class)->availableAdvance($this->companyId, $supplier));
        $this->assertSame(100.0, $bill->fresh()->outstanding());
    }

    // ── R1 §21 — Idempotency boundary (documented, not solved here). PostingCoordinator's
    // (source_module, source_event_id) receipt makes the JOURNAL exactly-once: a second
    // call with an IDENTICAL (bill, amount) reuses the same journal rather than posting a
    // new one. But applyAdvanceToBill(), unlike postOpeningPayable/postOpeningAdvance (which
    // guard with an explicit existingEntry() check before posting), unconditionally creates
    // TWO NEW SupplierLedgerEntry rows every call — so if enough advance remains for the
    // SAME valid amount to pass its checks a second time, submitting the exact same command
    // twice double-consumes the ledger/advance while the GL only reflects one journal's
    // worth of postings. This is a genuine, pre-existing gap (present before this task too,
    // since applyAdvanceToBill's structure is otherwise unchanged) — proven here, not fixed,
    // per this task's explicit instruction not to widen into idempotency-key architecture.
    // Transactional/concurrency safety (§7/§9 of the R1 remediation) is unaffected: this is
    // about a SEQUENTIAL duplicate submission, not a concurrent race.
    public function test_identical_sequential_resubmission_double_consumes_the_ledger_no_duplicate_guard(): void
    {
        $supplier = (string) Str::uuid();
        $expense = $this->account(AccountType::Expense);

        app(SupplierOpeningBalanceService::class)->postOpeningAdvance(
            $this->companyId, $supplier, 'SUP-DUP', 2000.0, Carbon::today(), null, null, 1,
        );
        $bill = $this->postedBill($supplier, $expense, 10000.0);

        $journalCountBefore = DB::table('finance_journal_entries')->count();

        // The identical (bill, amount) command, submitted twice, sequentially.
        app(SupplierOpeningBalanceService::class)->applyAdvanceToBill($bill->fresh(), 800.0, 1);
        app(SupplierOpeningBalanceService::class)->applyAdvanceToBill($bill->fresh(), 800.0, 1);

        // PostingCoordinator's receipt makes the JOURNAL exactly-once: only ONE new journal.
        $this->assertSame($journalCountBefore + 1, DB::table('finance_journal_entries')->count());

        // But the LEDGER was consumed twice — 1,600 total, not 800 — the confirmed gap.
        $this->assertSame(400.0, app(SupplierLedgerService::class)->availableAdvance($this->companyId, $supplier));
        $this->assertSame(1600.0, $bill->fresh()->allocatedAmount());
        $this->assertSame(4, DB::table('finance_supplier_ledger_entries')
            ->where('supplier_id', $supplier)->where('source_type', 'advance_settlement')->count());
    }

    // ═══ HELPERS (mirrors SupplierPaymentTransactionIntegrityTest's own helpers) ═══

    private function postedBill(string $supplier, Account $expense, float $amount): SupplierBill
    {
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

    private function account(AccountType $type): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => strtoupper($type->value[0]).'-'.$this->suffix(),
            'name' => ucfirst($type->value).' account',
            'account_type' => $type,
            'is_postable' => true,
        ]);
    }

    private function openAllPeriods(): void
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
    }
}
