<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Queries;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Categories\Domain\Models\Category;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;
use Modules\Sales\Customers\Domain\Models\Customer;

/**
 * RPT-SALES-02 · Sales by Dimension (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-SALES-01 (Gross Sales), 02 (Net Sales), 05 (Units Sold).
 *
 * "Brand / Product / Category / Customer / Channel / Warehouse — user-selected breakout"
 * (§18) — all six supported. Customer/Channel/Warehouse are order-level dimensions: Net
 * Sales (which subtracts order-level discount_amount/coupons and adds order-level fees)
 * is well-defined and computed for them. Product/Brand/Category are line-level dimensions
 * — Gross Sales and Units Sold (both pure `order_lines` sums) are well-defined, but Net
 * Sales is NOT: the architecture defines no rule for allocating an order-level discount
 * across its lines, and inventing one here would be exactly the un-approved business
 * formula §8/§18 forbids. Net Sales is therefore reported as `null` for those three
 * dimensions — an honest gap, the same convention Finance's own ProfitabilityService uses
 * (`available:false`) rather than fabricating a number (§18 RPT-FIN-05 known dependency).
 */
final class SalesByDimensionQuery implements ReportHandlerInterface
{
    private const ORDER_LEVEL_DIMENSIONS = ['customer', 'channel', 'warehouse'];

    private const LINE_LEVEL_DIMENSIONS = ['product', 'brand', 'category'];

    public function reportId(): string
    {
        return 'RPT-SALES-02';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'dimension' => ['required', Rule::in([...self::ORDER_LEVEL_DIMENSIONS, ...self::LINE_LEVEL_DIMENSIONS])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ])->validate();

        return [
            'dimension' => $validated['dimension'],
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);
        $dimension = $filters['dimension'];

        $base = $range->applyToDateColumn(
            Order::query()->where('status', '!=', OrderStatus::Cancelled->value),
            'order_date',
        );

        $rows = in_array($dimension, self::LINE_LEVEL_DIMENSIONS, true)
            ? $this->lineLevelRows($range, $context->companyId, $dimension)
            : $this->orderLevelRows(clone $base, $dimension);

