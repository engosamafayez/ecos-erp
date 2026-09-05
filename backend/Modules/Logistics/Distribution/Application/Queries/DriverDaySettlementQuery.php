<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Logistics\Distribution\Domain\Services\DriverDaySettlementReadService;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-DRV-02 · Driver Day Settlement (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-DRV-01 through 04 — "the already-built... KPI set. Source:
 * DriverDaySettlementReadService.kpis() — reuse verbatim."
 *
 * `kpis()` is a `private` method with no public standalone wrapper; the only public entry
 * points are `daySummary()`/`activeBoard()`/`historyBoard()`, each of which embeds the same
 * `kpis()` output under a `'kpis'` key for the requested scope. This report uses
 * `daySummary($companyId, $date)` — the natural company-wide "day's settlement board" shape
 * that method already provides — rather than forcing a single-driver drill-down through a
 * filter (`applyListFilters()`) that has no `driver_id` predicate, only `search`/`status`/
 * `stage`/damage/shortage flags. An optional `driver_id` narrows the already-fetched
 * `drivers` rows in memory (no extra query) rather than re-deriving a per-driver KPI
 * calculation this handler has no access to (`kpis()` stays private and un-reimplemented).
 */
final class DriverDaySettlementQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly DriverDaySettlementReadService $settlement,
    ) {}

    public function reportId(): string
    {
        return 'RPT-DRV-02';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date' => ['required', 'date_format:Y-m-d'],
            'driver_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            'date' => $validated['date'],
            'driver_id' => isset($validated['driver_id']) ? (int) $validated['driver_id'] : null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $result = $this->settlement->daySummary($context->companyId, $filters['date']);

        $rows = $result['drivers'] ?? [];

        if ($filters['driver_id'] !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['driver_id'] ?? 0) === $filters['driver_id'],
            ));
        }

        $kpis = $result['kpis'] ?? [];

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-DRV-01' => $kpis['total_delivered'] ?? null,
                'MET-DRV-02' => $kpis['total_cash_in'] ?? null,
                'MET-DRV-03' => $kpis['total_expenses'] ?? null,
                'MET-DRV-04' => $kpis['net_cash'] ?? null,
            ],
            rows: $rows,
            totals: $kpis,
            period: new ReportPeriod($filters['date'], $filters['date']),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
