<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\OrderFinancialSnapshot;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Integration\Application\Listeners\PostRevenueAndCogsOnOrderDelivered;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Operations\Fulfillment\Domain\Events\OrderDeliveredEvent;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-FIN-04-BRAND-PROFITABILITY-IMPLEMENTATION-007 — the
 * listener-level wiring of OrderFinancialSnapshot.brand_id into
 * profit_center_id on NEW Commerce revenue/COGS postings. The underlying
 * mechanism (CommercialAccountingService accepting and carrying a
 * $profitCenterId through to the journal line) was already proven by
 * CommercialAccountingDimensionAndControlTest — this file tests only what
 * this task added: the listener actually READING a real Brand id from the
 * order's own snapshot, and doing nothing different when one isn't there.
 *
 * Written for later consolidated execution — not run by this task (would
 * require a live connection to the shared test database).
 */
class CommerceOrderBrandAttributionListenerTest extends TestCase
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
        $this->seedRole('sales_revenue', AccountType::Revenue);
        $this->seedRole('cost_of_goods_sold', AccountType::Expense);
        $this->seedRole('finished_goods', AccountType::Asset);
    }

    // 1, 5, 6. A new Commerce posting, for an order whose OrderFinancialSnapshot
    // carries a brand_id, writes that brand_id as profit_center_id on both the
    // revenue line and the COGS line.
    public function test_revenue_and_cogs_postings_carry_the_snapshot_brand_id(): void
    {
        $brandId = (string) Str::uuid();
        $orderId = $this->makeOrder();
        $this->makeSnapshot($orderId, $brandId);

        app(PostRevenueAndCogsOnOrderDelivered::class)->handle($this->deliveredEvent($orderId, cogsAmount: 40.0));

        $invoice = \Modules\Finance\Receivables\Domain\Models\CustomerInvoice::query()
            ->where('company_id', $this->companyId)->where('source_type', 'order')->where('source_id', $orderId)->firstOrFail();
        $creditLine = $invoice->journalEntry->lines->firstWhere('credit', '>', 0);
        $this->assertSame($brandId, $creditLine->profit_center_id);

        $cogsJournal = JournalEntry::query()
            ->where('source_module', 'operations.fulfillment')
            ->where('source_event_id', 'order_delivered_cogs:'.$orderId)
            ->with('lines')->firstOrFail();
        $debitLine = $cogsJournal->lines->firstWhere('debit', '>', 0);
        $this->assertSame($brandId, $debitLine->profit_center_id);
    }

    // 2. An order with no snapshot (or a snapshot with no brand_id) posts
    // exactly as it always has — profit_center_id null, no error, no
    // different behavior. Confirms the "no snapshot at delivery" case this
    // module's own prior test (CommercialAccountingDimensionAndControlTest)
    // already flagged as a real, non-hypothetical scenario is handled safely.
    public function test_order_without_a_resolvable_brand_posts_unallocated(): void
    {
        $orderId = $this->makeOrder();
        // Deliberately no OrderFinancialSnapshot row created at all.

        app(PostRevenueAndCogsOnOrderDelivered::class)->handle($this->deliveredEvent($orderId, cogsAmount: 0.0));

        $invoice = \Modules\Finance\Receivables\Domain\Models\CustomerInvoice::query()
            ->where('company_id', $this->companyId)->where('source_type', 'order')->where('source_id', $orderId)->firstOrFail();
        $creditLine = $invoice->journalEntry->lines->firstWhere('credit', '>', 0);
        $this->assertNull($creditLine->profit_center_id);
    }

    // 3, 4. Replaying the same Delivered event never creates a second
    // invoice/journal — Brand attribution changes WHAT is written, never
    // the exactly-once guarantee itself (unchanged PostingCoordinator /
    // AccountsReceivableService idempotency).
    public function test_replayed_event_does_not_bypass_idempotency(): void
    {
        $brandId = (string) Str::uuid();
        $orderId = $this->makeOrder();
        $this->makeSnapshot($orderId, $brandId);
        $event = $this->deliveredEvent($orderId, cogsAmount: 10.0);

        $handler = app(PostRevenueAndCogsOnOrderDelivered::class);
        $handler->handle($event);
        $handler->handle($event);

        $this->assertSame(
            1,
            \Modules\Finance\Receivables\Domain\Models\CustomerInvoice::query()
                ->where('company_id', $this->companyId)->where('source_type', 'order')->where('source_id', $orderId)->count(),
        );
        $this->assertSame(
            1,
            JournalEntry::query()->where('source_event_id', 'order_delivered_cogs:'.$orderId)->count(),
        );
    }

    // 7. Canonical reversal preserves the same Brand dimension — proven here
    // at the listener's own entry point, complementing the lower-level proof
    // already in CommercialAccountingDimensionAndControlTest.
    public function test_reversal_preserves_the_brand_dimension(): void
    {
        $brandId = (string) Str::uuid();
        $orderId = $this->makeOrder();
        $this->makeSnapshot($orderId, $brandId);
        app(PostRevenueAndCogsOnOrderDelivered::class)->handle($this->deliveredEvent($orderId, cogsAmount: 0.0));

        $service = app(\Modules\Finance\Integration\Domain\Services\CommercialAccountingService::class);
        $reversal = $service->reverseRevenue($this->companyId, $orderId, 'Order cancelled');

        $reversedCreditLine = $reversal->lines->firstWhere('debit', '>', 0); // revenue flips to a debit on reversal
        $this->assertSame($brandId, $reversedCreditLine->profit_center_id);
    }

    // 8. No historical journal is touched or rewritten by any of the above —
    // each test creates and inspects only its own, brand-new order/journal;
    // nothing here updates an existing finance_journal_lines row (the model
    // has no update path at all, enforced by JournalEngine itself).

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function makeOrder(): string
    {
        $customer = Customer::factory()->create(['company_id' => $this->companyId]);
        $orderId = (string) Str::uuid();

        DB::table('orders')->insert([
            'id' => $orderId,
            'company_id' => $this->companyId,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-BRAND-'.$this->suffix(),
            'order_date' => Carbon::today()->toDateString(),
            'status' => 'delivered',
            'tax_total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    private function makeSnapshot(string $orderId, string $brandId): OrderFinancialSnapshot
    {
        return OrderFinancialSnapshot::create([
            'order_id' => $orderId,
            'company_id' => $this->companyId,
            'brand_id' => $brandId,
            'currency' => 'EGP',
            'subtotal' => 100.0,
            'grand_total' => 100.0,
            'snapshot_uuid' => (string) Str::uuid(),
            'snapshot_version' => 1,
            'pricing_engine_version' => 'test',
            'cost_engine_version' => 'test',
        ]);
    }

    private function deliveredEvent(string $orderId, float $cogsAmount): OrderDeliveredEvent
    {
        return new OrderDeliveredEvent(
            orderId: $orderId,
            orderNumber: 'ORD-BRAND-'.substr($orderId, 0, 8),
            companyId: $this->companyId,
            revenue: 100.0,
            cogsAmount: $cogsAmount,
            marginAmount: 100.0 - $cogsAmount,
            marginPercent: null,
            deliveredAt: Carbon::now()->toIso8601String(),
            actorId: null,
        );
    }

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
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'role' => $role,
            'account_id' => $account->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $account;
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
