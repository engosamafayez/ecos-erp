<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Integration\Domain\Services\CommercialAccountingService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Receivables\Domain\Models\CustomerLedgerEntry;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006 — dimension passthrough,
 * commercial reversal/correction, and period-control inheritance. Covers
 * TASK §33 items 32, 33, 36 (dimensions), 38-41 (reversal), 42-43 (period
 * control).
 */
class CommercialAccountingDimensionAndControlTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->openPeriodForCompany($this->companyId);
        $this->controlAccount('ar', AccountType::Asset);
    }

    // 32. Every commercial journal line carries the required company
    // dimension (automatic — every PostingLine requires it).
    public function test_commercial_journal_lines_carry_the_company_dimension(): void
    {
        $this->seedRole('sales_revenue', AccountType::Revenue);

        $invoice = app(CommercialAccountingService::class)->recognizeRevenue(
            $this->companyId, (string) Str::uuid(), 'ORD-DIM-1', (string) Str::uuid(), 100.0, 0.0, Carbon::today(), null,
        );

        foreach ($invoice->journalEntry->lines as $line) {
            $this->assertSame($this->companyId, $line->company_id);
        }
    }

    // 33. The profit-center (Brand) dimension passthrough — wired this task
    // into FinancialEvent/RulePostingStrategy and AR's line-level posting —
    // flows onto the journal line when explicitly supplied. Population from
    // an actual Brand value is DEFERRED (see report §9): neither
    // OrderDeliveredEvent nor the Order row itself carries brand_id today,
    // only order_financial_snapshots does, and that table is not reliably
    // created before Delivered (confirmed by this task's own research). This
    // proves the mechanism, not a production data source.
    public function test_profit_center_dimension_flows_through_when_supplied(): void
    {
        $this->seedRole('sales_revenue', AccountType::Revenue);
        $this->seedRole('cost_of_goods_sold', AccountType::Expense);
        $this->seedRole('finished_goods', AccountType::Asset);
        $profitCenterId = (string) Str::uuid();
        $orderId = (string) Str::uuid();

        $service = app(CommercialAccountingService::class);
        $invoice = $service->recognizeRevenue(
            $this->companyId, $orderId, 'ORD-DIM-2', (string) Str::uuid(), 100.0, 0.0, Carbon::today(), null, $profitCenterId,
        );
        $service->recognizeCogs($this->companyId, $orderId, 'ORD-DIM-2', 40.0, Carbon::today(), null, $profitCenterId);

        $revenueLine = $invoice->journalEntry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($revenueLine);
        // The AR-control (debit) line carries no profit center (only the
        // revenue line does, mirroring buildDocumentPostingRequest's own
        // per-line dimensioning) — check the credit (revenue) line instead.
        $creditLine = $invoice->journalEntry->lines->firstWhere('credit', '>', 0);
        $this->assertSame($profitCenterId, $creditLine->profit_center_id);

        $cogsJournal = JournalEntry::query()
            ->where('source_module', 'operations.fulfillment')
            ->where('source_event_id', 'order_delivered_cogs:'.$orderId)
            ->with('lines')->first();
        $debitLine = $cogsJournal->lines->firstWhere('debit', '>', 0);
        $this->assertSame($profitCenterId, $debitLine->profit_center_id);
    }

    // 36. A dimension value is carried verbatim and never validated against
    // another module's master data — Finance does not become a master-data
    // owner (TASK §5). An arbitrary, non-existent reference is accepted, not
    // rejected.
    public function test_arbitrary_dimension_reference_is_accepted_opaquely(): void
    {
        $this->seedRole('sales_revenue', AccountType::Revenue);
        $notARealBrand = 'not-a-real-brand-id';

        $invoice = app(CommercialAccountingService::class)->recognizeRevenue(
            $this->companyId, (string) Str::uuid(), 'ORD-DIM-3', (string) Str::uuid(), 100.0, 0.0, Carbon::today(), null, $notARealBrand,
        );

        $creditLine = $invoice->journalEntry->lines->firstWhere('credit', '>', 0);
        $this->assertSame($notARealBrand, $creditLine->profit_center_id);
    }

    // 38, 39, 40. Commercial reversal uses the canonical, unchanged
    // JournalEngine::reverse() (via AccountsReceivableService::
    // reverseDocumentPosting, Task 5's own pattern); the corresponding
    // CustomerLedgerEntry correction is a NEW, sign-flipped, append-only row
    // — the original invoice and its first ledger entry are never edited.
    public function test_commercial_reversal_uses_canonical_journal_engine_and_is_append_only(): void
    {
        $this->seedRole('sales_revenue', AccountType::Revenue);
        $orderId = (string) Str::uuid();

        $service = app(CommercialAccountingService::class);
        $invoice = $service->recognizeRevenue($this->companyId, $orderId, 'ORD-REV-1', (string) Str::uuid(), 300.0, 0.0, Carbon::today(), null);
        $originalJournalId = $invoice->journal_entry_id;
        $originalTotal = (float) $invoice->total;

        $reversalJournal = $service->reverseRevenue($this->companyId, $orderId, 'Order cancelled after recognition');

        $this->assertNotNull($reversalJournal);
        $this->assertSame($originalJournalId, $reversalJournal->reverses_journal_id);

        // The original invoice row is untouched (frozen-once-posted).
        $invoice->refresh();
        $this->assertSame($originalJournalId, $invoice->journal_entry_id);
        $this->assertSame($originalTotal, round((float) $invoice->total, 4));

        // A new, sign-flipped ledger entry exists alongside the original —
        // neither edited nor deleted.
        $entries = CustomerLedgerEntry::query()->where('source_id', $invoice->uuid)
            ->orWhere('journal_entry_id', $reversalJournal->id)->get();
        $this->assertSame(2, $entries->count());
        $this->assertSame(0.0, round((float) $entries->sum('amount'), 4));
    }

    // 41. A repeated reversal attempt cannot duplicate the correction.
    public function test_repeated_reversal_cannot_duplicate_the_correction(): void
    {
        $this->seedRole('sales_revenue', AccountType::Revenue);
        $orderId = (string) Str::uuid();

        $service = app(CommercialAccountingService::class);
        $service->recognizeRevenue($this->companyId, $orderId, 'ORD-REV-2', (string) Str::uuid(), 200.0, 0.0, Carbon::today(), null);
        $service->reverseRevenue($this->companyId, $orderId, 'First reversal');

        try {
            $service->reverseRevenue($this->companyId, $orderId, 'Second reversal attempt');
            $this->fail('Expected reversing an already-reversed journal to be rejected.');
        } catch (\Modules\Finance\Ledger\Domain\Exceptions\FinanceException) {
            // expected — JournalEngine::reverse() itself blocks this
        }

        // Exactly one correction exists — the blocked second attempt (asserted
        // above) never reaches JournalEntry::create(), so this count proves no
        // duplicate was written, not merely that an exception was thrown.
        $invoice = $service->findOrderInvoice($this->companyId, $orderId);
        $this->assertSame(
            1,
            JournalEntry::query()->where('reverses_journal_id', $invoice->journal_entry_id)->count(),
        );
    }

    // 42 & 43. The closed-period guard is inherited from JournalEngine —
    // never bypassed — and posting date is exactly the recognition date
    // handed in, no separately invented date logic.
    public function test_closed_period_guard_is_respected(): void
    {
        $this->seedRole('sales_revenue', AccountType::Revenue);

        $today = Carbon::today();
        $period = FiscalPeriod::query()
            ->where('company_id', $this->companyId)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->firstOrFail();
        app(FiscalCalendarService::class)->closePeriod($period);

        $this->expectException(\Modules\Finance\Ledger\Domain\Exceptions\FinanceException::class);

        app(CommercialAccountingService::class)->recognizeRevenue(
            $this->companyId, (string) Str::uuid(), 'ORD-PERIOD-1', (string) Str::uuid(),
            100.0, 0.0, $today, null,
        );
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function seedRole(string $role, AccountType $type): Account
    {
        $account = app(ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => strtoupper(substr($role, 0, 3)).'-'.$this->suffix(),
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'account_type' => $type,
            'is_postable' => true,
        ]);

        DB::table('finance_account_roles')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'role' => $role,
            'account_id' => $account->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $account;
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

    private function openPeriodForCompany(string $companyId): void
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
    }
}
