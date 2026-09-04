<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-SALES-04 · Requested-Delivery Performance (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-SALES-10 — the one Sales-category report needing a genuinely new
 * cross-module query (§18 gap classification: REPORTING READ MODEL REQUIRED).
 *
 * Join note: `distribution_delivery_stops.order_id` is a direct FK to `orders.id`, but is
 * NOT unique per order (`UNIQUE(trip_id, order_id)`, not `UNIQUE(order_id)` — a delivery
 * can be re-attempted on a different trip after a failure). §17's own caution ("must go
 * through the order<->stop link ... not a direct FK") is read here as: never assume the
 * first/any stop row is the delivery outcome — a `whereExists` scoped to the specific
 * `status = delivered` completion is used instead of a naive join, so an order with
 * multiple historical stop attempts is never double-counted or matched to the wrong one.
 */
final class RequestedDeliveryPerformanceQuery implements ReportHandlerInterface
{
    public function reportId(): string
    {
        return 'RPT-SALES-04';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);

        // Population: Delivered orders that actually named a requested_delivery_date — an
        // order with no requested date has no target to compare against and is excluded
        // from both numerator and denominator, not counted as a failure.
        $population = $range->applyToDateColumn(
            Order::query()
                ->where('status', OrderStatus::Delivered->value)
                ->whereNotNull('requested_delivery_date'),
            'requested_delivery_date',
        );

        $totalCount = (clone $population)->count();

        $onTimeCount = (clone $population)
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('distribution_delivery_stops')
                    ->whereColumn('distribution_delivery_stops.order_id', 'orders.id')
                    ->where('distribution_delivery_stops.status', DeliveryStopStatus::Delivered->value)
                    ->whereRaw('DATE(distribution_delivery_stops.completed_at) <= orders.requested_delivery_date');
            })
            ->count();

        $onTimeRate = $totalCount > 0 ? $onTimeCount / $totalCount : null;

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-SALES-10' => $onTimeRate !== null ? round($onTimeRate * 100, 2) : null,
            ],
            rows: [],
            totals: [
                'delivered_orders_with_requested_date' => $totalCount,
                'on_time_count' => $onTimeCount,
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
