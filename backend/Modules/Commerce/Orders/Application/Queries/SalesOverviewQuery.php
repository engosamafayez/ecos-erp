<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-SALES-01 · Sales Overview (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-SALES-01, 02, 03, 04, 06, 07 — exact formulas from §17, not inferred.
 *
 * Pattern B (new source-owned query service, `Modules\Commerce\Orders\Application\Queries`)
 * — no existing service composed these together (§18 gap classification). Tenant scope is
 * free: `Order`'s own global scope (Order.php booted(), TASK-GOLIVE-RC6-REPAIR-001) filters
 * every query here by the authenticated actor's company automatically.
 */
final class SalesOverviewQuery implements ReportHandlerInterface
{
    public function reportId(): string
    {
        return 'RPT-SALES-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'warehouse_id' => ['nullable', 'uuid'],
            'channel_id' => ['nullable', 'uuid'],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'channel_id' => $validated['channel_id'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);

        $scope = function () use ($filters) {
            $query = Order::query();

            if ($filters['warehouse_id'] !== null) {
                $query->where('assigned_warehouse_id', $filters['warehouse_id']);
            }

            if ($filters['channel_id'] !== null) {
                $query->where('channel_id', $filters['channel_id']);
            }

            return $query;
        };

        // MET-SALES-01/02/04 date basis: orders.order_date; excludes Cancelled (§17).
        $active = $range->applyToDateColumn(
            (clone $scope())->where('status', '!=', OrderStatus::Cancelled->value),
            'order_date',
        );

        $orderCount = (clone $active)->count();

        // Gross Sales — SUM(quantity x unit_price) over order_lines, computed as its own
        // documented formula rather than trusting order_lines.line_total to be identical.
        $grossSales = (float) (clone $active)
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as total')
            ->value('total');

        $discountAmountSum = (float) ((clone $active)->sum('discount_amount') ?? 0);

        $couponSum = (float) (clone $active)
            ->join('order_coupons', 'order_coupons.order_id', '=', 'orders.id')
            ->selectRaw('COALESCE(SUM(order_coupons.discount), 0) as total')
            ->value('total');

        $feeSum = (float) (clone $active)
            ->join('order_fees', 'order_fees.order_id', '=', 'orders.id')
            ->selectRaw('COALESCE(SUM(order_fees.total), 0) as total')
            ->value('total');

        $netSales = $grossSales - $discountAmountSum - $couponSum + $feeSum;
        $aov = $orderCount > 0 ? $netSales / $orderCount : 0.0;

        // MET-SALES-03 Delivered Sales — Net Sales formula, WHERE status = Delivered.
        $delivered = $range->applyToDateColumn(
            (clone $scope())->where('status', OrderStatus::Delivered->value),
            'order_date',
        );

        $deliveredGross = (float) (clone $delivered)
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->selectRaw('COALESCE(SUM(order_lines.quantity * order_lines.unit_price), 0) as total')
            ->value('total');
        $deliveredDiscount = (float) ((clone $delivered)->sum('discount_amount') ?? 0);
        $deliveredCoupons = (float) (clone $delivered)
            ->join('order_coupons', 'order_coupons.order_id', '=', 'orders.id')
            ->selectRaw('COALESCE(SUM(order_coupons.discount), 0) as total')
            ->value('total');
        $deliveredFees = (float) (clone $delivered)
            ->join('order_fees', 'order_fees.order_id', '=', 'orders.id')
            ->selectRaw('COALESCE(SUM(order_fees.total), 0) as total')
            ->value('total');
        $deliveredSales = $deliveredGross - $deliveredDiscount - $deliveredCoupons + $deliveredFees;

        // MET-SALES-06 Cancelled Orders Count — date basis order_date, "in the period".
        $cancelledCount = $range->applyToDateColumn(
            (clone $scope())->where('status', OrderStatus::Cancelled->value),
            'order_date',
        )->count();

        // MET-SALES-07 Scheduled Orders Count — "currently in Scheduled status", a live
        // snapshot count, NOT period-scoped (its own definition names no "in the period"
        // qualifier, unlike MET-SALES-06 — the date filter is deliberately not applied).
        $scheduledCount = (clone $scope())->where('status', OrderStatus::Scheduled->value)->count();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-SALES-01' => round($grossSales, 2),
                'MET-SALES-02' => round($netSales, 2),
                'MET-SALES-03' => round($deliveredSales, 2),
                'MET-SALES-04' => round($aov, 2),
                'MET-SALES-06' => $cancelledCount,
                'MET-SALES-07' => $scheduledCount,
            ],
            rows: [],
            totals: [],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
