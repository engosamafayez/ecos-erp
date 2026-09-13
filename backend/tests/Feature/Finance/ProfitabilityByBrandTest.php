<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Intelligence\Domain\Services\ProfitabilityService;
use Modules\Finance\Ledger\Domain\Enums\AccountCategory;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Ledger\Domain\Services\JournalEngine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingLine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingRequest;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-FIN-04-BRAND-PROFITABILITY-IMPLEMENTATION-007 —
 * ProfitabilityService::byBrand(): the reconciliation invariant
 * (Σrows + unallocated == total, exactly), Brand label resolution
 * (including an id that resolves to no current Brand), the CostAllocation
 * overlay, and company/tenant isolation.
 *
 * Posts directly through JournalEngine (bypassing Commerce/the posting
 * bridge entirely) so every figure in a test is exact and known up front —
 * the same idiom FleetCostAccountingTest and
 * CommercialAccountingDimensionAndControlTest already use.
 *
 * Written for later consolidated execution — not run by this task (would
 * require a live connection to the shared test database).
 */
class ProfitabilityByBrandTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    private Account $revenue;

    private Account $expense;

    private Account $clearing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->openPeriodForCompany($this->companyId);

        $this->revenue = $this->account('REV', AccountType::Revenue, AccountCategory::OperatingRevenue);
        $this->expense = $this->account('EXP', AccountType::Expense, AccountCategory::OperatingExpense);
        // Balancing side for every manual entry below; deliberately no
        // account_category, so it is never itself picked up as revenue/expense.
        $this->clearing = $this->account('CLR', AccountType::Asset, null);
    }

    // 13, 15. A straightforward mix of two Brands and an unallocated slice —
    // the base case every other assertion below builds on. Company rollup
    // (company()) is unaffected: it never reads profit_center_id at all.
    public function test_brand_breakdown_reconciles_exactly_to_company_total(): void
    {
        $brand1 = Brand::factory()->create(['company_id' => $this->companyId]);
        $brand2 = Brand::factory()->create(['company_id' => $this->companyId]);

        $this->postRevenue(1000.0, (string) $brand1->id);
        $this->postRevenue(500.0, (string) $brand2->id);
        $this->postRevenue(300.0, null);
        $this->postExpense(400.0, (string) $brand1->id);
        $this->postExpense(100.0, null);

        $result = app(ProfitabilityService::class)->byBrand($this->companyId, $this->windowFrom(), $this->windowTo());

        $byId = collect($result['rows'])->keyBy('brand_id');
        $this->assertSame(1000.0, $byId[(string) $brand1->id]['revenue']);
        $this->assertSame(400.0, $byId[(string) $brand1->id]['expense']);
        $this->assertTrue($byId[(string) $brand1->id]['resolved']);
        $this->assertSame($brand1->name, $byId[(string) $brand1->id]['brand_name']);

        $this->assertSame(500.0, $byId[(string) $brand2->id]['revenue']);
        $this->assertSame(0.0, $byId[(string) $brand2->id]['expense']);

        $this->assertSame(300.0, $result['unallocated']['revenue']);
        $this->assertSame(100.0, $result['unallocated']['expense']);

        $this->assertSame(1800.0, $result['total']['revenue']);
        $this->assertSame(500.0, $result['total']['expense']);

        // The invariant itself, not just the individual figures.
        $rowRevenue = collect($result['rows'])->sum('revenue');
        $rowExpense = collect($result['rows'])->sum('expense');
        $this->assertEqualsWithDelta($result['total']['revenue'], $rowRevenue + $result['unallocated']['revenue'], 0.0001);
        $this->assertEqualsWithDelta($result['total']['expense'], $rowExpense + $result['unallocated']['expense'], 0.0001);

        // Company rollup (a different, wider-scope figure) is untouched by
        // any of this — same value it always was.
        $company = app(ProfitabilityService::class)->company($this->companyId, $this->windowFrom(), $this->windowTo());
        $this->assertSame(1800.0, $company['revenue']);
    }

    // 12. A profit_center_id that does not resolve to any current Brand of
    // this company is never dropped — its amount is kept and counted in
    // `total`; only its label is honest about being unresolved.
    public function test_unresolved_brand_id_is_kept_not_dropped(): void
    {
        $unknownId = (string) Str::uuid(); // deliberately no Brand row for this id
        $this->postRevenue(750.0, $unknownId);

        $result = app(ProfitabilityService::class)->byBrand($this->companyId, $this->windowFrom(), $this->windowTo());

        $row = collect($result['rows'])->firstWhere('brand_id', $unknownId);
        $this->assertNotNull($row);
        $this->assertFalse($row['resolved']);
        $this->assertNull($row['brand_name']);
        $this->assertSame(750.0, $row['revenue']);
        $this->assertSame(750.0, $result['total']['revenue']);
    }

    // 17, 18. A company-level expense with an explicit CostAllocation
    // destination appears under that Brand; the remainder — never claimed by
    // any allocation — stays Unallocated. Reconciliation still holds exactly.
    public function test_cost_allocation_overlay_moves_the_allocated_share_out_of_unallocated(): void
    {
        $brand = Brand::factory()->create(['company_id' => $this->companyId]);

        // One shared, company-level expense posted to the GL with no Brand
        // dimension at all (350 total) — 250 of it explicitly allocated to
        // $brand via the existing, unchanged CostAllocationService ledger.
        $this->postExpense(350.0, null);
        $this->insertCostAllocation($brand, allocatedAmount: 250.0, sourceAmount: 350.0);

        $result = app(ProfitabilityService::class)->byBrand($this->companyId, $this->windowFrom(), $this->windowTo());

        $row = collect($result['rows'])->firstWhere('brand_id', (string) $brand->id);
        $this->assertSame(250.0, $row['expense']);
        $this->assertSame(100.0, $result['unallocated']['expense']); // 350 - 250
        $this->assertSame(350.0, $result['total']['expense']);

        $rowExpense = collect($result['rows'])->sum('expense');
        $this->assertEqualsWithDelta($result['total']['expense'], $rowExpense + $result['unallocated']['expense'], 0.0001);
    }

    // A reversed cost allocation (the append-only, negative contra-row
    // CostAllocationService::reverseAllocation() writes) nets to zero — the
    // whole original expense reverts to Unallocated, exactly as if no
    // allocation had ever been made. No invented "undo" logic: a plain SUM.
    public function test_reversed_cost_allocation_nets_back_to_unallocated(): void
    {
        $brand = Brand::factory()->create(['company_id' => $this->companyId]);
        $this->postExpense(350.0, null);
        $original = $this->insertCostAllocation($brand, allocatedAmount: 250.0, sourceAmount: 350.0);
        $this->insertCostAllocation($brand, allocatedAmount: -250.0, sourceAmount: 350.0, reversesId: $original);

        $result = app(ProfitabilityService::class)->byBrand($this->companyId, $this->windowFrom(), $this->windowTo());

        $row = collect($result['rows'])->firstWhere('brand_id', (string) $brand->id);
        $this->assertSame(0.0, $row['expense']);
        $this->assertSame(350.0, $result['unallocated']['expense']);
    }

    // 16. Existing dimensions (branch/cost-center/project) are untouched by
    // this method's existence — byBrand() is purely additive, on a
    // completely separate column.
    public function test_existing_dimensions_remain_unaffected(): void
    {
        $brand = Brand::factory()->create(['company_id' => $this->companyId]);
        $this->postRevenue(200.0, (string) $brand->id);

        $branch = app(ProfitabilityService::class)->byBranch($this->companyId, $this->windowFrom(), $this->windowTo());
        $this->assertSame([], $branch['rows']); // no branch_id was ever set on any line above
    }

    // 19, 20. Company A's Brand profitability and Brand metadata are never
    // visible to Company B, and vice versa.
    public function test_company_isolation_is_preserved(): void
    {
        $otherCompany = Company::factory()->create();
        $otherRevenue = $this->account('REV-B', AccountType::Revenue, AccountCategory::OperatingRevenue, $otherCompany->id);
        $this->openPeriodForCompany((string) $otherCompany->id);
        $otherBrand = Brand::factory()->create(['company_id' => $otherCompany->id]);

        $this->postRevenue(999.0, (string) $otherBrand->id, companyId: (string) $otherCompany->id, account: $otherRevenue);

        $brand = Brand::factory()->create(['company_id' => $this->companyId]);
        $this->postRevenue(111.0, (string) $brand->id);

        $result = app(ProfitabilityService::class)->byBrand($this->companyId, $this->windowFrom(), $this->windowTo());

        $this->assertCount(1, $result['rows']);
        $this->assertSame((string) $brand->id, $result['rows'][0]['brand_id']);
        $this->assertSame(111.0, $result['total']['revenue']);
        // Company B's brand id never even appears as an unresolved row —
        // it was never in Company A's own journal lines to begin with.
        $this->assertNull(collect($result['rows'])->firstWhere('brand_id', (string) $otherBrand->id));
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function windowFrom(): Carbon
    {
        return Carbon::today()->subMonths(3)->startOfMonth();
    }

    private function windowTo(): Carbon
    {
        return Carbon::today();
    }

    private function postRevenue(float $amount, ?string $brandId, ?string $companyId = null, ?Account $account = null): void
    {
        $companyId ??= $this->companyId;
        $account ??= $this->revenue;

        app(JournalEngine::class)->post(new PostingRequest(
            companyId: $companyId,
            entryDate: Carbon::today(),
            lines: [
                PostingLine::debit((int) $this->clearing->id, $amount, $companyId),
                PostingLine::credit((int) $account->id, $amount, $companyId, ['profitCenterId' => $brandId]),
            ],
            reference: 'TEST-REV-'.$this->suffix(),
            description: 'Test revenue',
        ));
    }

    private function postExpense(float $amount, ?string $brandId): void
    {
        app(JournalEngine::class)->post(new PostingRequest(
            companyId: $this->companyId,
            entryDate: Carbon::today(),
            lines: [
                PostingLine::debit((int) $this->expense->id, $amount, $this->companyId, ['profitCenterId' => $brandId]),
                PostingLine::credit((int) $this->clearing->id, $amount, $this->companyId),
            ],
            reference: 'TEST-EXP-'.$this->suffix(),
            description: 'Test expense',
        ));
    }

    private function insertCostAllocation(Brand $brand, float $allocatedAmount, float $sourceAmount, ?int $reversesId = null): int
    {
        return (int) DB::table('finance_cost_allocations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'source_type' => 'expense',
            'source_id' => (string) Str::uuid(),
            'source_amount' => $sourceAmount,
            'method' => 'fixed',
            'destination_profit_center_id' => (string) $brand->id,
            'allocated_amount' => $allocatedAmount,
            'reverses_allocation_id' => $reversesId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function account(string $codePrefix, AccountType $type, ?AccountCategory $category, ?string $companyId = null): Account
    {
        $attrs = [
            'company_id' => $companyId ?? $this->companyId,
            'code' => $codePrefix.'-'.$this->suffix(),
            'name' => $codePrefix,
            'account_type' => $type,
            'is_postable' => true,
        ];
        if ($category !== null) {
            $attrs['account_category'] = $category;
        }

        return app(ChartOfAccountsService::class)->create($attrs);
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
