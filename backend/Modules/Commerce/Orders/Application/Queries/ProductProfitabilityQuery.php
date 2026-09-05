<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PROD-03 · Product Profitability — Operational (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-PROD-02 (COGS — Operational), MET-PROD-04 (Gross Profit — Operational),
 * MET-PROD-06 (Gross Margin — Operational). **Never** the Accounting variants
 * (MET-PROD-03/05/07) — those are explicitly LATER, blocked on Finance's own
 * product/channel ledger-dimension gap (§18 known dependency), a gap this report must not
 * paper over by substituting an operational figure under an accounting label.
 *
 * Source strictly `order_line_snapshots` (per-line, immutable, captured at order Confirm) —
 * catalogue source_modules names `Modules\Commerce\Orders` only, never
 * `Modules\Inventory\Products`, because the cost figures live entirely on the snapshot, not
 * on the live `Product`/live `order_lines` rows. `order_line_snapshots.line_cost` is the one
 * genuinely per-PRODUCT (per-line) cost figure available anywhere in Commerce — unlike
 * `orders.actual_cogs_amount`, which is order-level only and cannot be allocated to a single
 * product without inventing a rule (the same order-level-vs-line-level trap
 * `SalesByDimensionQuery`/`ProductPerformanceQuery` already resolve for Net Sales/ASP).
 *
 * "Matched at the same grain" (§17 MET-PROD-04's own instruction): Gross Sales here is
 * computed from the SAME `order_lines` rows that have a matching snapshot (inner join on
 * `order_line_id`), using the identical `quantity × unit_price` formula MET-SALES-01 uses
 * everywhere else in this platform (cross-report consistency, §13) — never
 * `order_line_snapshots.line_total`, which would silently diverge from the live-order_lines
 * figure RPT-SALES-01/02/RPT-PROD-01 already report for the same metric. This necessarily
 * scopes the report to orders that have reached Confirm (where a snapshot exists); an order
 * still in Draft/Scheduled has no cost snapshot yet and is correctly excluded from a
 * profitability figure that requires a real captured cost, not a placeholder.
 */
final class ProductProfitabilityQuery implements ReportHandlerInterface
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function reportId(): string
    {
        return 'RPT-PROD-03';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'brand_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'brand_id' => $validated['brand_id'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);

        $base = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->join('order_line_snapshots', 'order_line_snapshots.order_line_id', '=', 'order_lines.id')
            ->join('products', 'products.id', '=', 'order_lines.product_id')
            ->where('orders.company_id', $context->companyId)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereNull('orders.deleted_at')
            ->whereNotNull('order_line_snapshots.line_cost')
            ->when($filters['brand_id'] !== null, fn ($q) => $q->where('products.brand_id', $filters['brand_id']))
            ->when($filters['category_id'] !== null, fn ($q) => $q->where('products.category_id', $filters['category_id']));

        $range->applyToDateColumn($base, 'orders.order_date');

        $rows = (clone $base)
            ->selectRaw('order_lines.product_id as product_id')
            ->selectRaw('MAX(products.name) as product_name')
            ->selectRaw('MAX(products.sku) as sku')
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(order_line_snapshots.line_cost), 0) as cogs')
            ->groupBy('order_lines.product_id')
            ->orderByDesc('gross_sales')
            ->forPage($filters['page'], $filters['per_page'])
            ->get()
            ->map(static function (object $row): array {
                $sales = (float) $row->gross_sales;
                $cogs = (float) $row->cogs;
                $profit = $sales - $cogs;

                return [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'sku' => $row->sku,
                    'gross_sales' => round($sales, 2),
                    'cogs' => round($cogs, 2),
                    'gross_profit' => round($profit, 2),
                    'gross_margin_pct' => $sales > 0 ? round($profit / $sales * 100, 2) : null,
                ];
            })
            ->values()
            ->all();

        $companyTotals = (clone $base)
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(order_line_snapshots.line_cost), 0) as cogs')
            ->first();

        $totalSales = (float) ($companyTotals->gross_sales ?? 0);
        $totalCogs = (float) ($companyTotals->cogs ?? 0);
        $totalProfit = $totalSales - $totalCogs;

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-PROD-02' => round($totalCogs, 2),
                'MET-PROD-04' => round($totalProfit, 2),
                'MET-PROD-06' => $totalSales > 0 ? round($totalProfit / $totalSales * 100, 2) : null,
            ],
            rows: $rows,
            totals: [
                'gross_sales' => round($totalSales, 2),
                'cogs' => round($totalCogs, 2),
                'gross_profit' => round($totalProfit, 2),
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
