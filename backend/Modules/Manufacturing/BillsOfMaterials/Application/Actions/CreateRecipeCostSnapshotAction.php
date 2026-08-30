<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\CostManagement\Application\Services\CostCalculationEngine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Exceptions\RecipeCostSnapshotException;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\BillOfMaterial;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\RecipeCostSnapshot;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\RecipeCostSnapshotLine;

/**
 * Freezes the per-component cost of a recipe at the moment it is approved.
 *
 * WHY THIS EXISTS
 * ---------------
 * Disassembly has to answer "what is this component worth?" for goods that were
 * built at some point in the past. Every other candidate answer is wrong:
 *
 *   the material's cost today  — changes after the goods were built
 *   the finished product's cost — that is the whole, not the parts
 *   the FG's FIFO layer cost    — what it cost to ACQUIRE the assembled product,
 *                                 which includes labour and overhead the components
 *                                 never carried
 *
 * The only defensible answer is what the approved recipe said each component cost
 * at approval. Nothing recorded that before this action existed.
 *
 * IMMUTABILITY
 * ------------
 * A snapshot is written once and never updated. Material cost changes cascade to
 * `recipe_cost` and pricing reviews, and they must NOT reach back into an existing
 * snapshot — goods already built were built at the old cost. Re-approving a recipe
 * whose components changed produces a new version with a new snapshot; the previous
 * one survives untouched so historical disassemblies stay valued correctly.
 */
final class CreateRecipeCostSnapshotAction
{
    public function __construct(private readonly CostCalculationEngine $costs) {}

    /**
     * @param  string|null  $approvedBy  User UUID; null for system-initiated approval.
     *
     * @throws RecipeCostSnapshotException when the recipe cannot be priced.
     */
    public function execute(BillOfMaterial $bom, ?string $approvedBy = null): RecipeCostSnapshot
    {
        $bom->loadMissing(['lines.rawMaterial.unit', 'product']);

        // ── Idempotency ───────────────────────────────────────────────────────
        //
        // Re-approving the SAME version returns the existing snapshot rather than
        // writing a second one. Activation is a toggle an operator may flip more
        // than once, and each flip must not rewrite history at whatever the
        // material costs that day.
        $existing = RecipeCostSnapshot::query()
            ->where('bom_id', $bom->id)
            ->where('bom_version_number', (int) ($bom->bom_version_number ?? 1))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $lines = $this->costs->calculateLines($bom);

        // ── Fail closed ───────────────────────────────────────────────────────
        //
        // These are refusals, not warnings. A snapshot that is partial or empty
        // would be worse than none at all: it would look authoritative while
        // silently valuing part of a disassembly at zero.
        if ($lines === []) {
            throw RecipeCostSnapshotException::noComponents($bom->bom_number ?? (string) $bom->id);
        }

        $unpriced = array_values(array_filter($lines, static fn (array $l): bool => $l['has_cost'] === false));

        if ($unpriced !== []) {
            throw RecipeCostSnapshotException::unpricedComponents(
                $bom->bom_number ?? (string) $bom->id,
                array_map(static fn (array $l): string => $l['sku'] ?? $l['material_id'], $unpriced),
            );
        }

        $companyId = $this->resolveCompanyId($bom);
        $totalCost = round(array_sum(array_column($lines, 'extended_cost')), 4);

        return DB::transaction(function () use ($bom, $lines, $companyId, $totalCost, $approvedBy): RecipeCostSnapshot {
            $snapshot = new RecipeCostSnapshot;
            $snapshot->fill([
                'company_id' => $companyId,
                'bom_id' => (string) $bom->id,
                'bom_version_number' => (int) ($bom->bom_version_number ?? 1),
                'total_cost' => $totalCost,
                'yield_quantity' => max((float) ($bom->yield_quantity ?? 1.0), 0.0001),
                'approved_at' => now(),
                'approved_by' => $approvedBy,
            ]);
            $snapshot->save();

            foreach ($lines as $line) {
                $snapshotLine = new RecipeCostSnapshotLine;
                $snapshotLine->fill([
                    'snapshot_id' => $snapshot->id,
                    'recipe_line_id' => $line['line_id'],
                    'raw_material_id' => $line['material_id'],
                    // The recipe's stated quantity, not the waste-adjusted one. Waste is
                    // kept as its own column so the snapshot stays readable as "what the
                    // recipe said" — `extended_cost` already has the adjustment applied.
                    'quantity' => $line['quantity'],
                    'waste_percentage' => $line['waste_percentage'],
                    'unit_cost' => $line['unit_cost'],
                    'extended_cost' => $line['extended_cost'],
                    'sku_snapshot' => $line['sku'],
                    'name_snapshot' => $line['name'],
                    'unit_symbol_snapshot' => $line['unit_symbol'],
                ]);
                $snapshotLine->save();
            }

            return $snapshot->load('lines');
        });
    }

    /**
     * Resolve the owning company through product → brand → company.
     *
     * Neither `bills_of_materials` nor `products` carries a `company_id`; the brand
     * is the only link. It is resolved once here and stored on the snapshot so
     * tenant scoping never depends on a join that a later re-brand of the product
     * would silently redirect.
     */
    private function resolveCompanyId(BillOfMaterial $bom): ?string
    {
        $product = $bom->product ?? Product::query()->find($bom->product_id);
        $companyId = $product?->brand?->company_id;

        return $companyId !== null ? (string) $companyId : null;
    }
}
