<?php

declare(strict_types=1);

namespace Modules\Finance\Intelligence\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Analytics\Domain\Services\FinancialMetricsService;
use Modules\Finance\Ledger\Domain\Enums\AccountCategory;
use Modules\Organization\Brands\Domain\Models\Brand;

/**
 * Profitability analysis — derived from the ledger's own dimensions, never a
 * duplicated calculation.
 *
 * Company, branch and cost-center profitability come straight from the signed
 * journal-line dimensions. Customer profitability is revenue attributed from the
 * AR subledger with the company operating margin applied — the attribution
 * method is stated, not hidden. Product/channel are exposed as dimension-ready:
 * the ledger does not tag lines by product or channel, so their profitability
 * is company-level until those tags exist (surfaced honestly, not fabricated).
 */
final class ProfitabilityService
{
    private array $expenseCategories;

    private array $revenueCategories;

    public function __construct(private readonly FinancialMetricsService $metrics)
    {
        $this->revenueCategories = [AccountCategory::OperatingRevenue];
        $this->expenseCategories = [AccountCategory::CostOfSales, AccountCategory::OperatingExpense];
    }

    /** @return array<string, mixed> */
    public function company(string $companyId, Carbon $from, Carbon $to): array
    {
        $pnl = $this->metrics->profitAndLoss($companyId, $from, $to);

        return [
            'dimension' => 'company',
            'revenue' => $pnl['total_revenue'],
            'expense' => round($pnl['cost_of_sales'] + $pnl['operating_expense'] + $pnl['other_expense'], 4),
            'profit' => $pnl['net_profit'],
            'margin_pct' => $pnl['net_margin_pct'],
        ];
    }

    /** @return array<string, mixed> */
    public function byBranch(string $companyId, Carbon $from, Carbon $to): array
    {
        return $this->byDimension($companyId, 'branch_id', 'branch', $from, $to);
    }

    /** @return array<string, mixed> */
    public function byCostCenter(string $companyId, Carbon $from, Carbon $to): array
    {
        return $this->byDimension($companyId, 'cost_center_id', 'cost_center', $from, $to);
    }

    /** @return array<string, mixed> */
    public function byProject(string $companyId, Carbon $from, Carbon $to): array
    {
        return $this->byDimension($companyId, 'project_id', 'project', $from, $to);
    }

    /**
     * Brand profitability (TASK-ECOS-V1.1-FIN-04-BRAND-PROFITABILITY-
     * IMPLEMENTATION-007) — the approved convention is `profit_center_id` =
     * a Brand's own id, so this reads the same generic dimensioned activity
     * {@see byDimension()} does, but adds three things that dimension alone
     * cannot: resolved Brand names (company-scoped, never cross-tenant), an
     * explicit Unallocated figure so nothing posted without a Brand goes
     * missing from the total, and the ALREADY-COMPUTED {@see CostAllocation}
     * overlay — a directly-dimensioned journal line and an explicitly
     * allocated share of a company-level expense are both "this Brand's
     * cost," and a Brand view that only read journal lines would silently
     * treat the second kind as still-unallocated.
     *
     * Unallocated is computed as the SAME-SCOPE company total (via
     * {@see FinancialMetricsService::categoryTotal()}, reading the identical
     * categories/status/date-range predicates as the dimensioned rows) minus
     * the sum of those rows and minus the net cost-allocation total — never a
     * second, independently-filtered query that could silently drift from
     * the breakdown it is meant to reconcile against. `rows + unallocated ==
     * total` holds by construction, not by convention, either way.
     *
     * No allocation is computed or guessed here: `finance_cost_allocations`
     * is read exactly as {@see CostAllocationService} already wrote it
     * (explicit destinations and amounts, reversals already netted by a
     * plain SUM — the same pattern its own `effectiveAllocatedAmount()`
     * uses); this method only reads that ledger, never adds to it.
     *
     * @return array<string, mixed>
     */
    public function byBrand(string $companyId, Carbon $from, Carbon $to): array
    {
        $revenue = $this->metrics->activityByDimension($companyId, $this->revenueCategories, 'profit_center_id', $from, $to);
        $expense = $this->metrics->activityByDimension($companyId, $this->expenseCategories, 'profit_center_id', $from, $to);
        $allocations = $this->costAllocationsByBrand($companyId, $from, $to);

        $keys = array_unique(array_merge(array_keys($revenue), array_keys($expense), array_keys($allocations)));
        $brands = $keys === []
            ? collect()
            : Brand::query()->where('company_id', $companyId)->whereIn('id', $keys)->get(['id', 'name', 'code'])
                ->keyBy(static fn (Brand $b): string => (string) $b->id);

        $rows = [];
        $allocatedRevenue = 0.0;
        $allocatedExpense = 0.0;
        $totalCostAllocations = 0.0;

        foreach ($keys as $dim) {
            $rev = $revenue[$dim] ?? 0.0;
            // Directly-dimensioned expense plus this Brand's explicitly
            // allocated share of a company-level one — two different
            // sources of the same fact: "this Brand's cost."
            $directExp = $expense[$dim] ?? 0.0;
            $allocatedToThisBrand = $allocations[$dim] ?? 0.0;
            $exp = $directExp + $allocatedToThisBrand;

            $allocatedRevenue += $rev;
            $allocatedExpense += $directExp;
            $totalCostAllocations += $allocatedToThisBrand;

            $profit = round($rev - $exp, 4);
            $brand = $brands->get($dim);

            $rows[] = [
                'brand_id' => $dim,
                // Null when the id does not resolve to a current Brand of this
                // company (deleted, or never one) — the amount is still kept
                // and still counted in `total`; only the label is honest.
                'brand_name' => $brand?->name,
                'brand_code' => $brand?->code,
                'resolved' => $brand !== null,
                'revenue' => round($rev, 4),
                'expense' => round($exp, 4),
                'profit' => $profit,
                'margin_pct' => $rev !== 0.0 ? round($profit / $rev * 100, 2) : 0.0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['profit'] <=> $a['profit']);

        $totalRevenue = $this->metrics->categoryTotal($companyId, $this->revenueCategories, $from, $to);
        $totalExpense = $this->metrics->categoryTotal($companyId, $this->expenseCategories, $from, $to);
        $unallocatedRevenue = round($totalRevenue - $allocatedRevenue, 4);
        // A cost-allocation's source expense is already company-level in the
        // GL (no profit_center_id) — its allocated share must come OUT of
        // Unallocated exactly once, the same amount just added to its Brand
        // row above, or Σrows + unallocated would double-count it.
        $unallocatedExpense = round($totalExpense - $allocatedExpense - $totalCostAllocations, 4);
        $unallocatedProfit = round($unallocatedRevenue - $unallocatedExpense, 4);
        $totalProfit = round($totalRevenue - $totalExpense, 4);

        return [
            'dimension' => 'brand',
            'rows' => $rows,
            'unallocated' => [
                'revenue' => $unallocatedRevenue,
                'expense' => $unallocatedExpense,
                'profit' => $unallocatedProfit,
                'margin_pct' => $unallocatedRevenue !== 0.0 ? round($unallocatedProfit / $unallocatedRevenue * 100, 2) : 0.0,
            ],
            // The reconciliation target: Σrows + unallocated == total, exactly,
            // within the same revenue/expense category scope this whole method
            // reads — not the wider net-profit figure company() reports (which
            // also includes other_revenue/other_expense, categories no Brand
            // or Unallocated row here claims any share of).
            'total' => [
                'revenue' => round($totalRevenue, 4),
                'expense' => round($totalExpense, 4),
                'profit' => $totalProfit,
                'margin_pct' => $totalRevenue !== 0.0 ? round($totalProfit / $totalRevenue * 100, 2) : 0.0,
            ],
        ];
    }

    /**
     * Net cost-allocation amount per destination Brand, within the window —
     * read verbatim from {@see CostAllocationService}'s own table, reversals
     * (negative-amount rows) already netted by the plain SUM, exactly the
     * pattern `effectiveAllocatedAmount()` itself uses. Filtered on the
     * allocation's own `created_at`: a `CostAllocation` row carries no other
     * date, and this is when the destination assignment itself became true,
     * not necessarily the original expense's posting date.
     *
     * @return array<string, float> destination profit_center_id → net allocated amount
     */
    private function costAllocationsByBrand(string $companyId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('finance_cost_allocations')
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('destination_profit_center_id')
            ->selectRaw('destination_profit_center_id as dim, COALESCE(SUM(allocated_amount), 0) as amount')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->dim] = round((float) $r->amount, 4);
        }

