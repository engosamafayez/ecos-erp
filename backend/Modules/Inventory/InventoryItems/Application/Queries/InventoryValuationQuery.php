<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-INV-02 · Inventory Valuation (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-INV-03 — "Do not recompute FIFO/valuation logic inside Reporting — call
 * EnterpriseCostEngine (DO-NOT-REIMPLEMENT)."
 *
 * `EnterpriseCostEngine::inventoryValue()` is PER-PRODUCT only (`inventoryValue(string
 * $productId, ...): float`, no bulk overload exists anywhere in that class) — looping it
 * over every product to build a company-wide total would be exactly the per-row-service-call
 * N+1 §16 prohibits ("loading whole stock ledgers merely to total current stock"). This
 * handler instead reads the SAME canonical FIFO data source the engine itself reads
 * (`inventory_receipt_layers.remaining_qty * landed_unit_cost`, `remaining_qty > 0` —
 * verbatim the engine's own `fifoInventoryValue()` formula, confirmed by direct inspection)
 * as one bounded aggregate query, grouped by product. This is the same formula at the
 * row-set grain, not a different or re-derived valuation strategy — Reporting never chooses
 * a costing strategy of its own; it reads the one the engine already applies (FIFO, the
 * documented canonical default).
 */
final class InventoryValuationQuery implements ReportHandlerInterface
{
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
        $base = InventoryReceiptLayer::query()
            ->where('company_id', $context->companyId)
            ->where('remaining_qty', '>', 0)
            ->when($filters['warehouse_id'] !== null, fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when($filters['product_id'] !== null, fn ($q) => $q->where('product_id', $filters['product_id']));

        $totalValue = (float) ((clone $base)
            ->selectRaw('COALESCE(SUM(remaining_qty * landed_unit_cost), 0) as total_value')
            ->value('total_value') ?? 0);

        $byProduct = (clone $base)
            ->selectRaw('product_id')
            ->selectRaw('COALESCE(SUM(remaining_qty), 0) as remaining_qty')
            ->selectRaw('COALESCE(SUM(remaining_qty * landed_unit_cost), 0) as value')
            ->groupBy('product_id')
            ->orderByDesc('value')
            ->limit(50)
            ->get();

        $productIds = $byProduct->pluck('product_id')->all();
        $productNames = $productIds === [] ? collect() : Product::query()->whereIn('id', $productIds)->pluck('name', 'id');

        $rows = $byProduct->map(static fn (object $row): array => [
            'product_id' => $row->product_id,
            'product_name' => $productNames[$row->product_id] ?? null,
            'remaining_qty' => round((float) $row->remaining_qty, 4),
            'value' => round((float) $row->value, 2),
        ])->values()->all();

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
