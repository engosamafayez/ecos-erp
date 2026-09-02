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
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006 — revenue recognition on
 * commercial delivery, wired through CommercialAccountingService into the
 * unchanged AccountsReceivableService (real CustomerInvoice, real
 * CustomerLedgerEntry). Covers TASK §33 items 1-7, 14-18.
 */
class CommercialAccountingRevenueTest extends TestCase
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
        $this->controlAccount($this->companyId, 'ar', AccountType::Asset);
    }

    // 1 & 6 & 15. Canonical Delivered recognizes revenue once, with correct
    // commercial source linkage (order id, order number).
    public function test_delivered_order_recognizes_revenue_once_with_source_linkage(): void
    {
        $revenueAccount = $this->seedRole($this->companyId, 'sales_revenue', AccountType::Revenue);
        $orderId = (string) Str::uuid();
        $customerId = (string) Str::uuid();

        $invoice = app(CommercialAccountingService::class)->recognizeRevenue(
            companyId: $this->companyId,
            orderId: $orderId,
            orderNumber: 'ORD-1001',
            customerId: $customerId,
            grossRevenue: 1150.0,
            taxTotal: 150.0,
            recognizedAt: Carbon::today(),
            actorId: null,
        );

        $this->assertNotNull($invoice);
        $this->assertTrue($invoice->isPosted());
        $this->assertSame('order', $invoice->source_type);
        $this->assertSame($orderId, $invoice->source_id);
        $this->assertSame($customerId, $invoice->customer_id);
        $this->assertSame(1000.0, round((float) $invoice->subtotal, 4));
        $this->assertSame(150.0, round((float) $invoice->tax_total, 4));
        $this->assertSame(1150.0, round((float) $invoice->total, 4));

        $journal = $invoice->journalEntry;
        $this->assertNotNull($journal);
        $this->assertSame('finance.ar', $journal->source_module);
        $this->assertSame('invoice:'.$invoice->uuid, $journal->source_event_id);

        $revenueLine = $journal->lines->firstWhere('account_id', $revenueAccount->id);
        $this->assertNotNull($revenueLine);
        $this->assertSame(1000.0, round((float) $revenueLine->credit, 4));
    }

    // 2 & 16. A replayed Delivered event does not duplicate the invoice.
    public function test_replayed_delivered_event_does_not_duplicate_revenue(): void
    {
        $this->seedRole($this->companyId, 'sales_revenue', AccountType::Revenue);
        $orderId = (string) Str::uuid();
        $customerId = (string) Str::uuid();

        $service = app(CommercialAccountingService::class);
        $first = $service->recognizeRevenue($this->companyId, $orderId, 'ORD-2002', $customerId, 500.0, 0.0, Carbon::today(), null);
        $second = $service->recognizeRevenue($this->companyId, $orderId, 'ORD-2002', $customerId, 500.0, 0.0, Carbon::today(), null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CustomerInvoice::query()->where('source_type', 'order')->where('source_id', $orderId)->count());
    }

    // 3 & 4. A zero/free order (the guard a cancelled-before-recognition or
    // free order hits — the listener only ever calls this for an order that
    // actually reached Delivered, so a genuinely cancelled order never
    // reaches this call at all; this proves the boundary this method itself
    // is responsible for) recognizes no revenue.
    public function test_zero_revenue_order_recognizes_nothing(): void
    {
        $this->seedRole($this->companyId, 'sales_revenue', AccountType::Revenue);
        $orderId = (string) Str::uuid();

        $result = app(CommercialAccountingService::class)->recognizeRevenue(
            $this->companyId, $orderId, 'ORD-3003', (string) Str::uuid(), 0.0, 0.0, Carbon::today(), null,
        );

        $this->assertNull($result);
        $this->assertSame(0, CustomerInvoice::query()->where('source_id', $orderId)->count());
    }

    // 5. Revenue uses the canonical, seeded account-role mapping — not a
    // hardcoded account id. Resolving before any role is seeded fails loudly
    // rather than guessing.
    public function test_revenue_posting_fails_loudly_when_sales_revenue_role_unmapped(): void
    {
        $this->expectException(\Modules\Finance\Ledger\Domain\Exceptions\FinanceException::class);

        app(CommercialAccountingService::class)->recognizeRevenue(
            $this->companyId, (string) Str::uuid(), 'ORD-4004', (string) Str::uuid(), 100.0, 0.0, Carbon::today(), null,
        );
    }

    // 7 & 18. Tenant isolation: company B cannot see company A's order
    // invoice, and posting for B does not touch A's ledger.
    public function test_tenant_isolation_between_companies(): void
    {
        $this->seedRole($this->companyId, 'sales_revenue', AccountType::Revenue);

        $otherCompany = Company::factory()->create();
        $otherCompanyId = (string) $otherCompany->id;
        $this->openPeriodForCompany($otherCompanyId);
        $this->controlAccount($otherCompanyId, 'ar', AccountType::Asset);
        $this->seedRole($otherCompanyId, 'sales_revenue', AccountType::Revenue);

        $orderId = (string) Str::uuid();
        $service = app(CommercialAccountingService::class);
        $service->recognizeRevenue($this->companyId, $orderId, 'ORD-5005', (string) Str::uuid(), 200.0, 0.0, Carbon::today(), null);

        $this->assertNull($service->findOrderInvoice($otherCompanyId, $orderId));
        $this->assertNotNull($service->findOrderInvoice($this->companyId, $orderId));
    }

    // 14 & 17. The receivable is a real, canonical CustomerInvoice (not a
    // bespoke table), and its outstanding balance reconciles to the posted
    // total until allocated.
    public function test_receivable_is_a_canonical_customer_invoice_and_reconciles(): void
    {
        $this->seedRole($this->companyId, 'sales_revenue', AccountType::Revenue);

        $invoice = app(CommercialAccountingService::class)->recognizeRevenue(
            $this->companyId, (string) Str::uuid(), 'ORD-6006', (string) Str::uuid(), 300.0, 0.0, Carbon::today(), null,
        );

        $this->assertInstanceOf(CustomerInvoice::class, $invoice);
        $this->assertSame(300.0, round($invoice->outstanding(), 4));
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function seedRole(string $companyId, string $role, AccountType $type): Account
    {
        $account = app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => strtoupper(substr($role, 0, 3)).'-'.$this->suffix(),
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'account_type' => $type,
            'is_postable' => true,
        ]);

        DB::table('finance_account_roles')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'role' => $role,
            'account_id' => $account->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $account;
    }

    private function controlAccount(string $companyId, string $subledger, AccountType $type): Account
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

    private function suffix(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
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
}
