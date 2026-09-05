<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Commerce\Orders\Application\Queries\SalesByDimensionQuery;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-EXEC-02 · Top Performers (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-SALES-01 (Gross Sales), MET-SALES-02 (Net Sales), MET-CUST-06 (Lifetime
 * Value). Read strategy: composition over `SalesByDimensionQuery` (RPT-SALES-02, Task 3,
 * called verbatim — never re-querying `order_lines` here) plus one small new aggregate over
 * `CustomerBrand` for the LTV board (no existing service exposes a ranked LTV list; the
 * underlying `lifetime_value` column itself is never recomputed, only ordered and summed).
 *
 * Product and Brand boards rank by GROSS Sales, not Net — `SalesByDimensionQuery` itself
 * reports `net_sales: null` for line-level dimensions (product/brand/category), the
 * already-ratified resolution to the order-level-discount-allocation gap (see that class's
 * own docblock and MET-SALES-02's dictionary "known dependency"). Only the Customer board,
 * an order-level dimension, has a real Net Sales figure. This is not a new gap invented
 * here — it is the same one Task 3 already found, disclosed, and shipped a resolution for.
 *
 * `SalesByDimensionQuery` does not itself sort its `rows` (a plain `GROUP BY` with no
 * `ORDER BY` — arbitrary MySQL row order); this composer sorts the already-fetched, already
 * date/tenant-bounded result in memory and slices the top N — no additional query.
 */
final class TopPerformersQuery implements ReportHandlerInterface
{
    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly SalesByDimensionQuery $salesByDimension,
    ) {}

    public function reportId(): string
    {
        return 'RPT-EXEC-02';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'limit' => (int) ($validated['limit'] ?? self::DEFAULT_LIMIT),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);
        $limit = $filters['limit'];
        $dimensionFilters = ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']];

        $topProducts = $this->topBy(
            $this->salesByDimension->execute($context, [...$dimensionFilters, 'dimension' => 'product'])->rows,
            'gross_sales',
            $limit,
        );

        $topBrands = $this->topBy(
            $this->salesByDimension->execute($context, [...$dimensionFilters, 'dimension' => 'brand'])->rows,
            'gross_sales',
            $limit,
        );

        $topCustomersByNetSales = $this->topBy(
            $this->salesByDimension->execute($context, [...$dimensionFilters, 'dimension' => 'customer'])->rows,
            'net_sales',
            $limit,
        );

        $topCustomersByLifetimeValue = $this->topCustomersByLtv($context->companyId, $limit);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: [],
            totals: [
                'top_products_by_gross_sales' => $topProducts,
                'top_brands_by_gross_sales' => $topBrands,
                'top_customers_by_net_sales' => $topCustomersByNetSales,
                'top_customers_by_lifetime_value' => $topCustomersByLifetimeValue,
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function topBy(array $rows, string $key, int $limit): array
    {
        usort($rows, static fn (array $a, array $b): int => ($b[$key] ?? 0) <=> ($a[$key] ?? 0));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topCustomersByLtv(string $companyId, int $limit): array
    {
        return DB::table('customer_brands')
            ->join('customers', 'customers.id', '=', 'customer_brands.customer_id')
            ->where('customers.company_id', $companyId)
            ->selectRaw('customer_brands.customer_id as customer_id')
            ->selectRaw('MAX(customers.name) as customer_name')
            ->selectRaw('COALESCE(SUM(customer_brands.lifetime_value), 0) as lifetime_value')
            ->groupBy('customer_brands.customer_id')
            ->orderByDesc('lifetime_value')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'customer_id' => $row->customer_id,
                'customer_name' => $row->customer_name,
                'lifetime_value' => round((float) $row->lifetime_value, 2),
            ])
            ->values()
            ->all();
    }
}
