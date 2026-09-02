<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Services;

use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

/**
 * Syncs a Supplier's Supply Capabilities (Raw Materials + Product Categories)
 * to an exact target set — full-replace semantics, matching a multi-select
 * UI where the submitted list IS the complete desired state.
 *
 * Uses explicit attach()/detach() rather than sync() so that an assignment
 * already in place keeps its ORIGINAL `created_by` (the audit fact of who
 * first declared it) — a plain sync() with pivot data on every row would
 * overwrite created_by for every unchanged assignment on every subsequent
 * edit, which is not what the audit trail (§18) means.
 */
final class SupplierCapabilitySyncService
{
    /**
     * @param  list<string>  $rawMaterialIds
     * @param  list<string>  $productCategoryIds
     */
    public function sync(Supplier $supplier, array $rawMaterialIds, array $productCategoryIds, ?int $actorId): void
    {
        $this->syncRelation($supplier->rawMaterials(), $rawMaterialIds, $actorId);
        $this->syncRelation($supplier->productCategories(), $productCategoryIds, $actorId);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Database\Eloquent\Model, Supplier>  $relation
     * @param  list<string>  $targetIds
     */
    private function syncRelation($relation, array $targetIds, ?int $actorId): void
    {
        $current = $relation->allRelatedIds()->all();

        $toDetach = array_values(array_diff($current, $targetIds));
        $toAttach = array_values(array_diff($targetIds, $current));

        if ($toDetach !== []) {
            $relation->detach($toDetach);
        }

        if ($toAttach !== []) {
            $relation->attach(array_fill_keys($toAttach, ['created_by' => $actorId]));
        }
    }
}
