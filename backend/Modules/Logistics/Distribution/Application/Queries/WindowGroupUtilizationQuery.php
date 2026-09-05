<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DistributionAggregationService;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-DIST-01 · Window/Group Utilization (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-DIST-02 — reuses `DistributionAggregationService::slotOrderCounts()` verbatim
 * (its numerator) divided by `VirtualCapacitySlot.capacity_orders` (the doc's own formula,
 * §17: "Live order count in a Distribution Group divided by its enforced order-count
 * capacity"). `slotOrderCounts()` is per-window, so this report requires `window_id`.
 *
 * Known dependency preserved (§17 MET-DIST-02): `capacity_stops`/`capacity_weight_kg`/
 * `capacity_volume_m3` are NOT enforced by any guard and are never used as a denominator
 * here — only `capacity_orders`, the one the doc confirms is actually enforced. This is a
 * distinct number from Vehicle/Trip capacity (`Trip.capacity`) — never conflated (see
 * `VehicleTripUtilizationQuery` for that separate metric).
 *
 * `VirtualCapacitySlot` carries `company_id` but no global scope — filtered explicitly.
 */
final class WindowGroupUtilizationQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly DistributionAggregationService $aggregation,
    ) {}

    public function reportId(): string
    {
        return 'RPT-DIST-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'window_id' => ['required', 'uuid'],
            'warehouse_id' => ['nullable', 'uuid'],
        ])->validate();

        return [
            'window_id' => $validated['window_id'],
            'warehouse_id' => $validated['warehouse_id'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $windowId = $filters['window_id'];

        $slots = VirtualCapacitySlot::query()
            ->where('company_id', $context->companyId)
            ->where('distribution_window_id', $windowId)
            ->get();

        $orderCounts = $this->aggregation->slotOrderCounts($windowId, $filters['warehouse_id']);

        $rows = $slots->map(static function (VirtualCapacitySlot $slot) use ($orderCounts): array {
            $orders = $orderCounts[(string) $slot->id] ?? 0;
            $capacity = $slot->capacity_orders;

            return [
                'slot_id' => $slot->id,
                'slot_name' => $slot->name,
                'order_count' => $orders,
                'capacity_orders' => $capacity,
                'utilization_pct' => $capacity !== null && $capacity > 0 ? round($orders / $capacity * 100, 1) : null,
            ];
        })->values()->all();

        $totalOrders = array_sum(array_column($rows, 'order_count'));
        $totalCapacity = array_sum(array_filter(array_column($rows, 'capacity_orders'), static fn ($c) => $c !== null));

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-DIST-02' => $totalCapacity > 0 ? round($totalOrders / $totalCapacity * 100, 1) : null,
            ],
            rows: $rows,
            totals: [
                'total_orders' => $totalOrders,
                'total_capacity_orders' => $totalCapacity,
                'slot_count' => $slots->count(),
            ],
            period: new ReportPeriod(null, null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
