<?php

declare(strict_types=1);

namespace Modules\CostManagement\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\CostManagement\Domain\Enums\CostStrategy;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * EnterpriseCostEngine — the single source of truth for product cost valuation.
 *
 * EPIC-DATA-CONSOLIDATION-001, Phase C. Replaces the ~7 scattered, disagreeing
 * cost-selection strategies with one engine. Supports FIFO / Average / Standard;
 * FIFO is the canonical inventory-value basis (CostStrategy::canonical()).
 *
 * No consumer may pick a cost column directly — every "which cost" decision
 * routes through this engine so that valuation is consistent across every
 * dashboard, report, export, and API.
 */
final class EnterpriseCostEngine
{
    /**
     * The per-unit cost for a product under the given strategy.
     *
     * FIFO     → current_fifo_cost (oldest open layer's landed cost)
     * Average  → average_cost (weighted average maintained on receipt/invoice)
     * Standard → standard_cost (operator-set standard, nullable)
     *
     * Returns null when the chosen basis has never been populated.
     */
    public function unitCost(Product $product, ?CostStrategy $strategy = null): ?float
    {
        $strategy ??= CostStrategy::canonical();

        $value = match ($strategy) {
            CostStrategy::Fifo => $product->current_fifo_cost,
            CostStrategy::Average => $product->average_cost,
            CostStrategy::Standard => $product->standard_cost ?? null,
        };

        return $value !== null ? (float) $value : null;
    }

    /**
     * Canonical inventory value for a product.
     *
     * FIFO (canonical): Σ(remaining_qty × landed_unit_cost) over all open receipt
     * layers — each remaining unit valued at the exact cost it was received at.
     * This is the enterprise inventory-value basis.
     *
     * Average/Standard: on-hand quantity × the strategy's unit cost.
     */
    public function inventoryValue(
        string $productId,
        ?string $companyId = null,
        ?CostStrategy $strategy = null,
    ): float {
        $strategy ??= CostStrategy::canonical();

        if ($strategy === CostStrategy::Fifo) {
            return $this->fifoInventoryValue($productId, $companyId);
        }

        $product = Product::query()->find($productId);
        if ($product === null) {
            return 0.0;
        }

        $unit = $this->unitCost($product, $strategy) ?? 0.0;
        $onHand = $this->onHandQty($productId, $companyId);

        return round($onHand * $unit, 4);
    }

    /**
     * The canonical "best available" per-unit cost for a product, using the
     * FIFO-first fallback order: current_fifo_cost → average_cost →
     * last_purchase_cost → 0.
     *
     * This is the single definition of the ad-hoc cost fallback chains that were
     * scattered across stock/count/return actions. Pure property read (no DB) —
     * exposed statically so callers need no extra DI. Distinct from unitCost(),
     * which returns a single strategy's basis (null when unpopulated); this one
     * always resolves to a usable number via fallback.
     */
    public static function resolveUnitCost(Product $product): float
    {
        return (float) (
            $product->current_fifo_cost
            ?? $product->average_cost
            ?? $product->last_purchase_cost
            ?? 0
        );
    }

    /**
     * The canonical moving-weighted-average unit cost after receiving new stock.
     *
     * newAvg = (oldQty × oldCost + incomingQty × incomingCost) / (oldQty + incomingQty)
     *
     * When there is no resulting quantity, the incoming cost is the average.
     * This is the single definition of the weighted-average formula — receipt and
     * supplier-invoice posting both route through it so the calculation cannot drift.
     * Pure/deterministic: exposed statically so hot posting paths need no extra DI.
     */
    public static function weightedAverageCost(
        float $oldQty,
        float $oldCost,
        float $incomingQty,
        float $incomingCost,
    ): float {
        $totalQty = $oldQty + $incomingQty;

        return $totalQty > 0
            ? round(($oldQty * $oldCost + $incomingQty * $incomingCost) / $totalQty, 4)
            : $incomingCost;
    }

    /**
     * Canonical FIFO inventory value for an entire company (optionally one warehouse
     * within it), grouped by product — the one bulk-capable sibling of
     * {@see self::inventoryValue()}, which is per-product only by design (looping it
     * to build a company-wide total would be a real N+1: one query per product).
     *
     * TASK-ECOS-REPORTING-V1-SOURCE-REMEDIATION-007 §5: added so Reporting's inventory
     * valuation report can consume this engine directly instead of independently
     * mirroring the FIFO formula — reads the exact same `inventory_receipt_layers`
     * data {@see self::fifoInventoryValue()} reads for a single product, at the
     * row-set grain instead of the single-row grain. This is the same formula and
     * the same data source, never a second valuation strategy: FIFO only, matching
     * `CostStrategy::canonical()` — Average/Standard bulk valuation is not exposed
     * here since neither is the enterprise default and no caller has needed it yet.
     * Purely additive: no existing method's signature or behavior changes.
     *
     * @return list<array{product_id: string, remaining_qty: float, value: float}>
     */
    public function fifoInventoryValueByProduct(string $companyId, ?string $warehouseId = null): array
    {
        $query = DB::table('inventory_receipt_layers')
            ->where('company_id', $companyId)
            ->where('remaining_qty', '>', 0);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query
            ->selectRaw('product_id, COALESCE(SUM(remaining_qty), 0) as remaining_qty, COALESCE(SUM(remaining_qty * landed_unit_cost), 0) as value')
            ->groupBy('product_id')
            ->orderByDesc('value')
            ->get()
            ->map(static fn (object $row): array => [
                'product_id' => $row->product_id,
                'remaining_qty' => round((float) $row->remaining_qty, 4),
                'value' => round((float) $row->value, 4),
            ])
            ->all();
    }

    /**
     * FIFO valuation: sum of every open layer's remaining value.
     * Company-scoped when a company id is supplied.
     */
    private function fifoInventoryValue(string $productId, ?string $companyId): float
    {
        $query = DB::table('inventory_receipt_layers')
            ->where('product_id', $productId)
            ->where('remaining_qty', '>', 0);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $value = $query->selectRaw('COALESCE(SUM(remaining_qty * landed_unit_cost), 0) as v')->value('v');

        return round((float) $value, 4);
    }

    /**
     * Total on-hand for a product across warehouses (used only for Average/Standard
     * valuation — availability/on-hand as a metric is owned by InventorySummaryService).
     */
    private function onHandQty(string $productId, ?string $companyId): float
    {
        $query = DB::table('inventory_items')
            ->where('product_id', $productId)
            ->whereNull('deleted_at');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return (float) $query->selectRaw('COALESCE(SUM(on_hand_qty), 0) as q')->value('q');
    }
}