        $totals = [
            'gross_sales' => round((float) collect($rows)->sum('gross_sales'), 2),
            'net_sales' => in_array($dimension, self::ORDER_LEVEL_DIMENSIONS, true)
                ? round((float) collect($rows)->sum('net_sales'), 2)
                : null,
            'units_sold' => (float) collect($rows)->sum('units_sold'),
        ];

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: $rows,
            totals: $totals,
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }

    /**
     * @param  Builder<Order>  $base
     * @return list<array<string, mixed>>
     */
    private function orderLevelRows(Builder $base, string $dimension): array
    {
        [$groupColumn, $lookupModel] = match ($dimension) {
            'customer' => ['orders.customer_id', Customer::class],
            'channel' => ['orders.channel_id', Channel::class],
            'warehouse' => ['orders.assigned_warehouse_id', Warehouse::class],
        };

        // Deliberately NO join to customers/channels/warehouses here: those tables carry
        // their own `company_id` column, and `Order`'s own global scope adds an
        // unqualified `company_id` predicate — joining a second `company_id`-bearing
        // table into the SAME query makes that predicate ambiguous at the SQL level
        // (a real defect this exact shape produced once, caught by this task's own
        // Feature test). Aggregating on the raw id column only, then resolving names in
        // a small separate lookup, sidesteps the ambiguity entirely rather than aliasing
        // around it.
        $withLines = (clone $base)->join('order_lines', 'order_lines.order_id', '=', 'orders.id');

        $rows = $withLines
            ->selectRaw("{$groupColumn} as dimension_key")
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(order_lines.quantity), 0) as units_sold')
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->groupBy($groupColumn)
            ->get();

        $keys = $rows->pluck('dimension_key')->filter()->values()->all();
        $names = $keys === [] ? collect() : $lookupModel::query()->whereIn('id', $keys)->pluck('name', 'id');

        // Order-level adjustments (discount_amount/coupons/fees) summed per group, once —
        // never joined alongside order_lines in the same query (that would fan-out and
        // corrupt both sums, see SalesOverviewQuery's own comment on this exact hazard).
        $adjustments = (clone $base)
            ->selectRaw("{$groupColumn} as dimension_key")
            ->selectRaw('COALESCE(SUM(orders.discount_amount), 0) as discount_amount_sum')
            ->groupBy($groupColumn)
            ->get()
            ->keyBy('dimension_key');

        $coupons = (clone $base)
            ->join('order_coupons', 'order_coupons.order_id', '=', 'orders.id')
            ->selectRaw("{$groupColumn} as dimension_key")
            ->selectRaw('COALESCE(SUM(order_coupons.discount), 0) as coupon_sum')
            ->groupBy($groupColumn)
            ->get()
            ->keyBy('dimension_key');

        $fees = (clone $base)
            ->join('order_fees', 'order_fees.order_id', '=', 'orders.id')
            ->selectRaw("{$groupColumn} as dimension_key")
            ->selectRaw('COALESCE(SUM(order_fees.total), 0) as fee_sum')
            ->groupBy($groupColumn)
            ->get()
            ->keyBy('dimension_key');

        return $rows->map(function (object $row) use ($adjustments, $coupons, $fees, $names): array {
            $key = $row->dimension_key;
            $gross = (float) $row->gross_sales;
            $discount = (float) ($adjustments[$key]->discount_amount_sum ?? 0);
            $coupon = (float) ($coupons[$key]->coupon_sum ?? 0);
            $fee = (float) ($fees[$key]->fee_sum ?? 0);

            return [
                'dimension_key' => $key,
                'dimension_label' => $names[$key] ?? null,
                'gross_sales' => round($gross, 2),
                'net_sales' => round($gross - $discount - $coupon + $fee, 2),
                'units_sold' => (float) $row->units_sold,
                'order_count' => (int) $row->order_count,
            ];
        })->values()->all();
    }

    /**
     * Product/Brand/Category grouping keys live on `products`, which (like `orders`)
     * carries its own `company_id` column — joining it into an `Order::query()`-rooted
     * query would hit the exact same ambiguous-`company_id` hazard {@see orderLevelRows()}
     * documents. Built instead on the plain query builder from `order_lines`, with the
     * tenant/status/soft-delete/date filters this task's own handler applies explicitly
     * and table-qualified — `Order`'s Eloquent global scope never enters this query at
     * all, so there is nothing for it to collide with.
     *
     * @return list<array<string, mixed>>
     */
    private function lineLevelRows(ReportDateRange $range, string $companyId, string $dimension): array
    {
        $query = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.company_id', $companyId)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereNull('orders.deleted_at');

        $range->applyToDateColumn($query, 'orders.order_date');

        if ($dimension !== 'product') {
            $query->leftJoin('products', 'products.id', '=', 'order_lines.product_id');
        }

        $groupColumn = match ($dimension) {
            'product' => 'order_lines.product_id',
            'brand' => 'products.brand_id',
            'category' => 'products.category_id',
        };

        $rows = $query
            ->selectRaw("{$groupColumn} as dimension_key")
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(order_lines.quantity), 0) as units_sold')
            ->groupBy($groupColumn)
            ->get();

        $keys = $rows->pluck('dimension_key')->filter()->values()->all();
        $lookupModel = match ($dimension) {
            'product' => Product::class,
            'brand' => Brand::class,
            'category' => Category::class,
        };
        $names = $keys === [] ? collect() : $lookupModel::query()->whereIn('id', $keys)->pluck('name', 'id');

        return $rows->map(static fn (object $row): array => [
            'dimension_key' => $row->dimension_key,
            'dimension_label' => $names[$row->dimension_key] ?? null,
            'gross_sales' => round((float) $row->gross_sales, 2),
            'net_sales' => null,
            'units_sold' => (float) $row->units_sold,
        ])->values()->all();
    }
}
