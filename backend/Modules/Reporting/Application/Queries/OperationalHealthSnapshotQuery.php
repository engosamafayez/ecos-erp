<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Queries;

use DateTimeImmutable;
use Modules\Inventory\InventoryItems\Application\Queries\StockOnHandQuery;
use Modules\Logistics\Distribution\Application\Queries\DeliveryPerformanceQuery;
use Modules\Operations\Preparation\Application\Queries\PreparationCompletionAndShortagesQuery;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-EXEC-03 · Operational Health Snapshot (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-DIST-01, MET-PREP-01, MET-INV-01. Read strategy: C (Reporting-owned
 * composition) — the ONLY thing this task's Reporting-module-owned query code does is call
 * three already-live Task 4 handlers and extract one KPI from each (ADR-045 Decision 3
 * Pattern C / Decision 1's own module skeleton: "Application/Queries — CROSS-DOMAIN
 * COMPOSITION ONLY", the one deliberate exception to every other report's rule of never
 * putting handler code inside `Modules\Reporting` itself).
 *
 * Each delegate handler is called with its own default (empty) filter set — this is a
 * snapshot, not a report with its own date/warehouse/wave filter surface — and its KPI is
 * read back verbatim, never recomputed, guaranteeing this composition can never drift from
 * RPT-DIST-02/RPT-PREP-02/RPT-INV-01's own values for the same metric (§13 cross-report
 * consistency).
 */
final class OperationalHealthSnapshotQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly DeliveryPerformanceQuery $deliveryPerformance,
        private readonly PreparationCompletionAndShortagesQuery $preparationCompletion,
        private readonly StockOnHandQuery $stockOnHand,
    ) {}

    public function reportId(): string
    {
        return 'RPT-EXEC-03';
    }

    public function validateFilters(array $rawFilters): array
    {
        return [];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $delivery = $this->deliveryPerformance->execute($context, $this->deliveryPerformance->validateFilters([]));
        $preparation = $this->preparationCompletion->execute($context, $this->preparationCompletion->validateFilters([]));
        $stock = $this->stockOnHand->execute($context, $this->stockOnHand->validateFilters([]));

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-DIST-01' => $delivery->kpis['MET-DIST-01'] ?? null,
                'MET-PREP-01' => $preparation->kpis['MET-PREP-01'] ?? null,
                'MET-INV-01' => $stock->kpis['MET-INV-01'] ?? null,
            ],
            rows: [],
            totals: [],
            period: new ReportPeriod(null, null),
            appliedFilters: [],
            generatedAt: new DateTimeImmutable,
        );
    }
}
