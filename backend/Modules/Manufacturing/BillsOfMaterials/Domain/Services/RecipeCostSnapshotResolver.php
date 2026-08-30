<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Domain\Services;

use Modules\Manufacturing\BillsOfMaterials\Domain\Exceptions\RecipeCostSnapshotException;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\RecipeCostSnapshot;

/**
 * The only sanctioned way to obtain the cost basis for a recipe's components.
 *
 * Resolution is BY VERSION, never "the newest snapshot". Goods built under version 1
 * must be valued at version 1 prices even after version 2 is approved — resolving to
 * the latest would silently re-price historical stock every time a recipe changed,
 * which is precisely the failure this whole mechanism exists to prevent.
 *
 * There is deliberately no fallback. When no snapshot exists the resolver throws
 * rather than reaching for the material's current cost, the recipe's current
 * `recipe_cost`, or the finished product's FIFO cost. Each of those would produce a
 * confident, wrong number; an exception produces a correct refusal.
 */
final class RecipeCostSnapshotResolver
{
    /**
     * @throws RecipeCostSnapshotException when the recipe version has no snapshot.
     */
    public function resolveOrFail(string $bomId, int $bomVersionNumber, ?string $bomNumber = null): RecipeCostSnapshot
    {
        $snapshot = $this->resolve($bomId, $bomVersionNumber);

        if ($snapshot === null) {
            throw RecipeCostSnapshotException::missingForDisassembly($bomNumber ?? $bomId);
        }

        return $snapshot;
    }

    public function resolve(string $bomId, int $bomVersionNumber): ?RecipeCostSnapshot
    {
        return RecipeCostSnapshot::query()
            ->with('lines')
            ->where('bom_id', $bomId)
            ->where('bom_version_number', $bomVersionNumber)
            ->first();
    }

    public function exists(string $bomId, int $bomVersionNumber): bool
    {
        return RecipeCostSnapshot::query()
            ->where('bom_id', $bomId)
            ->where('bom_version_number', $bomVersionNumber)
            ->exists();
    }

    /**
     * Frozen cost per UNIT OF MATERIAL (per KG, per litre…), keyed by material id.
     *
     * This — not the line's extended cost — is what valuing a disassembly requires.
     * Extended cost is a batch total and only means anything alongside the batch
     * quantity; unit cost is dimensionally correct on its own, so recovering 5 KG of
     * a material frozen at 100/KG is worth 500 no matter what the recipe yielded or
     * how much of it is being taken apart.
     *
     * @return array<string, float>
     */
    public function unitCosts(RecipeCostSnapshot $snapshot): array
    {
        $costs = [];

        foreach ($snapshot->lines as $line) {
            // A recipe may list the same material on more than one line. The unit
            // cost is identical on each — it comes from the material, not the line —
            // so the first wins rather than being summed, which would multiply it.
            $costs[(string) $line->raw_material_id] ??= (float) $line->unit_cost;
        }

        return $costs;
    }
}
