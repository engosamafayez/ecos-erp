<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PROD-01 · Product Performance (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-SALES-05 (Units Sold), MET-PROD-01 (Average Selling Price).
 *
 * ASP is defined by the dictionary as "Net Sales for a product ÷ units sold" — but
 * MET-SALES-02's own known dependency ("no line-level discount exists... a 'discount by
 * product' breakdown is not derivable today") already discloses that a real per-product Net
 * Sales figure does not exist, the exact gap `SalesByDimensionQuery` (RPT-SALES-02, Task 3)
 * found and resolved by reporting Net Sales as null for line-level dimensions rather than
 * inventing an order-level-discount allocation rule. This report applies the identical,
 * already-ratified resolution: ASP here is Gross Sales ÷ units (both well-defined at the
 * line grain, no allocation required), not a fabricated per-product Net Sales.
 *
 * Built on the plain query builder joining `order_lines`→`orders`, exactly mirroring
 * `SalesByDimensionQuery::lineLevelRows()` — `Product` carries its own Eloquent global scope
 * (confirmed: `Product::booted()` adds a fail-closed tenant scope identical to `Order`'s),
 * so routing through `Product::query()` in the same builder as `orders`/`order_lines` (both
 * also `company_id`-bearing via `orders`) would reproduce the exact ambiguous-`company_id`
 * hazard Task 3 discovered — avoided here by never invoking Eloquent's `Product` scope in
 * this query at all.
 */
final class ProductPerformanceQuery implements ReportHandlerInterface
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function reportId(): string
    {
        return 'RPT-PROD-01';
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
            ->join('products', 'products.id', '=', 'order_lines.product_id')
            ->where('orders.company_id', $context->companyId)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereNull('orders.deleted_at')
            ->when($filters['brand_id'] !== null, fn ($q) => $q->where('products.brand_id', $filters['brand_id']))
            ->when($filters['category_id'] !== null, fn ($q) => $q->where('products.category_id', $filters['category_id']));

        $range->applyToDateColumn($base, 'orders.order_date');

        $totalProducts = (clone $base)->distinct('order_lines.product_id')->count('order_lines.product_id');

        $rows = (clone $base)
            ->selectRaw('order_lines.product_id as product_id')
            ->selectRaw('MAX(products.name) as product_name')
            ->selectRaw('MAX(products.sku) as sku')
            ->selectRaw('MAX(products.brand_id) as brand_id')
            ->selectRaw('MAX(products.category_id) as category_id')
            ->selectRaw('COALESCE(SUM(order_lines.quantity), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as gross_sales')
            ->groupBy('order_lines.product_id')
            ->orderByDesc('gross_sales')
            ->forPage($filters['page'], $filters['per_page'])
            ->get()
            ->map(static function (object $row): array {
                $units = (float) $row->units_sold;
                $sales = (float) $row->gross_sales;

                return [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'sku' => $row->sku,
                    'brand_id' => $row->brand_id,
                    'category_id' => $row->category_id,
                    'units_sold' => $units,
                    'gross_sales' => round($sales, 2),
                    'asp' => $units > 0 ? round($sales / $units, 2) : null,
                ];
            })
            ->values()
            ->all();

        $companyTotals = (clone $base)
            ->selectRaw('COALESCE(SUM(order_lines.quantity), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as gross_sales')
            ->first();

        $totalUnits = (float) ($companyTotals->units_sold ?? 0);
        $totalSales = (float) ($companyTotals->gross_sales ?? 0);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-SALES-05' => $totalUnits,
                'MET-PROD-01' => $totalUnits > 0 ? round($totalSales / $totalUnits, 2) : null,
            ],
            rows: $rows,
            totals: [
                'units_sold' => $totalUnits,
                'gross_sales' => round($totalSales, 2),
                'product_count' => $totalProducts,
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
