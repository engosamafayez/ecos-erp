<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-DIST-04 · Vehicle/Trip Utilization (ENTERPRISE-REPORTING-PLATFORM.md §18) — V1 covers
 * Group/Trip only, per the doc's own scope note: a Vehicle-identity-accurate cut is LATER,
 * blocked on open blocker VP-1 (`Operations\Loading`'s `vehicle_assignments.vehicle_id` is
 * unconstrained/untyped against `logistics_vehicles.id`) — not attempted here.
 *
 * Trip capacity fill rate: `distribution_trip_orders` count per trip, divided by
 * `Trip.capacity` — a DIFFERENT number from Group capacity (`VirtualCapacitySlot.
 * capacity_orders`, see `WindowGroupUtilizationQuery`) — never conflated (§17 MET-DIST-02
 * known dependency). `distribution_trip_orders` has no `company_id` of its own — scoped via
 * `trip_id` -> `distribution_trips.company_id`.
 */
final class VehicleTripUtilizationQuery implements ReportHandlerInterface
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function reportId(): string
    {
        return 'RPT-DIST-04';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);

        $base = DB::table('distribution_trips as dt')
            ->where('dt.company_id', $context->companyId);
        $range->applyToTimestampColumn($base, 'dt.created_at');

        $tripCount = (clone $base)->count();

        $trips = (clone $base)
            // Active-only (§10/§25): joined ON the condition, not a WHERE, so a trip with
            // zero currently-active orders still reports order_count=0 rather than
            // disappearing from a LEFT JOIN it no longer matches.
            ->leftJoin('distribution_trip_orders as dto', function ($join): void {
                $join->on('dto.trip_id', '=', 'dt.id')->whereNull('dto.superseded_at');
            })
            ->selectRaw('dt.id, dt.trip_number, dt.capacity')
            ->selectRaw('COUNT(dto.order_id) as order_count')
            ->groupBy('dt.id', 'dt.trip_number', 'dt.capacity')
            ->orderBy('dt.trip_number')
            ->forPage($filters['page'], $filters['per_page'])
            ->get();

        $rows = $trips->map(static function (object $row): array {
            $capacity = $row->capacity !== null ? (int) $row->capacity : null;
            $orders = (int) $row->order_count;

            return [
                'trip_id' => (string) $row->id,
                'trip_number' => $row->trip_number,
                'order_count' => $orders,
                'capacity' => $capacity,
                'utilization_pct' => $capacity !== null && $capacity > 0 ? round($orders / $capacity * 100, 1) : null,
            ];
        })->values()->all();

        $totalOrders = array_sum(array_column($rows, 'order_count'));
        $totalCapacity = array_sum(array_filter(array_column($rows, 'capacity'), static fn ($c) => $c !== null));

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: $rows,
            totals: [
                'trip_count' => $tripCount,
                'total_orders' => $totalOrders,
                'total_capacity' => $totalCapacity,
                'overall_utilization_pct' => $totalCapacity > 0 ? round($totalOrders / $totalCapacity * 100, 1) : null,
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
