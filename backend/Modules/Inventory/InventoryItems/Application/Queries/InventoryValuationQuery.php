<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\CostManagement\Domain\Services\EnterpriseCostEngine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-INV-02 · Inventory Valuation (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-INV-03 — "Do not recompute FIFO/valuation logic inside Reporting — call
 * EnterpriseCostEngine (DO-NOT-REIMPLEMENT)."
 *
 * TASK-ECOS-REPORTING-V1-SOURCE-REMEDIATION-007 §5 fix: this handler previously aggregated
 * `inventory_receipt_layers` directly, independently mirroring the engine's own FIFO
 * formula rather than calling it — a real DO-NOT-REIMPLEMENT violation confirmed by direct
 * source audit (Task 6): if the engine's costing strategy or formula ever changed, this
 * report would not have inherited that change. Fixed by calling the engine's new, additive
 * `EnterpriseCostEngine::fifoInventoryValueByProduct()` (added this task specifically so a
 * bulk, company-wide read is possible without the N+1 a per-product loop over
 * `inventoryValue()` would cause — see that method's own docblock). Reporting now
 * genuinely consumes the canonical authority; it does not read the underlying table at all.
 */
final class InventoryValuationQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly EnterpriseCostEngine $costEngine,
    ) {}

    public function reportId(): string
    {
        return 'RPT-INV-02';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
        ])->validate();

        return [
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $byProduct = $this->costEngine->fifoInventoryValueByProduct($context->companyId, $filters['warehouse_id']);

        if ($filters['product_id'] !== null) {
            $byProduct = array_values(array_filter(
                $byProduct,
                static fn (array $row): bool => $row['product_id'] === $filters['product_id'],
            ));
        }

        $totalValue = array_sum(array_column($byProduct, 'value'));

        $top = array_slice($byProduct, 0, 50);
        $productIds = array_column($top, 'product_id');
        $productNames = $productIds === [] ? collect() : Product::query()->whereIn('id', $productIds)->pluck('name', 'id');

        $rows = array_values(array_map(static fn (array $row): array => [
            'product_id' => $row['product_id'],
            'product_name' => $productNames[$row['product_id']] ?? null,
            'remaining_qty' => $row['remaining_qty'],
            'value' => $row['value'],
        ], $top));

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-INV-03' => round($totalValue, 2),
            ],
            rows: $rows,
            totals: [
                'total_value' => round($totalValue, 2),
                'costing_strategy' => 'fifo',
            ],
            period: new ReportPeriod(null, null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
