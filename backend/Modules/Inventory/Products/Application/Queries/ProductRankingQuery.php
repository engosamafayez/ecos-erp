<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PROD-02 · Top Sellers / Slow Movers / Zero-Sale (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-SALES-05 (Units Sold) only — rank/filter over the same figure, no new formula.
 *
 * Three named `mode`s share one Units-Sold-in-window computation:
 *  - `top_sellers`  — active products ordered by units sold, descending.
 *  - `slow_movers`  — active products with >0 units sold, ordered ascending (excludes
 *                      zero-sale products, which are their own named cut, not "the bottom
 *                      of the top-sellers list").
 *  - `zero_sale`    — active products with NO order_lines at all in the window.
 *
 * The sold-products aggregate is built the same ambiguous-`company_id`-safe way as
 * `ProductPerformanceQuery`/`SalesByDimensionQuery::lineLevelRows()` (plain query builder,
 * never `Product::query()` joined to `orders`/`order_lines`). The `zero_sale` cut starts
 * from `Product::query()` alone (its own global scope, uncombined with any other
 * `company_id`-bearing table in the same builder) and excludes by a separately-fetched id
 * list — the same "aggregate first, exclude via a separate lookup" pattern
 * `SalesByDimensionQuery::orderLevelRows()` already established, not a join.
 */
final class ProductRankingQuery implements ReportHandlerInterface
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function reportId(): string
    {
        return 'RPT-PROD-02';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'mode' => ['required', Rule::in(['top_sellers', 'slow_movers', 'zero_sale'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            'mode' => $validated['mode'],
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);
        $mode = $filters['mode'];

        $soldBase = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->join('products', 'products.id', '=', 'order_lines.product_id')
            ->where('orders.company_id', $context->companyId)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereNull('orders.deleted_at')
            ->where('products.is_active', true);
        $range->applyToDateColumn($soldBase, 'orders.order_date');

        if ($mode === 'zero_sale') {
            $soldProductIds = (clone $soldBase)->distinct()->pluck('order_lines.product_id')->all();

            $zeroSaleBase = Product::query()->where('is_active', true);

            if ($soldProductIds !== []) {
                $zeroSaleBase->whereNotIn('id', $soldProductIds);
            }

            $totalCount = (clone $zeroSaleBase)->count();

            $rows = (clone $zeroSaleBase)
                ->orderBy('name')
                ->forPage($filters['page'], $filters['per_page'])
                ->get(['id', 'name', 'sku', 'brand_id', 'category_id'])
                ->map(static fn (Product $p): array => [
                    'product_id' => $p->id,
                    'product_name' => $p->name,
                    'sku' => $p->sku,
                    'brand_id' => $p->brand_id,
                    'category_id' => $p->category_id,
                    'units_sold' => 0.0,
                ])
                ->values()
                ->all();

            return new ReportResult(
                reportId: $this->reportId(),
                kpis: ['MET-SALES-05' => 0.0],
                rows: $rows,
                totals: ['product_count' => $totalCount, 'page' => $filters['page'], 'per_page' => $filters['per_page']],
                period: $range->toPeriod(),
                appliedFilters: $filters,
                generatedAt: new DateTimeImmutable,
            );
        }

        $ordered = $soldBase
            ->selectRaw('order_lines.product_id as product_id')
            ->selectRaw('MAX(products.name) as product_name')
            ->selectRaw('MAX(products.sku) as sku')
            ->selectRaw('MAX(products.brand_id) as brand_id')
            ->selectRaw('MAX(products.category_id) as category_id')
            ->selectRaw('COALESCE(SUM(order_lines.quantity), 0) as units_sold')
            ->groupBy('order_lines.product_id')
            ->havingRaw($mode === 'slow_movers' ? 'SUM(order_lines.quantity) > 0' : '1 = 1');

        $totalCount = DB::table(DB::raw("({$ordered->toSql()}) as ranked"))
            ->mergeBindings($ordered)
            ->count();

        $rows = $ordered
            ->orderBy('units_sold', $mode === 'slow_movers' ? 'asc' : 'desc')
            ->forPage($filters['page'], $filters['per_page'])
            ->get()
            ->map(static fn (object $row): array => [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'sku' => $row->sku,
                'brand_id' => $row->brand_id,
                'category_id' => $row->category_id,
                'units_sold' => (float) $row->units_sold,
            ])
            ->values()
            ->all();

        $topUnits = $rows[0]['units_sold'] ?? 0.0;

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: ['MET-SALES-05' => $topUnits],
            rows: $rows,
            totals: ['product_count' => $totalCount, 'page' => $filters['page'], 'per_page' => $filters['per_page']],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
