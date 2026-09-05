<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Logistics\Distribution\Domain\Services\DriverReportsReadService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-DRV-01 · Driver Operational Summary (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-DRV-01 (Orders Delivered), MET-DRV-02 (Cash Collected, Raw) — "Source:
 * DriverReportsReadService." Reused verbatim; that class's own docblock states every query
 * is fail-closed to the driver's own company id, load-bearing since the Loading tables it
 * reads carry no global tenant scope of their own.
 *
 * `Driver` itself DOES carry a global scope (permissive: the caller's own company OR the
 * shared/unowned pool, `company_id IS NULL`) — a deliberate, pre-existing business rule for
 * shared drivers, not something this report overrides.
 */
final class DriverOperationalSummaryQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly DriverReportsReadService $reports,
    ) {}

    public function reportId(): string
    {
        return 'RPT-DRV-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'driver_id' => ['required', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ])->validate();

        return [
            'driver_id' => (int) $validated['driver_id'],
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $driver = Driver::query()->findOrFail($filters['driver_id']);

        $range = new ReportDateRange(
            $filters['date_from'] ?? now()->startOfMonth()->toDateString(),
            $filters['date_to'] ?? now()->toDateString(),
        );

        $wallet = $this->reports->wallet($driver, $context->companyId, (string) $range->from, (string) $range->to);
        $orders = $this->reports->ordersPerformance($driver, $context->companyId, (string) $range->from, (string) $range->to, 1, 1);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-DRV-01' => $orders['summary']['delivered'] ?? null,
                'MET-DRV-02' => $wallet['cash']['collected_raw'] ?? $wallet['collections']['total'] ?? null,
            ],
            rows: [],
            totals: [
                'wallet' => $wallet,
                'orders_summary' => $orders['summary'] ?? null,
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
