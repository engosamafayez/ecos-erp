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
 * RPT-DIST-02 · Delivery Performance (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-DIST-01 (Delivery Rate) and MET-DIST-03 (Delivery Duration).
 *
 * `distribution_delivery_stops` has NO `company_id` column at all (confirmed by direct
 * inspection of its migration) — tenant scope is reachable only through `trip_id` →
 * `distribution_trips.company_id`, so this handler joins to `distribution_trips` explicitly
 * for that filter rather than querying `DeliveryStop` bare.
 *
 * Source strictly `DeliveryStop` — never `Modules\Logistics\Delivery` (§17 known
 * dependency: "a parallel, uncalled stack with no driver-runtime caller").
 */
final class DeliveryPerformanceQuery implements ReportHandlerInterface
{
    public function reportId(): string
    {
        return 'RPT-DIST-02';
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

        $base = DB::table('distribution_delivery_stops as dds')
            ->join('distribution_trips as dt', 'dt.id', '=', 'dds.trip_id')
            ->where('dt.company_id', $context->companyId);
        $range->applyToTimestampColumn($base, 'dds.attempted_at');

        $byStatus = (clone $base)
            ->selectRaw('dds.status, COUNT(*) as total')
            ->groupBy('dds.status')
            ->pluck('total', 'dds.status');

        $totalStops = (int) $byStatus->sum();
        $delivered = (int) ($byStatus[DeliveryStopStatus::Delivered->value] ?? 0);
        $deliveryRate = $totalStops > 0 ? round($delivered / $totalStops * 100, 1) : null;

        $avgDurationMinutes = (clone $base)
            ->whereNotNull('dds.attempted_at')
            ->whereNotNull('dds.completed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, dds.attempted_at, dds.completed_at)) as avg_minutes')
            ->value('avg_minutes');

        $rows = collect(DeliveryStopStatus::cases())->map(static fn (DeliveryStopStatus $status): array => [
            'status' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($byStatus[$status->value] ?? 0),
        ])->values()->all();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-DIST-01' => $deliveryRate,
                'MET-DIST-03' => $avgDurationMinutes !== null ? round((float) $avgDurationMinutes, 1) : null,
            ],
            rows: $rows,
            totals: [
                'total_stops' => $totalStops,
                'delivered' => $delivered,
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
