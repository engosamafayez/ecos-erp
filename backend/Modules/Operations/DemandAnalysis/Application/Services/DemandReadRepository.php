<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Application\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Operations\DemandAnalysis\Domain\Models\WaveKpi;
use Modules\Operations\DemandAnalysis\Domain\Models\WaveManufacturingDemand;
use Modules\Operations\DemandAnalysis\Domain\Models\WaveMaterialDemand;
use Modules\Operations\DemandAnalysis\Domain\Models\WaveMissingMaterial;
use Modules\Operations\DemandAnalysis\Domain\Models\WaveProductDemand;

/**
 * Persistence layer for all demand read models.
 *
 * The engine calls upsert methods after calculation; the Preparation Workspace
 * calls read methods to render UI. Caching is controlled here — all engine
 * services remain cache-agnostic.
 *
 * Upserts use PostgreSQL ON CONFLICT to guarantee idempotency: identical inputs
 * always produce identical output regardless of how many times they are applied.
 */
final class DemandReadRepository
{
    // ── Reads ─────────────────────────────────────────────────────────────────

    /** @return Collection<int, WaveProductDemand> */
    public function getProductDemand(string $waveId): Collection
    {
        return WaveProductDemand::where('preparation_wave_id', $waveId)
            ->orderByDesc('required_qty')
            ->get();
    }

    /** @return Collection<int, WaveMaterialDemand> */
    public function getMaterialDemand(string $waveId): Collection
    {
        return WaveMaterialDemand::where('preparation_wave_id', $waveId)
            ->orderByDesc('required_qty')
            ->get();
    }

    /** @return Collection<int, WaveMissingMaterial> */
    public function getMissingMaterials(string $waveId): Collection
    {
        return WaveMissingMaterial::where('preparation_wave_id', $waveId)
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->get();
    }

    /** @return Collection<int, WaveManufacturingDemand> */
    public function getManufacturingDemand(string $waveId): Collection
    {
        return WaveManufacturingDemand::where('preparation_wave_id', $waveId)
            ->orderByDesc('required_qty')
            ->get();
    }

    public function getWaveKpis(string $waveId): ?WaveKpi
    {
        return WaveKpi::where('preparation_wave_id', $waveId)->first();
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function upsertProductDemand(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        // Chunk to avoid hitting parameter limits on large waves (1 M lines → many products).
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('wave_product_demand')->upsert(
                $chunk,
                ['preparation_wave_id', 'product_id'],
                // `prepared_qty` is DELIBERATELY ABSENT from this update list.
                // It is operator-owned (product-level Prepared, Option A); a demand
                // rebuild must refresh what the wave requires without discarding what
                // the floor has already prepared. remaining_qty / completion_pct are
                // likewise omitted because they are derived from it at read time.
                ['product_name', 'product_sku', 'required_qty',
                    'orders_count', 'data_hash', 'last_calculated_at', 'updated_at'],
            );
        }
    }

    /**
     * Invalidate "preparation completed" for products whose Required has moved.
     *
     * Completion is an explicit operator declaration: "I have finished preparing THIS
     * product for THIS wave". It is a statement about a specific Required quantity, so
     * the moment Required changes — an order postponed, added, removed, or its quantity
     * edited — the declaration no longer describes the work in front of the operator and
     * must not keep presenting the row as done.
     *
     * Run BEFORE upsertProductDemand(), which overwrites required_qty: this compares the
     * stored Required against the freshly calculated one, so it must see the old value.
     *
     * prepared_qty is deliberately NOT touched. Rule: the floor's number is never
     * discarded, only the completion claim is withdrawn. Remaining stays derived and
     * non-negative on its own (max(0, required - prepared)) even when Required drops
     * below what was already prepared.
     *
     * @param  list<array<string, mixed>>  $rows  freshly calculated product-demand rows
     */
    public function clearCompletionWhereRequiredChanged(string $waveId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $requiredByProduct = [];
        foreach ($rows as $row) {
            $requiredByProduct[(string) $row['product_id']] = (float) $row['required_qty'];
        }

        $stale = DB::table('wave_product_demand')
            ->where('preparation_wave_id', $waveId)
            ->whereNotNull('preparation_completed_at')
            ->whereIn('product_id', array_keys($requiredByProduct))
            ->get(['product_id', 'required_qty']);

        $invalidated = [];
        foreach ($stale as $row) {
            // Same 4-decimal rounding the calculators emit, so a pure float
            // representation difference never counts as a change.
            if (round((float) $row->required_qty, 4) !== round($requiredByProduct[$row->product_id], 4)) {
                $invalidated[] = $row->product_id;
            }
        }

        if ($invalidated === []) {
            return;
        }

        DB::table('wave_product_demand')
            ->where('preparation_wave_id', $waveId)
            ->whereIn('product_id', $invalidated)
            ->update(['preparation_completed_at' => null, 'updated_at' => now()]);
    }

    /**
     * Remove product-demand rows the latest calculation no longer produces.
     *
     * The projection was upsert-only, so a product whose demand fell to zero — the
     * exact effect of postponing the last order that wanted it — produced no row,
     * and a missing row is a no-op for an upsert. The stale row therefore survived
     * with its pre-postponement Required forever.
     *
     * Deliberately NOT inside upsertProductDemand(): that method early-returns on an
     * empty $rows, which is precisely the case where pruning matters most.
     *
     * @param  list<string>  $keepProductIds  products the calculation still returns
     * @param  list<string>|null  $scopeProductIds  limit to an incremental slice
     */
    public function deleteProductDemandNotIn(string $waveId, array $keepProductIds, ?array $scopeProductIds = null): void
    {
        DB::table('wave_product_demand')
            ->where('preparation_wave_id', $waveId)
            ->when($scopeProductIds !== null, fn ($q) => $q->whereIn('product_id', $scopeProductIds))
            ->when($keepProductIds !== [], fn ($q) => $q->whereNotIn('product_id', $keepProductIds))
            ->delete();
    }

