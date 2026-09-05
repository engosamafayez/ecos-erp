<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Operations\DemandAnalysis\Application\Services\WaveKpiCalculator;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PREP-02 · Preparation Completion & Shortages (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-PREP-01 (Preparation Completion — reuses `WaveKpiCalculator::calculate()`
 * verbatim, per-wave) and MET-PREP-02 (Wave Shortage Rate — §17's own source is
 * `PreparationAnalyticsController`'s `shortage_rate_pct`: waves with `shortage_detected`
 * true, divided by waves created, over a date range — a population-level rate, not a
 * per-wave figure).
 *
 * The two metrics have genuinely different grains (one wave vs. a date-range population),
 * so this report accepts both an optional `wave_id` (completion) and a date range (shortage
 * rate) rather than forcing one shape onto the other.
 */
final class PreparationCompletionAndShortagesQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly WaveKpiCalculator $kpiCalculator,
    ) {}

    public function reportId(): string
    {
        return 'RPT-PREP-02';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'wave_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ])->validate();

        return [
            'wave_id' => $validated['wave_id'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);

        // MET-PREP-01 — only when a specific wave is named; WaveKpiCalculator::calculate()
        // takes one wave instance, never a batch (DO-NOT-REIMPLEMENT: no second completion
        // formula is derived here).
        $completionPct = null;
        $waveKpis = null;
        if ($filters['wave_id'] !== null) {
            $wave = PreparationWave::query()
                ->where('company_id', $context->companyId)
                ->findOrFail($filters['wave_id']);

            $waveKpis = $this->kpiCalculator->calculate($wave);
            $completionPct = $waveKpis['completion_pct'] ?? null;
        }

        // MET-PREP-02 — population rate across the date range (planning_date), reusing the
        // exact predicate PreparationAnalyticsController's own shortage_rate_pct uses:
        // waves with shortage_detected = true, over waves created in the period.
        $wavesBase = $range->applyToDateColumn(
            PreparationWave::query()->where('company_id', $context->companyId),
            'planning_date',
        );
        $wavesCreated = (clone $wavesBase)->count();
        $wavesWithShortage = (clone $wavesBase)->where('shortage_detected', true)->count();
        $shortageRatePct = $wavesCreated > 0 ? round($wavesWithShortage / $wavesCreated * 100, 1) : 0.0;

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-PREP-01' => $completionPct !== null ? round((float) $completionPct, 2) : null,
                'MET-PREP-02' => $shortageRatePct,
            ],
            rows: [],
            totals: [
                'wave_kpis' => $waveKpis,
                'waves_created' => $wavesCreated,
                'waves_with_shortage' => $wavesWithShortage,
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
