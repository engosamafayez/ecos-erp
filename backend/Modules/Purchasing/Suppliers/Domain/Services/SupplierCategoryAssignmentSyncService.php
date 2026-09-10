<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Services;

use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

/**
 * Syncs a Supplier's Supplier Category assignments to an exact target set —
 * full-replace semantics, matching a multi-select UI where the submitted list IS
 * the complete desired state. Mirrors {@see SupplierCapabilitySyncService} exactly
 * (same attach()/detach()-over-sync() reasoning: an assignment already in place
 * keeps its ORIGINAL `created_by`), kept as its own small service because Supplier
 * Category classification is a distinct concept from Supply Capabilities.
 */
final class SupplierCategoryAssignmentSyncService
{
    /**
     * @param  list<string>  $categoryIds
     */
    public function sync(Supplier $supplier, array $categoryIds, ?int $actorId): void
    {
        $relation = $supplier->categories();
        $current = $relation->allRelatedIds()->all();

        $toDetach = array_values(array_diff($current, $categoryIds));
        $toAttach = array_values(array_diff($categoryIds, $current));

        if ($toDetach !== []) {
            $relation->detach($toDetach);
        }

        if ($toAttach !== []) {
            $relation->attach(array_fill_keys($toAttach, ['created_by' => $actorId]));
        }
    }
}
