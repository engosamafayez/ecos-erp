<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-INV-01 · Stock on Hand / Available / Reserved (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-INV-01 (Available Stock), MET-INV-02 (Reserved Stock).
 *
 * `InventoryItem` carries its own `company_id` but no Eloquent global scope (confirmed by
 * direct inspection — no `booted()` anywhere in the model, matching `Customer`, not
 * `Order`) — every query here filters `company_id` explicitly.
 *
 * Available Stock reuses `InventoryItem::availableQty()`'s own formula
 * (`on_hand_qty - reserved_qty`, deliberately signed/unclamped per that method's docblock)
 * rather than re-deriving it — computed here as a plain column expression for the same
 * reason `SalesOverviewQuery` computes `quantity * unit_price` directly: a per-row PHP call
 * would be N+1 across a full inventory list, and the formula is a one-line arithmetic
 * expression, not a strategy decision, so mirroring it in SQL is not "recomputing valuation
 * logic" (§7) — it is the same formula at the row-set grain instead of the single-row grain.
 */
final class StockOnHandQuery implements ReportHandlerInterface
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function reportId(): string
    {
        return 'RPT-INV-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $base = InventoryItem::query()
            ->where('company_id', $context->companyId)
            ->when($filters['warehouse_id'] !== null, fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when($filters['product_id'] !== null, fn ($q) => $q->where('product_id', $filters['product_id']));

        // Company-wide totals — one bounded aggregate query, not a per-row sum in PHP.
        $totals = (clone $base)
            ->selectRaw('COALESCE(SUM(on_hand_qty), 0) as on_hand_total')
            ->selectRaw('COALESCE(SUM(reserved_qty), 0) as reserved_total')
            ->selectRaw('COALESCE(SUM(on_hand_qty - reserved_qty), 0) as available_total')
            ->first();

        $itemCount = (clone $base)->count();

        $items = (clone $base)
            ->orderBy('product_id')
            ->forPage($filters['page'], $filters['per_page'])
            ->get();

        $productIds = $items->pluck('product_id')->unique()->values()->all();
        $productNames = $productIds === [] ? collect() : Product::query()->whereIn('id', $productIds)->pluck('name', 'id');

        $rows = $items->map(static fn (InventoryItem $item): array => [
            'product_id' => $item->product_id,
            'product_name' => $productNames[$item->product_id] ?? null,
            'warehouse_id' => $item->warehouse_id,
            'on_hand_qty' => (float) $item->on_hand_qty,
            'reserved_qty' => (float) $item->reserved_qty,
            'available_qty' => $item->availableQty(),
        ])->values()->all();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-INV-01' => round((float) $totals->available_total, 4),
                'MET-INV-02' => round((float) $totals->reserved_total, 4),
            ],
            rows: $rows,
            totals: [
                'on_hand_total' => round((float) $totals->on_hand_total, 4),
                'reserved_total' => round((float) $totals->reserved_total, 4),
                'available_total' => round((float) $totals->available_total, 4),
                'item_count' => $itemCount,
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
            ],
            period: new ReportPeriod(null, null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
