<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * Evaluates whether a finished good can be manufactured given current material stock levels.
 *
 * A material is considered available when:
 *   available_qty > 0  OR  allow_negative_stock = true
 *
 * Possible statuses:
 *   'instock'       — all recipe materials are available
 *   'outofstock'    — at least one material is unavailable
 *   'recipe_missing' — no active recipe exists (cannot evaluate)
 */
final class ManufacturingAvailabilityService
{
    /**
     * Evaluate manufacturing availability for a finished good.
     *
     * @return array{
     *   status: 'instock'|'outofstock'|'recipe_missing',
     *   blocking_materials: list<array{id: string, sku: string, name: string, available_qty: float}>,
     *   components: list<array{id: string, sku: string, name: string, quantity: float, waste_percentage: float, available_qty: float, is_available: bool}>
     * }
     */
    public function evaluate(Product $product): array
    {
        if ($product->product_type !== Product::TYPE_FINISHED_GOOD) {
            return ['status' => 'recipe_missing', 'blocking_materials' => [], 'components' => []];
        }

        $recipe = $product->activeRecipe()->with('components.component')->first();

        if ($recipe === null) {
            return ['status' => 'recipe_missing', 'blocking_materials' => [], 'components' => []];
        }

        $componentIds = $recipe->components
            ->pluck('raw_material_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($componentIds)) {
            return ['status' => 'instock', 'blocking_materials' => [], 'components' => []];
        }

        // Canonical availability rule flag (EPIC-DATA-CONSOLIDATION-001, Phase B/D).
        // OFF (default): legacy sum-then-clamp. ON: clamp-per-warehouse-then-sum.
        $availExpr = (bool) config('inventory_ledger.canonical_summary')
            ? 'SUM(on_hand_qty - reserved_qty)'
            : 'SUM(on_hand_qty) - SUM(reserved_qty)';

        // F4 (TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001): component availability is
        // COMPANY-scoped. Ownership is ADR-013: Product -> Brand -> Company.
        //
        // The boundary is deliberately the COMPANY, never the Brand: one Raw Material
        // is legitimately shared by recipes of several Brands inside the same Company
        // (runtime-certified by RecipeCrossBrandReuseTest). Scoping by brand_id would
        // break that and is forbidden.
        //
        // A finished good with no derivable company FAILS CLOSED — it sees no
        // inventory at all rather than falling back to the global pool.
        $companyId = $product->brand?->company_id;

        $inventoryTotals = $companyId === null
            ? []
            : DB::table('inventory_items')
                ->whereNull('deleted_at')
                ->where('company_id', $companyId)
                ->whereIn('product_id', $componentIds)
                ->selectRaw("product_id, {$availExpr} as avail")
                ->groupBy('product_id')
                ->pluck('avail', 'product_id')
                ->map(fn ($v) => (float) $v)
                ->all();

        $blocking = [];
        $components = [];

        foreach ($recipe->components as $line) {
            $material = $line->component;
            if ($material === null) {
                continue;
            }

            $available = $inventoryTotals[$material->id] ?? 0.0;
            $isAvailable = $available > 0.0 || $material->allow_negative_stock;

            $components[] = [
                'id' => $material->id,
                'sku' => $material->sku,
                'name' => $material->name,
                'quantity' => (float) $line->quantity,
                'waste_percentage' => (float) ($line->waste_percentage ?? 0.0),
                'available_qty' => $available,
                'is_available' => $isAvailable,
            ];

            if (! $isAvailable) {
                $blocking[] = [
                    'id' => $material->id,
                    'sku' => $material->sku,
                    'name' => $material->name,
                    'available_qty' => $available,
                ];
            }
        }

        return [
            'status' => $blocking === [] ? 'instock' : 'outofstock',
            'blocking_materials' => $blocking,
            'components' => $components,
        ];
    }
}
