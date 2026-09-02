<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Integration\Domain\Services\CommercialAccountingService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006 — COGS recognition on
 * commercial delivery. Posted through the existing F3 rule-driven bridge
 * (BusinessEventType::DeliveryConfirmation + the new
 * shipping.delivery_confirmation posting rule) — a bare GL movement, no
 * subledger document. QUEUE_CONNECTION=sync in phpunit.xml makes
 * FinancialIntegrationService::recordAsync() post inline in this suite, so
 * these assertions run against the real, already-posted journal. Covers TASK
 * §33 items 8-13.
 */
class CommercialAccountingCogsTest extends TestCase
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
    }

    // 8 & 11 & 12. Delivered recognises COGS once, using the seeded
    // cost_of_goods_sold / finished_goods role mapping — Dr COGS, Cr FG.
    public function test_delivered_order_recognizes_cogs_once_with_account_mapping(): void
    {
        $cogsAccount = $this->seedRole('cost_of_goods_sold', AccountType::Expense);
        $fgAccount = $this->seedRole('finished_goods', AccountType::Asset);
        $orderId = (string) Str::uuid();

        app(CommercialAccountingService::class)->recognizeCogs(
            companyId: $this->companyId,
            orderId: $orderId,
            orderNumber: 'ORD-7007',
            cogsAmount: 640.0,
            recognizedAt: Carbon::today(),
            actorId: null,
        );

        $journal = $this->cogsJournal($orderId);
        $this->assertNotNull($journal);

        $debit = $journal->lines->firstWhere('account_id', $cogsAccount->id);
        $credit = $journal->lines->firstWhere('account_id', $fgAccount->id);
        $this->assertNotNull($debit);
        $this->assertNotNull($credit);
        $this->assertSame(640.0, round((float) $debit->debit, 4));
        $this->assertSame(640.0, round((float) $credit->credit, 4));
    }

    // 9 & 10. COGS uses exactly the historical cost basis handed to it — a
    // later call for the SAME order with a different amount (representing a
    // subsequent product-cost change) does not rewrite the already-posted
    // journal; idempotency wins, so history is immutable.
    public function test_later_cost_change_does_not_mutate_historical_cogs(): void
    {
        $this->seedRole('cost_of_goods_sold', AccountType::Expense);
        $this->seedRole('finished_goods', AccountType::Asset);
        $orderId = (string) Str::uuid();

        $service = app(CommercialAccountingService::class);
        $service->recognizeCogs($this->companyId, $orderId, 'ORD-8008', 500.0, Carbon::today(), null);
        $service->recognizeCogs($this->companyId, $orderId, 'ORD-8008', 999.0, Carbon::today(), null);

        $journal = $this->cogsJournal($orderId);
        $this->assertSame(500.0, round((float) $journal->lines->sum('debit'), 4));
    }

    // 13. Replay does not duplicate the COGS journal.
    public function test_replayed_cogs_recognition_does_not_duplicate(): void
    {
        $this->seedRole('cost_of_goods_sold', AccountType::Expense);
        $this->seedRole('finished_goods', AccountType::Asset);
        $orderId = (string) Str::uuid();

        $service = app(CommercialAccountingService::class);
        $service->recognizeCogs($this->companyId, $orderId, 'ORD-9009', 250.0, Carbon::today(), null);
        $service->recognizeCogs($this->companyId, $orderId, 'ORD-9009', 250.0, Carbon::today(), null);

        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('source_module', 'operations.fulfillment')
                ->where('source_event_id', 'order_delivered_cogs:'.$orderId)
                ->count(),
        );
    }

    // A zero-cost order (e.g. no shippable cost basis) posts nothing.
    public function test_zero_cogs_posts_nothing(): void
    {
        $this->seedRole('cost_of_goods_sold', AccountType::Expense);
        $this->seedRole('finished_goods', AccountType::Asset);
        $orderId = (string) Str::uuid();

        app(CommercialAccountingService::class)->recognizeCogs(
            $this->companyId, $orderId, 'ORD-1010', 0.0, Carbon::today(), null,
        );

        $this->assertNull($this->cogsJournal($orderId));
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function cogsJournal(string $orderId): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_module', 'operations.fulfillment')
            ->where('source_event_id', 'order_delivered_cogs:'.$orderId)
            ->with('lines')
            ->first();
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
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'role' => $role,
            'account_id' => $account->id,
            'created_at' => now(),
            'updated_at' => now(),
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
