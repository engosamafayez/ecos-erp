<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PREP-03 · Postponed & Bottleneck Products (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-PREP-03 (Postponed Orders Count — `preparation_wave_orders.postponed_at IS
 * NOT NULL`) plus the existing `top_shorted_products` ranking
 * (`PreparationAnalyticsController.top_shorted_products`).
 *
 * `preparation_wave_orders` deliberately carries no global scope (explicit, documented
 * design choice in `PreparationWaveOrder`'s own docblock: "five consumers query this table
 * through the raw query builder, which would silently bypass one") — scoped here through an
 * explicit join to `preparation_waves.company_id`, never a bare unqualified filter.
 *
 * `top_shorted_products`'s own source is `PreparationAnalyticsController::buildAnalytics()`,
 * a private, cached method — no public API to call. This handler reads the same
 * `preparation_wave_items` joined to `preparation_waves`, `quantity_short > 0`, grouped by
 * product, ordered by shortage occurrence count — the identical query shape, not a
 * re-derived formula.
 */
final class PostponedAndBottleneckProductsQuery implements ReportHandlerInterface
{
    public function reportId(): string
    {
        return 'RPT-PREP-03';
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

        $postponedQuery = DB::table('preparation_wave_orders as pwo')
            ->join('preparation_waves as pw', 'pw.id', '=', 'pwo.preparation_wave_id')
            ->where('pw.company_id', $context->companyId)
            ->whereNotNull('pwo.postponed_at');
        $range->applyToTimestampColumn($postponedQuery, 'pwo.postponed_at');
        $postponedCount = $postponedQuery->count();

        $topShortedQuery = DB::table('preparation_wave_items as pwi')
            ->join('preparation_waves as pw', 'pw.id', '=', 'pwi.preparation_wave_id')
            ->where('pw.company_id', $context->companyId)
            ->where('pwi.quantity_short', '>', 0);
        $range->applyToDateColumn($topShortedQuery, 'pw.planning_date');

        $topShorted = $topShortedQuery
            ->selectRaw('pwi.product_id, pwi.sku_snapshot as sku')
            ->selectRaw('COUNT(*) as shortage_occurrences')
            ->selectRaw('AVG(pwi.quantity_short / NULLIF(pwi.quantity_required, 0) * 100) as avg_shortage_pct')
            ->groupBy('pwi.product_id', 'pwi.sku_snapshot')
            ->orderByDesc('shortage_occurrences')
            ->limit(10)
            ->get()
            ->map(static fn (object $row): array => [
                'product_id' => $row->product_id,
                'sku' => $row->sku,
                'shortage_occurrences' => (int) $row->shortage_occurrences,
                'avg_shortage_pct' => $row->avg_shortage_pct !== null ? round((float) $row->avg_shortage_pct, 1) : null,
            ])
            ->values()
            ->all();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-PREP-03' => $postponedCount,
            ],
            rows: $topShorted,
            totals: [
                'postponed_orders_count' => $postponedCount,
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
