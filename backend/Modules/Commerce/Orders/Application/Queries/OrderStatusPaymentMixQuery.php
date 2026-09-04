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
 * RPT-SALES-03 · Order Status & Payment Mix (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-SALES-08 (Order Count by Status), MET-SALES-09 (Payment Method Mix).
 */
final class OrderStatusPaymentMixQuery implements ReportHandlerInterface
{
    public function reportId(): string
    {
        return 'RPT-SALES-03';
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
        $base = $range->applyToDateColumn(Order::query(), 'order_date');

        // MET-SALES-08 — count grouped by the 11 canonical OrderStatus values (ADR-042).
        // Never a legacy pre-V3 status word: the enum itself is the only source of group
        // keys, so a dead status can never appear as a spurious bucket.
        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statusRows = array_map(
            static fn (OrderStatus $status): array => [
                'breakdown' => 'status',
                'key' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($byStatus[$status->value] ?? 0),
            ],
            OrderStatus::cases(),
        );

        // MET-SALES-09 — COUNT(orders) GROUP BY COALESCE(payment_method_manual, payment_method).
        // Neither column is a backed enum (§17 known dependency) — normalize defensively
        // rather than trust clean values. Alias deliberately NOT named `payment_method`:
        // that is a real column on `orders`, and MySQL's GROUP BY resolves an identifier
        // matching an actual column name to the column itself, not a same-named SELECT
        // alias — grouping by the raw column instead of this COALESCE expression, which
        // then fails ONLY_FULL_GROUP_BY on the ungrouped `payment_method_manual` reference
        // (a real defect this exact collision produced once, caught by this task's own
        // Feature test).
        $paymentRows = (clone $base)
            ->selectRaw('COALESCE(NULLIF(TRIM(payment_method_manual), \'\'), NULLIF(TRIM(payment_method), \'\'), \'unspecified\') as resolved_payment_method')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('resolved_payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(static fn (object $row): array => [
                'breakdown' => 'payment_method',
                'key' => $row->resolved_payment_method,
                'label' => null,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: [...$statusRows, ...$paymentRows],
            totals: [],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
