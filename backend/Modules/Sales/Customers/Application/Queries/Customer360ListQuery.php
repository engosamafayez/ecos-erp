<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerBrand;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;

/**
 * RPT-CUST-02 · Customer 360 List (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-CUST-06 (Customer Lifetime Value — reused verbatim from
 * `CustomerBrand.lifetime_value`, never recomputed, per §17's own instruction).
 *
 * "Preferred Category" is deliberately NOT included in this first tranche: unlike
 * "preferred Brand" (directly derivable from the existing, already-maintained
 * `CustomerBrand.orders_count` pivot — no new aggregation), no equivalent per-category
 * pivot exists anywhere in canonical source. Building one would mean a brand-new
 * product-to-category-per-customer aggregation with no existing service to reuse and no
 * architecture-approved formula for it — exactly the kind of new business computation §8
 * reserves for architecture approval, not a Task 3 judgment call. Disclosed here and in
 * the engineering report rather than silently omitted or invented.
 *
 * "Blocked state" reuses `BlockedCustomerPolicy::activeBlocksForCustomers()` verbatim —
 * already documented as "ONE query, never one per row" (§12 N+1 avoidance), the same
 * batch lookup `CustomerController`'s own list view uses.
 */
final class Customer360ListQuery implements ReportHandlerInterface
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly BlockedCustomerPolicy $blockedCustomerPolicy,
    ) {}

    public function reportId(): string
    {
        return 'RPT-CUST-02';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            'per_page' => (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $totalCustomers = Customer::query()->where('company_id', $context->companyId)->count();

        $customers = Customer::query()
            ->where('company_id', $context->companyId)
            ->orderBy('name')
            ->forPage($filters['page'], $filters['per_page'])
            ->get();

        $customerIds = $customers->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all();

        // One batched aggregate query for order stats per customer — never one per row.
        $orderStats = Order::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('customer_id')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('MAX(order_date) as last_order_date')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        // Delivered-only sales per customer, via order_lines (Gross Sales formula, §17).
        $deliveredSales = Order::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', OrderStatus::Delivered->value)
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->selectRaw('orders.customer_id as customer_id')
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as delivered_sales')
            ->groupBy('orders.customer_id')
            ->get()
            ->keyBy('customer_id');

        // Total (non-cancelled) sales per customer, same formula, no status restriction
        // beyond "commercially active" (§17 MET-SALES-01).
        $totalSales = Order::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->selectRaw('orders.customer_id as customer_id')
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as total_sales')
            ->groupBy('orders.customer_id')
            ->get()
            ->keyBy('customer_id');

        // MET-CUST-06 + preferred Brand — one batched query over CustomerBrand, never
        // recomputing lifetime_value/orders_count (§17: "reuse, do not recompute").
        $brandPivots = CustomerBrand::query()
            ->whereIn('customer_id', $customerIds)
            ->with('brand:id,name')
            ->get()
            ->groupBy('customer_id');

        $blocks = $this->blockedCustomerPolicy->activeBlocksForCustomers($customers, $context->companyId);

        $rows = $customers->map(function (Customer $customer) use ($orderStats, $deliveredSales, $totalSales, $brandPivots, $blocks): array {
            $id = (string) $customer->id;
            $stats = $orderStats[$id] ?? null;
            $pivots = $brandPivots[$id] ?? collect();

            $lifetimeValue = (float) $pivots->sum('lifetime_value');
            $preferred = $pivots->sortByDesc('orders_count')->first();

            $orderCount = (int) ($stats->order_count ?? 0);
            $totalSalesValue = (float) ($totalSales[$id]->total_sales ?? 0);

            return [
                'customer_id' => $id,
                'name' => $customer->name,
                'orders' => $orderCount,
                'aov' => $orderCount > 0 ? round($totalSalesValue / $orderCount, 2) : 0.0,
                'total_sales' => round($totalSalesValue, 2),
                'delivered_sales' => round((float) ($deliveredSales[$id]->delivered_sales ?? 0), 2),
                'last_order_date' => $stats->last_order_date ?? null,
                'lifetime_value' => round($lifetimeValue, 2),
                'preferred_brand' => $preferred?->brand?->name,
                // See class docblock: no per-category pivot exists yet, deliberately deferred.
                'preferred_category' => null,
                'is_blocked' => isset($blocks[$id]),
            ];
        })->values()->all();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: $rows,
            totals: [
                'total_customers' => $totalCustomers,
                'returned_count' => count($rows),
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
            ],
            period: new ReportPeriod(null, null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
