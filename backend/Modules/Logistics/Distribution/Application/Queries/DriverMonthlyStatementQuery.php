<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Logistics\Distribution\Domain\Services\DriverReportsReadService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-DRV-03 · Driver Monthly Statement (ENTERPRISE-REPORTING-PLATFORM.md §18) — "Source:
 * DriverReportsReadService::monthlyStatement()." A clean, purpose-built public method,
 * reused verbatim.
 *
 * Known dependency preserved, not silently fixed (§17 known dependency): `wallet()`'s own
 * `no_canonical_authority` flag for advances/expenses (a stale docblock relative to
 * `driver_trip_movements`, which now IS a real authority per this same service) is passed
 * through in the response exactly as the service returns it — flagged for the owning
 * module to reconcile, never patched from inside Reporting.
 */
final class DriverMonthlyStatementQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly DriverReportsReadService $reports,
    ) {}

    public function reportId(): string
    {
        return 'RPT-DRV-03';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'driver_id' => ['required', 'integer', 'min:1'],
            'month' => ['required', 'date_format:Y-m'],
        ])->validate();

        return [
            'driver_id' => (int) $validated['driver_id'],
            'month' => $validated['month'],
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $driver = Driver::query()->findOrFail($filters['driver_id']);

        $statement = $this->reports->monthlyStatement($driver, $context->companyId, $filters['month']);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: [],
            totals: $statement,
            period: new ReportPeriod($statement['period']['from'] ?? null, $statement['period']['to'] ?? null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