    /**
     * Same pruning for material demand.
     *
     * Must run BEFORE deleteResolvedMissingMaterials(), which prunes
     * wave_missing_materials by reference to wave_material_demand.missing_qty — a
     * stale material row would otherwise keep a resolved shortage alive.
     *
     * @param  list<string>  $keepMaterialIds
     * @param  list<string>|null  $scopeMaterialIds
     */
    public function deleteMaterialDemandNotIn(string $waveId, array $keepMaterialIds, ?array $scopeMaterialIds = null): void
    {
        DB::table('wave_material_demand')
            ->where('preparation_wave_id', $waveId)
            ->when($scopeMaterialIds !== null, fn ($q) => $q->whereIn('material_id', $scopeMaterialIds))
            ->when($keepMaterialIds !== [], fn ($q) => $q->whereNotIn('material_id', $keepMaterialIds))
            ->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function upsertMaterialDemand(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('wave_material_demand')->upsert(
                $chunk,
                ['preparation_wave_id', 'material_id'],
                ['material_name', 'material_sku', 'required_qty', 'available_qty',
                    'reserved_qty', 'expected_today', 'in_transit_qty',
                    'missing_qty', 'coverage_pct', 'allow_negative', 'data_hash', 'last_calculated_at', 'updated_at'],
            );
        }
    }

    /**
     * Persist per-product preparation readiness onto the product-demand projection.
     *
     * Readiness is a classification of rows that already exist, so this UPDATES them and
     * never inserts: a product with no demand row has nothing to be ready for. Writing it
     * separately from `upsertProductDemand` keeps the readiness question (which needs the
     * material layer) out of the product calculator, which runs before materials exist.
     *
     * @param  array<string, array{material_status: string, blocking_materials_count: int}>  $readiness
     */
    public function upsertProductReadiness(string $waveId, array $readiness): void
    {
        foreach ($readiness as $productId => $state) {
            DB::table('wave_product_demand')
                ->where('preparation_wave_id', $waveId)
                ->where('product_id', $productId)
                ->update([
                    'material_status' => $state['material_status'],
                    'blocking_materials_count' => $state['blocking_materials_count'],
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function upsertMissingMaterials(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('wave_missing_materials')->upsert(
                $chunk,
                ['preparation_wave_id', 'material_id'],
                ['material_name', 'missing_qty', 'affected_orders_count',
                    'priority', 'procurement_status', 'last_calculated_at', 'updated_at'],
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function upsertManufacturingDemand(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('wave_manufacturing_demand')->upsert(
                $chunk,
                ['preparation_wave_id', 'product_id'],
                ['product_name', 'required_qty', 'planned_qty', 'manufacturing_qty',
                    'completed_qty', 'remaining_qty', 'last_calculated_at', 'updated_at'],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertWaveKpis(array $data): void
    {
        // Strip meta-keys (underscore-prefixed) that are meant for callers but not DB columns.
        $row = array_filter($data, static fn ($k) => ! str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);

        DB::table('wave_kpis')->upsert(
            [$row],
            ['preparation_wave_id'],
            ['orders_count', 'products_count', 'materials_count', 'missing_materials_count',
                'prepared_count', 'remaining_count', 'completion_pct',
                'last_calculated_at', 'updated_at'],
        );
    }

    // ── Cleanup ───────────────────────────────────────────────────────────────

    /** Remove all demand projections for a wave (e.g. when wave is deleted). */
    public function clearWaveDemand(string $waveId): void
    {
        DB::table('wave_product_demand')->where('preparation_wave_id', $waveId)->delete();
        DB::table('wave_material_demand')->where('preparation_wave_id', $waveId)->delete();
        DB::table('wave_missing_materials')->where('preparation_wave_id', $waveId)->delete();
        DB::table('wave_manufacturing_demand')->where('preparation_wave_id', $waveId)->delete();
        DB::table('wave_kpis')->where('preparation_wave_id', $waveId)->delete();
    }

    /** Remove resolved shortages (materials where missing_qty is now 0). */
    public function deleteResolvedMissingMaterials(string $waveId): void
    {
        DB::table('wave_missing_materials')
            ->where('preparation_wave_id', $waveId)
            ->whereNotIn(
                'material_id',
                DB::table('wave_material_demand')
                    ->where('preparation_wave_id', $waveId)
                    ->where('missing_qty', '>', 0)
                    ->pluck('material_id'),
            )
            ->delete();
    }

    // ── Idempotency helpers ───────────────────────────────────────────────────

    /**
     * Check if a product demand row is stale (hash changed).
     * Used by callers that want to skip publishing events when data didn't change.
     */
    public function hasProductDemandChanged(string $waveId, string $productId, string $newHash): bool
    {
        $existing = DB::table('wave_product_demand')
            ->where('preparation_wave_id', $waveId)
            ->where('product_id', $productId)
            ->value('data_hash');

        return $existing !== $newHash;
    }
}
