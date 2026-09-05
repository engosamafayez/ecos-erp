<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Operations\DemandAnalysis\Domain\Models\WaveMissingMaterial;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-INV-04 · Shortage & Zero-Stock (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-INV-04 (Stock Shortage, via DemandAnalysis — existing data, new aggregation
 * across all currently-active waves) and MET-INV-05 (Zero-Stock Product Count — no existing
 * query computes this today, confirmed by inspection; built here, Pattern B).
 *
 * Shortage: `WaveMissingMaterial` carries no global scope and `DemandReadRepository`'s own
 * reader methods filter only by `preparation_wave_id` (tenant safety normally comes from the
 * caller having already validated ONE wave belongs to their company first) — this report
 * aggregates across MANY waves at once, so it joins to `PreparationWave` explicitly and
 * scopes on `preparation_waves.company_id` itself, restricted to `WaveStatus::activeValues()`
 * (the existing, canonical "active" predicate — never re-derived).
 *
 * Zero-Stock: MET-INV-05's own definition — "active products with on_hand_qty <= 0 (or no
 * InventoryItem row) for a given warehouse" — requires a LEFT JOIN from `products` to
 * `inventory_items` (a product with no row at all must count), so it cannot be expressed as
 * a plain `InventoryItem` query alone.
 */
final class ShortageAndZeroStockQuery implements ReportHandlerInterface
{
    public function reportId(): string
    {
        return 'RPT-INV-04';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'warehouse_id' => ['required', 'uuid'],
        ])->validate();

        return [
            'warehouse_id' => $validated['warehouse_id'],
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $warehouseId = $filters['warehouse_id'];

        // MET-INV-04 — Stock Shortage (Material), summed across active waves in this warehouse.
        $activeWaveIds = PreparationWave::query()
            ->where('company_id', $context->companyId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', WaveStatus::activeValues())
            ->pluck('id');

        $shortageRows = $activeWaveIds->isEmpty()
            ? collect()
            : WaveMissingMaterial::query()
                ->whereIn('preparation_wave_id', $activeWaveIds)
                ->selectRaw('material_id, material_name')
                ->selectRaw('COALESCE(SUM(missing_qty), 0) as missing_qty')
                ->groupBy('material_id', 'material_name')
                ->orderByDesc('missing_qty')
                ->limit(50)
                ->get();

        $totalShortageQty = (float) $shortageRows->sum('missing_qty');

        // MET-INV-05 — Zero-Stock Product Count: active products with on_hand_qty <= 0 or no
        // InventoryItem row at all, for this warehouse.
        $zeroStockCount = (int) DB::table('products')
            ->leftJoin('inventory_items', function ($join) use ($warehouseId): void {
                $join->on('inventory_items.product_id', '=', 'products.id')
                    ->where('inventory_items.warehouse_id', '=', $warehouseId);
            })
            ->where('products.company_id', $context->companyId)
            ->where('products.is_active', true)
            ->where(function ($q): void {
                $q->whereNull('inventory_items.id')
                    ->orWhere('inventory_items.on_hand_qty', '<=', 0);
            })
            ->count('products.id');

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                'MET-INV-04' => round($totalShortageQty, 4),
                'MET-INV-05' => $zeroStockCount,
            ],
            rows: $shortageRows->map(static fn (object $row): array => [
                'material_id' => $row->material_id,
                'material_name' => $row->material_name,
                'missing_qty' => round((float) $row->missing_qty, 4),
            ])->values()->all(),
            totals: [
                'active_wave_count' => $activeWaveIds->count(),
                'zero_stock_product_count' => $zeroStockCount,
            ],
            period: new ReportPeriod(null, null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