        return $out;
    }

    /**
     * Customer profitability — AR revenue per customer with the company operating
     * margin applied (stated attribution).
     *
     * @return array<string, mixed>
     */
    public function byCustomer(string $companyId, Carbon $from, Carbon $to, int $limit = 50): array
    {
        $margin = $this->metrics->profitAndLoss($companyId, $from, $to)['operating_margin_pct'] / 100;

        $rows = DB::table('finance_customer_invoices')
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('document_type', 'invoice')
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COALESCE(SUM(total),0) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $lines = $rows->map(fn ($r) => [
            'customer_id' => $r->customer_id,
            'revenue' => round((float) $r->revenue, 4),
            'estimated_profit' => round((float) $r->revenue * $margin, 4),
            'margin_pct' => round($margin * 100, 2),
        ])->all();

        return [
            'dimension' => 'customer',
            'attribution' => 'AR revenue × company operating margin',
            'rows' => $lines,
        ];
    }

    /**
     * Product / channel profitability — dimension-ready. The ledger does not tag
     * journal lines by product or channel, so this returns the company total with
     * an explicit note rather than a fabricated split.
     *
     * @return array<string, mixed>
     */
    public function byUntaggedDimension(string $companyId, string $dimension, Carbon $from, Carbon $to): array
    {
        return [
            'dimension' => $dimension,
            'available' => false,
            'note' => "The ledger does not tag journal lines by {$dimension}; company-level profitability is shown. Tag postings by {$dimension} to break this down.",
            'company' => $this->company($companyId, $from, $to),
        ];
    }

    /** @return array<string, mixed> */
    private function byDimension(string $companyId, string $column, string $label, Carbon $from, Carbon $to): array
    {
        $revenue = $this->metrics->activityByDimension($companyId, $this->revenueCategories, $column, $from, $to);
        $expense = $this->metrics->activityByDimension($companyId, $this->expenseCategories, $column, $from, $to);

        $keys = array_unique(array_merge(array_keys($revenue), array_keys($expense)));
        $rows = [];
        foreach ($keys as $dim) {
            $rev = $revenue[$dim] ?? 0.0;
            $exp = $expense[$dim] ?? 0.0;
            $profit = round($rev - $exp, 4);
            $rows[] = [
                $label => $dim,
                'revenue' => $rev,
                'expense' => $exp,
                'profit' => $profit,
                'margin_pct' => $rev !== 0.0 ? round($profit / $rev * 100, 2) : 0.0,
            ];
        }

        usort($rows, static fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return ['dimension' => $label, 'rows' => $rows];
    }
}
