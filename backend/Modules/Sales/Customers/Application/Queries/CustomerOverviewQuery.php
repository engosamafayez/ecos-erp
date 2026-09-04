<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;
use Modules\Sales\Customers\Domain\Models\Customer;

/**
 * RPT-CUST-01 · Customer Overview (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-CUST-01 through 05.
 *
 * `Customer` carries no automatic tenant global scope (unlike `Order` — confirmed by
 * direct inspection: no `booted()`/`addGlobalScope` anywhere in
 * Modules\Sales\Customers\Domain\Models\Customer). Every Customer-rooted query here
 * filters `company_id` explicitly, matching the same manual-scoping convention already
 * used by `EloquentCustomerRepository`/`CustomerController`.
 *
 * MET-CUST-02's "trailing period" is explicitly a report parameter, not fixed by the
 * dictionary (§17) — exposed here as `period_days` (default 30).
 */
final class CustomerOverviewQuery implements ReportHandlerInterface
{
    private const DEFAULT_PERIOD_DAYS = 30;

    public function reportId(): string
    {
        return 'RPT-CUST-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ])->validate();

        return [
            'period_days' => (int) ($validated['period_days'] ?? self::DEFAULT_PERIOD_DAYS),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $periodDays = $filters['period_days'];
        $to = Carbon::now(ReportPeriod::BUSINESS_TIMEZONE)->toDateString();
        $from = Carbon::now(ReportPeriod::BUSINESS_TIMEZONE)->subDays($periodDays)->toDateString();

        // MET-CUST-01 — Total Customers.
        $totalCustomers = Customer::query()->where('company_id', $context->companyId)->count();

        // Orders in the trailing period, non-cancelled — the shared basis for 02/04/05.
        // Order's own global scope already enforces tenant isolation.
        $ordersInPeriod = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('order_date', [$from, $to]);

        $orderCount = (clone $ordersInPeriod)->count();

        // MET-CUST-02 — Active Customers: distinct customers with >=1 order in the period.
        $ordersPerCustomer = (clone $ordersInPeriod)
            ->selectRaw('customer_id, COUNT(*) as orders_count')
            ->groupBy('customer_id')
            ->pluck('orders_count', 'customer_id');

        $activeCustomers = $ordersPerCustomer->count();

        // MET-CUST-04 — Repeat Customer Rate: share of active customers with >1 order.
        $repeatCustomers = $ordersPerCustomer->filter(static fn (int $count): bool => $count > 1)->count();
        $repeatRate = $activeCustomers > 0 ? $repeatCustomers / $activeCustomers : 0.0;

        // MET-CUST-05 — Orders per Customer over the same filter set as 02/04.
        $ordersPerCustomerAvg = $activeCustomers > 0 ? $orderCount / $activeCustomers : 0.0;

        // MET-CUST-03 — New Customers: system-wide first order falls inside the period
        // (MIN(orders.order_date) per customer — the direct-Order alternative §17 names,
        // avoiding a per-brand CustomerBrand.first_order_at aggregation for a system-wide
        // figure).
        $firstOrderDates = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('customer_id, MIN(order_date) as first_order_date')
            ->groupBy('customer_id')
            ->havingRaw('MIN(order_date) BETWEEN ? AND ?', [$from, $to])
            ->get();
        $newCustomers = $firstOrderDates->count();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-CUST-01' => $totalCustomers,
                'MET-CUST-02' => $activeCustomers,
                'MET-CUST-03' => $newCustomers,
                'MET-CUST-04' => round($repeatRate * 100, 2),
                'MET-CUST-05' => round($ordersPerCustomerAvg, 2),
            ],
            rows: [],
            totals: [],
            period: new ReportPeriod($from, $to),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
