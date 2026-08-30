<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ActiveRecipeResolver;
use Modules\Operations\DemandAnalysis\Domain\Enums\MaterialPriority;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;

/**
 * Derives missing-material records from the already-calculated material demand.
 *
 * Rule: shortage = max(0, required – available). Negative shortages are never produced.
 * Affected-orders count is derived by traversing BOM backwards from material → product → orders.
 */
final class MissingMaterialCalculator
{
    public function __construct(private readonly ActiveRecipeResolver $activeRecipes) {}

    /**
     * @param  list<string>|null  $affectedMaterialIds  Null = all materials for wave.
     * @return list<array<string, mixed>>
     */
    public function calculate(PreparationWave $wave, ?array $affectedMaterialIds = null): array
    {
        $query = DB::table('wave_material_demand')
            ->where('preparation_wave_id', $wave->id)
            ->where('missing_qty', '>', 0);

        if ($affectedMaterialIds !== null && count($affectedMaterialIds) > 0) {
            $query->whereIn('material_id', $affectedMaterialIds);
        }

        $shortages = $query
            ->select(['material_id', 'material_name', 'missing_qty', 'required_qty'])
            ->get();

        if ($shortages->isEmpty()) {
            return [];
        }

        $materialIds = $shortages->pluck('material_id')->all();

        // Count affected orders per material: orders in wave that contain a
        // product whose BOM uses this material.
        $affectedCounts = $this->countAffectedOrders($wave->id, $materialIds);

        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($shortages as $row) {
            $missingQty = (float) $row->missing_qty;
            $requiredQty = (float) $row->required_qty;
            $priority = MaterialPriority::fromShortageRatio($missingQty, $requiredQty);
            $affectedCount = $affectedCounts[$row->material_id] ?? 0;

            $rows[] = [
                'id' => Str::uuid()->toString(),
                'company_id' => $wave->company_id,
                'warehouse_id' => $wave->warehouse_id,
                'preparation_wave_id' => $wave->id,
                'material_id' => $row->material_id,
                'material_name' => $row->material_name,
                'missing_qty' => round($missingQty, 4),
                'affected_orders_count' => $affectedCount,
                'priority' => $priority->value,
                'procurement_status' => null,
                'last_calculated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Return a map of material_id → affected_order_count for the given wave.
     *
     * Recipe selection goes through ActiveRecipeResolver, like every other explosion in
     * the module. This previously joined `bills_of_materials WHERE is_active = true`
     * directly, so a product carrying two active versions counted its orders once per
     * version and inflated affected_orders_count — the same duplication already repaired
     * in MaterialDemandCalculator and the material drill-down, left behind here.
     *
     * @param  list<string>  $materialIds
     * @return array<string, int>
     */
    private function countAffectedOrders(string $waveId, array $materialIds): array
    {
        $productIds = DB::table('preparation_wave_orders as pwo')
            ->join('order_lines as ol', 'ol.order_id', '=', 'pwo.order_id')
            ->where('pwo.preparation_wave_id', $waveId)
            ->whereNull('pwo.postponed_at')
            ->distinct()
            ->pluck('ol.product_id')
            ->all();

        $bomIds = array_values($this->activeRecipes->bomIdsByProduct($productIds));

        if ($bomIds === []) {
            return [];
        }

        // material → finished products (via the ONE active recipe) → orders in this wave
        $rows = DB::table('bill_of_material_lines as boml')
            ->join('bills_of_materials as bom', 'bom.id', '=', 'boml.bom_id')
            ->join('order_lines as ol', 'ol.product_id', '=', 'bom.product_id')
            ->join('preparation_wave_orders as pwo', function ($join) use ($waveId) {
                $join->on('pwo.order_id', '=', 'ol.order_id')
                    ->where('pwo.preparation_wave_id', $waveId)
                    // Postponed memberships are retained as history but have left the
                    // current cycle (REFINEMENT-002 §22).
                    ->whereNull('pwo.postponed_at');
            })
            ->whereIn('bom.id', $bomIds)
            ->whereIn('boml.raw_material_id', $materialIds)
            ->selectRaw('boml.raw_material_id AS material_id, COUNT(DISTINCT pwo.order_id) AS order_count')
            ->groupBy('boml.raw_material_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->material_id] = (int) $row->order_count;
        }

        return $map;
    }
}
