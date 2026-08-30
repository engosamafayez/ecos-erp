<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\DemandAnalysis\Domain\Events\MaterialDemandUpdated;
use Modules\Operations\DemandAnalysis\Domain\Events\MissingMaterialsUpdated;
use Modules\Operations\DemandAnalysis\Domain\Events\ProductDemandUpdated;
use Modules\Operations\DemandAnalysis\Domain\Events\WaveDemandUpdated;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;

/**
 * Orchestrates the multi-step demand projection pipeline for a wave.
 *
 * Full recalculation:   products → materials → missing → KPIs → events
 * Incremental refresh:  only affected products/materials are recalculated;
 *                       unaffected rows are untouched.
 *
 * Events are published after each layer so downstream listeners can react
 * to partial updates as they arrive.
 */
final class DemandProjectionBuilder
{
    public function __construct(
        private readonly ProductDemandCalculator $productCalc,
        private readonly MaterialDemandCalculator $materialCalc,
        private readonly MissingMaterialCalculator $missingCalc,
        private readonly WaveKpiCalculator $kpiCalc,
        private readonly DemandReadRepository $repository,
        private readonly ProductReadinessCalculator $readinessCalc,
    ) {}

    // ── Full recalculation ────────────────────────────────────────────────────

    public function buildFull(PreparationWave $wave, string $trigger = 'full_refresh'): void
    {
        // Layer 1 – product demand
        $productRows = $this->productCalc->calculate($wave);
        // Must precede the upsert — it compares stored Required against the new one.
        $this->repository->clearCompletionWhereRequiredChanged($wave->id, $productRows);
        $this->repository->upsertProductDemand($productRows);
        // Drop products the wave no longer demands (e.g. every order wanting them was
        // postponed). Without this the projection only ever grows.
        $this->repository->deleteProductDemandNotIn($wave->id, array_column($productRows, 'product_id'));

        event(new ProductDemandUpdated(
            $wave->id,
            $wave->company_id,
            $wave->warehouse_id,
            count($productRows),
            $trigger,
        ));

        // Layer 2 – material demand (reads product demand from DB)
        $materialRows = $this->materialCalc->calculate($wave);
        $this->repository->upsertMaterialDemand($materialRows);
        // Must precede deleteResolvedMissingMaterials(), which prunes missing rows by
        // reference to wave_material_demand.
        $this->repository->deleteMaterialDemandNotIn($wave->id, array_column($materialRows, 'material_id'));

        // Layer 3 – missing materials
        $this->repository->deleteResolvedMissingMaterials($wave->id);
        $missingRows = $this->missingCalc->calculate($wave);
        $this->repository->upsertMissingMaterials($missingRows);

        // Layer 3b – per-product preparation readiness.
        //
        // Runs here because it is the first point where BOTH the product rows and the
        // material shortage are persisted. It answers a different question from Missing:
        // Missing is the real physical shortage (Procurement), readiness is whether that
        // shortage blocks preparation (allow_negative → it does not). Per product only —
        // the wave is never blocked, and no order or reservation is touched.
        $this->repository->upsertProductReadiness($wave->id, $this->readinessCalc->calculate($wave));

        $hasCritical = count(array_filter($missingRows, fn ($r) => $r['priority'] === 'critical')) > 0;

        event(new MaterialDemandUpdated(
            $wave->id,
            $wave->company_id,
            $wave->warehouse_id,
            count($materialRows),
            count($missingRows),
            $trigger,
        ));

        event(new MissingMaterialsUpdated(
            $wave->id,
            $wave->company_id,
            $wave->warehouse_id,
            count($missingRows),
            $hasCritical,
            $trigger,
        ));

        // Layer 4 – KPIs (reads from DB)
        $kpiData = $this->kpiCalc->calculate($wave);
        $this->repository->upsertWaveKpis($kpiData);
        $this->syncWaveHeader($wave->id, $kpiData);

        event(new WaveDemandUpdated(
            $wave->id,
            $wave->company_id,
            $wave->warehouse_id,
            $kpiData['orders_count'],
            $kpiData['products_count'],
            $kpiData['materials_count'],
            $kpiData['missing_materials_count'],
            $kpiData['completion_pct'],
            $trigger,
        ));
    }

    // ── Incremental refresh (order-level) ─────────────────────────────────────

    /**
     * Recalculate only the products that belong to the given orders.
     * Unaffected products/materials remain unchanged.
     *
     * @param  list<string>  $affectedOrderIds
     */
    public function buildIncremental(PreparationWave $wave, array $affectedOrderIds, string $trigger = 'incremental'): void
    {
        if (empty($affectedOrderIds)) {
            return;
        }

        // Derive affected product IDs from order lines (no model load needed).
        $affectedProductIds = $this->productCalc->productIdsForOrders($affectedOrderIds);

        if (empty($affectedProductIds)) {
            $this->refreshKpis($wave, $trigger);

            return;
        }

        $this->buildForProducts($wave, $affectedProductIds, $trigger);
    }

