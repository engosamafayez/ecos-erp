<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Application\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ActiveRecipeResolver;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;

/**
 * Material -> Recipe -> Product -> Order Line attribution.
 *
 * TASK-PREPARATION-DEFICIT-DECISIONS-IMPACT-001 §12. This is NOT a second demand engine: it
 * reuses the same two authorities the wave projection uses —
 *   1. ActiveRecipeResolver, so exactly ONE recipe per product is exploded (joining
 *      bills_of_materials on is_active directly double-counts a product carrying two active
 *      versions; that bug was already repaired elsewhere in this module), and
 *   2. the canonical per-unit factor `quantity * (1 + waste_percentage / 100)` used by
 *      MaterialDemandCalculator to turn product demand into material demand.
 *
 * The only thing it adds is GRAIN: the wave projection aggregates to (wave, material) and
 * ProductDemandCalculator's GROUP BY destroys order-line identity, so the line-level figure
 * cannot be recovered downstream — it has to be re-joined here.
 *
 * IMPORTANT — what `impact_qty` means. It is the quantity of the material that THIS order line
 * REQUIRES. It is deliberately not a share of `missing_qty`: wave-level missing is netted
 * against own/postponed reservations and floored at zero AFTER aggregation, so it is not
 * linearly attributable to a line. Summing impact over every affected line therefore
 * reconciles with the material's Required, which is exactly the figure it is derived from.
 *
 * Reads only. Touches no inventory, reservation, ledger, goods receipt or purchase order.
 */
final class ShortageImpactAttributor
{
    public function __construct(private readonly ActiveRecipeResolver $activeRecipes) {}

    /**
     * Per-order-line impact of the given materials inside one wave.
     *
     * `$includePostponed` exists for ONE caller: deciding whether a postponed order may be
     * returned to preparation needs that order's own requirement, which the demand view
     * deliberately excludes. It defaults to false so every demand-facing call keeps the
     * canonical `postponed_at IS NULL` semantics.
     *
     * @param  array<int, string>  $materialIds
     * @return Collection<int, object{order_id: string, order_line_id: string, product_id: string,
     *                                product_name: string, material_id: string, line_qty: string,
     *                                impact_qty: string}>
     */
    public function forMaterials(
        PreparationWave $wave,
        array $materialIds,
        bool $includePostponed = false,
    ): Collection {
        if ($materialIds === []) {
            return collect();
        }

        // One recipe per product, resolved by the same authority the projection uses.
        $productIds = DB::table('order_lines as ol')
            ->join('preparation_wave_orders as pwo', 'pwo.order_id', '=', 'ol.order_id')
            ->where('pwo.preparation_wave_id', $wave->id)
            ->when(! $includePostponed, static fn ($q) => $q->whereNull('pwo.postponed_at'))
            ->distinct()
            ->pluck('ol.product_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $bomIds = array_values($this->activeRecipes->bomIdsByProduct($productIds));

        if ($bomIds === []) {
            return collect();
        }

        return DB::table('preparation_wave_orders as pwo')
            ->join('order_lines as ol', 'ol.order_id', '=', 'pwo.order_id')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->join('bills_of_materials as bom', 'bom.product_id', '=', 'ol.product_id')
            ->join('bill_of_material_lines as boml', 'boml.bom_id', '=', 'bom.id')
            ->where('pwo.preparation_wave_id', $wave->id)
            // Same membership predicate as ProductDemandCalculator, so a postponed order
            // leaves this attribution exactly as it leaves the demand it fed.
            ->when(! $includePostponed, static fn ($q) => $q->whereNull('pwo.postponed_at'))
            ->whereIn('bom.id', $bomIds)
            ->whereIn('boml.raw_material_id', $materialIds)
            ->select([
                'pwo.order_id',
                'ol.id as order_line_id',
                'ol.product_id',
                'p.name as product_name',
                'boml.raw_material_id as material_id',
                'ol.quantity as line_qty',
            ])
            // The canonical conversion, identical to MaterialDemandCalculator's
            // `$productRequiredQty * $qtyPerUnit * (1 + $wastePct / 100)`.
            ->selectRaw('ol.quantity * boml.quantity * (1 + COALESCE(boml.waste_percentage, 0) / 100) AS impact_qty')
            ->get();
    }
}
