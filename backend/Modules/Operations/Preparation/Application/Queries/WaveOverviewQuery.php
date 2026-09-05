<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PREP-01 · Wave Overview (ENTERPRISE-REPORTING-PLATFORM.md §18): "Waves opened/
 * completed, duration. Source: PreparationDashboardController."
 *
 * That controller's own query logic lives in a `private buildDashboard()` method — not a
 * reusable service class, so there is no public API to call. This handler reads the same
 * `PreparationWave` table the controller already reads (waves opened/completed counts,
 * average duration) rather than re-deriving a different computation; it does not attempt to
 * invoke the controller's private method, which is not structurally possible.
 *
 * `PreparationWave` carries `company_id` but no global scope (confirmed by direct
 * inspection) — filtered explicitly here, the same manual-scoping convention every
 * Preparation controller in this codebase already uses.
 */
final class WaveOverviewQuery implements ReportHandlerInterface
{
    public function reportId(): string
    {
        return 'RPT-PREP-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'warehouse_id' => ['nullable', 'uuid'],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);

        $base = $range->applyToDateColumn(
            PreparationWave::query()
                ->where('company_id', $context->companyId)
                ->when($filters['warehouse_id'] !== null, fn ($q) => $q->where('warehouse_id', $filters['warehouse_id'])),
            'planning_date',
        );

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $wavesOpened = (clone $base)->count();
        $wavesCompleted = (int) ($byStatus['completed'] ?? 0);

        $avgDurationMinutes = (clone $base)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as avg_minutes')
            ->value('avg_minutes');

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: $byStatus->map(static fn (int $count, string $status): array => [
                'status' => $status,
                'count' => $count,
            ])->values()->all(),
            totals: [
                'waves_opened' => $wavesOpened,
                'waves_completed' => $wavesCompleted,
                'avg_completion_minutes' => $avgDurationMinutes !== null ? round((float) $avgDurationMinutes, 1) : null,
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
