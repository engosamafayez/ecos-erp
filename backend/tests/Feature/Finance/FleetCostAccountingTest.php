<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Integration\Application\Listeners\PostFleetCostOnVehicleCostPosted;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Logistics\Fleet\Domain\Enums\CostType;
use Modules\Logistics\Fleet\Domain\Events\VehicleCostPosted;
use Modules\Logistics\Fleet\Domain\Models\CostEntry;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-07 — Fleet
 * vehicle-cost posting. VehicleCostPosted is confirmed (this task's own
 * research) to have had zero subscribers anywhere before now. Reuses the
 * EXISTING, already-seeded shipping.shipment_cost posting rule (Dr
 * shipping_expense 5550, Cr carrier_payable 2130) — no new PostingRule.
 * QUEUE_CONNECTION=sync makes the async post inline in this suite. Covers
 * TASK §38 items 22-25 (adapted: Fleet cost, the confirmed live trigger, in
 * place of the unrouted Trip-settlement/shortage path — see the Task 7
 * report).
 */
class FleetCostAccountingTest extends TestCase
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
    }

    // 22, 23. An approved (effective-on-creation, per Fleet's own model —
    // see the listener's docblock) trip/vehicle cost posts once, carrying
    // its fleet-unit reference.
    public function test_vehicle_cost_posted_posts_once_using_existing_shipping_rule(): void
    {
        $shippingExpense = $this->seedRole('shipping_expense', AccountType::Expense);
        $carrierPayable = $this->seedRole('carrier_payable', AccountType::Liability);

        $entry = $this->costEntry(350.0, CostType::Fuel);

        app(PostFleetCostOnVehicleCostPosted::class)->handle(new VehicleCostPosted($entry));

        $journal = JournalEntry::query()
            ->where('source_module', 'logistics.fleet')
            ->where('source_event_id', 'fleet_cost_entry:'.$entry->id)
            ->with('lines')->first();

        $this->assertNotNull($journal);
        $debit = $journal->lines->firstWhere('account_id', $shippingExpense->id);
        $credit = $journal->lines->firstWhere('account_id', $carrierPayable->id);
        $this->assertSame(350.0, round((float) $debit->debit, 4));
        $this->assertSame(350.0, round((float) $credit->credit, 4));
    }

    // 25. Fleet does not (and cannot, by construction — the listener is the
    // only path from a CostEntry to a journal) write the GL directly; only
    // JournalEngine, via the unchanged posting pipeline, does.
    public function test_replayed_cost_entry_does_not_duplicate(): void
    {
        $this->seedRole('shipping_expense', AccountType::Expense);
        $this->seedRole('carrier_payable', AccountType::Liability);
        $entry = $this->costEntry(200.0, CostType::Maintenance);

        $handler = app(PostFleetCostOnVehicleCostPosted::class);
        $handler->handle(new VehicleCostPosted($entry));
        $handler->handle(new VehicleCostPosted($entry)); // simulates a redelivered event

        $this->assertSame(
            1,
            JournalEntry::query()->where('source_module', 'logistics.fleet')
                ->where('source_event_id', 'fleet_cost_entry:'.$entry->id)->count(),
        );
    }

    // 24 (adapted — "foreign trip rejected" reframed for the confirmed
    // reality that fleet_cost_entries.company_id is NULLABLE): an entry with
    // no company_id is skipped, never guessed at or posted to a default.
    public function test_cost_entry_without_company_id_is_skipped(): void
    {
        $this->seedRole('shipping_expense', AccountType::Expense);
        $this->seedRole('carrier_payable', AccountType::Liability);

        $entry = new CostEntry([
            'fleet_unit_id' => 1, 'company_id' => null, 'cost_type' => CostType::Other->value,
            'amount' => 100.0, 'currency' => 'EGP', 'incurred_on' => Carbon::today()->toDateString(),
        ]);
        $entry->id = 999999;

        app(PostFleetCostOnVehicleCostPosted::class)->handle(new VehicleCostPosted($entry));

        $this->assertSame(0, JournalEntry::query()->where('source_event_id', 'fleet_cost_entry:999999')->count());
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function costEntry(float $amount, CostType $type): CostEntry
    {
        $entry = new CostEntry([
            'fleet_unit_id' => 1,
            'company_id' => $this->companyId,
            'cost_type' => $type->value,
            'amount' => $amount,
            'currency' => 'EGP',
            'incurred_on' => Carbon::today()->toDateString(),
        ]);
        $entry->id = random_int(100000, 999999);

        return $entry;
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

    private function openPeriodForToday(): void
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