    /**
     * Recalculate only the given product rows (and their derived materials).
     *
     * @param  list<string>  $productIds
     */
    public function buildForProducts(PreparationWave $wave, array $productIds, string $trigger = 'product_refresh'): void
    {
        // Layer 1
        $productRows = $this->productCalc->calculate($wave, $productIds);
        // Must precede the upsert — it compares stored Required against the new one.
        $this->repository->clearCompletionWhereRequiredChanged($wave->id, $productRows);
        $this->repository->upsertProductDemand($productRows);
        // Scoped prune: within the slice we just recalculated, any product that no
        // longer appears has genuinely fallen to zero demand (its orders were
        // postponed or detached). Products outside the slice are untouched.
        $this->repository->deleteProductDemandNotIn(
            $wave->id,
            array_column($productRows, 'product_id'),
            $productIds,
        );

        event(new ProductDemandUpdated(
            $wave->id,
            $wave->company_id,
            $wave->warehouse_id,
            count($productRows),
            $trigger,
        ));

        // Layer 2 — re-explode only affected products
        $materialRows = $this->materialCalc->calculate($wave, $productIds);
        $this->repository->upsertMaterialDemand($materialRows);
        // No material prune on the incremental path — deliberately. A material row is
        // shared across products, so the set "materials previously attributed to these
        // products" is not derivable here; pruning by the recalculated slice alone would
        // delete materials still demanded by products outside it. Postponement dispatches
        // DemandRefreshRequested -> DemandCalculationService::recalculate() -> buildFull(),
        // which prunes both layers, so the postponement contract is fully covered there.

        // Layer 3 — re-check shortages for affected materials only
        $affectedMaterialIds = array_column($materialRows, 'material_id');
        $this->repository->deleteResolvedMissingMaterials($wave->id);
        $missingRows = $this->missingCalc->calculate($wave, $affectedMaterialIds ?: null);
        $this->repository->upsertMissingMaterials($missingRows);

        // Layer 3b — readiness for the affected products only (same contract as buildFull).
        $this->repository->upsertProductReadiness($wave->id, $this->readinessCalc->calculate($wave, $productIds));

        $hasCritical = count(array_filter($missingRows, fn ($r) => $r['priority'] === 'critical')) > 0;

        event(new MaterialDemandUpdated(
            $wave->id,
            $wave->company_id,
            $wave->warehouse_id,
            count($materialRows),
            count($missingRows),
            $trigger,
        ));

        event(new MissingMaterialsUpdated(
            $wave->id,
            $wave->company_id,
            $wave->warehouse_id,
            count($missingRows),
            $hasCritical,
            $trigger,
        ));

        $this->refreshKpis($wave, $trigger);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Recompute the wave-level KPI + header totals from the CANONICAL product demand.
     *
     * TASK-PREPARATION-PART-3 section 7. Recording Prepared writes only
     * `wave_product_demand.prepared_qty`, so without this the wave header keeps the
     * `total_units_prepared` snapshot from the last demand rebuild — which is why a product
     * could read 2/2 = 100% while the wave header read 0%.
     *
     * Deliberately NOT buildFull()/buildForProducts(): preparation progress changes no
     * demand, no material requirement and no readiness. This recomputes the aggregate only,
     * reusing the same WaveKpiCalculator + syncWaveHeader the rebuild already uses, so there
     * is exactly one definition of wave completion.
     */
    public function refreshWaveTotals(PreparationWave $wave, string $trigger = 'preparation_recorded'): void
    {
        $this->refreshKpis($wave, $trigger);
    }

    private function refreshKpis(PreparationWave $wave, string $trigger): void
    {
        $kpiData = $this->kpiCalc->calculate($wave);
        $this->repository->upsertWaveKpis($kpiData);
        $this->syncWaveHeader($wave->id, $kpiData);

        event(new WaveDemandUpdated(
            $wave->id,
            $wave->company_id,
            $wave->warehouse_id,
            $kpiData['orders_count'],
            $kpiData['products_count'],
            $kpiData['materials_count'],
            $kpiData['missing_materials_count'],
            $kpiData['completion_pct'],
            $trigger,
        ));
    }

    private function syncWaveHeader(string $waveId, array $kpiData): void
    {
        DB::table('preparation_waves')
            ->where('id', $waveId)
            ->update([
                'products_count' => $kpiData['products_count'],
                'total_units_required' => $kpiData['_total_units_required'] ?? 0,
                'total_units_prepared' => $kpiData['_total_units_prepared'] ?? 0,
                'updated_at' => now(),
            ]);
    }
}
