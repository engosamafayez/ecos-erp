<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-DIST-03 · Zone Performance (ENTERPRISE-REPORTING-PLATFORM.md §18) — confirmed not
 * built anywhere in canonical source; built here, Pattern B, per the doc's own explicit
 * instruction.
 *
 * Groups by `distribution_window_orders.distribution_zone_id` — NEVER `orders.
 * delivery_zone_id`, a different, unrelated catalog entirely (§14's own "two-catalog zone
 * trap"; enforced here at the query layer, not left to the UI). `distribution_window_orders`
 * carries its own `company_id` directly (confirmed by migration) — filtered explicitly.
 * Delivery outcome for each zone's orders is read by joining to `distribution_delivery_stops`
 * via `order_id` (the only reliable link — an order may have multiple historical stop
 * attempts across trips, so this counts the DELIVERED outcome specifically, not just any
 * stop row, mirroring `RequestedDeliveryPerformanceQuery`'s own established pattern for this
 * exact hazard).
 */
final class ZonePerformanceQuery implements ReportHandlerInterface
{
    public function reportId(): string
    {
        return 'RPT-DIST-03';
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

        $base = DB::table('distribution_window_orders as dwo')
            ->where('dwo.company_id', $context->companyId)
            ->whereNotNull('dwo.distribution_zone_id');
        $range->applyToTimestampColumn($base, 'dwo.created_at');

        $orderCounts = (clone $base)
            ->selectRaw('dwo.distribution_zone_id, COUNT(*) as order_count')
            ->groupBy('dwo.distribution_zone_id')
            ->pluck('order_count', 'distribution_zone_id');

        $deliveredCounts = (clone $base)
            ->join('distribution_delivery_stops as dds', 'dds.order_id', '=', 'dwo.order_id')
            ->where('dds.status', DeliveryStopStatus::Delivered->value)
            ->selectRaw('dwo.distribution_zone_id, COUNT(DISTINCT dwo.order_id) as delivered_count')
            ->groupBy('dwo.distribution_zone_id')
            ->pluck('delivered_count', 'distribution_zone_id');

        $rows = $orderCounts->map(static function (int $orderCount, string $zoneId) use ($deliveredCounts): array {
            $delivered = (int) ($deliveredCounts[$zoneId] ?? 0);

            return [
                'zone_id' => $zoneId,
                'order_count' => $orderCount,
                'delivered_count' => $delivered,
                'delivery_rate' => $orderCount > 0 ? round($delivered / $orderCount * 100, 1) : null,
            ];
        })->values()->all();

        $totalOrders = (int) $orderCounts->sum();
        $totalDelivered = (int) $deliveredCounts->sum();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: $rows,
            totals: [
                'total_orders' => $totalOrders,
                'total_delivered' => $totalDelivered,
                'overall_delivery_rate' => $totalOrders > 0 ? round($totalDelivered / $totalOrders * 100, 1) : null,
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
