<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Domain\Services;

use Modules\Inventory\Products\Domain\Models\Product;

/**
 * Resolves the ONE active recipe per product, in bulk.
 *
 * This is a thin bulk adapter over the canonical accessor `Product::activeRecipe()`
 * — it restates no rule of its own. That relation is `ofMany('bom_version_number',
 * 'max')` filtered to `is_active`, on a SoftDeletes model, which means:
 *
 *   - highest bom_version_number wins when several versions are flagged active,
 *   - soft-deleted recipes are excluded,
 *   - exactly one recipe comes back per product, or none.
 *
 * WHY THIS EXISTS: `bills_of_materials` has no unique constraint on
 * (product_id, is_active). `EloquentRecipeRepository::deactivateOthers()` is a
 * write-path convention, not a database guarantee, and it cannot reach a
 * soft-deleted row that still carries `is_active = true`. Any consumer that joins
 * `bills_of_materials ... WHERE is_active = true` therefore aggregates EVERY active
 * version and silently multiplies the demand it derives.
 *
 * Callers that explode recipes over many products must use this rather than writing
 * their own join, so a product can never contribute its components twice.
 */
final class ActiveRecipeResolver
{
    /**
     * @param  list<string>  $productIds
     * @return array<string, string> product_id => bom_id (products without an active recipe are absent)
     */
    public function bomIdsByProduct(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $map = [];

        // withTrashed(): resolving a recipe is not the place to decide whether a product
        // still belongs in a projection. Callers pass the product IDs their own scope
        // already produced; silently dropping a soft-deleted product here would remove
        // its components from a shortage report without saying so, and a silent
        // under-report of a shortage is the most damaging failure this module has.
        foreach (Product::withTrashed()->whereIn('id', $productIds)->with('activeRecipe')->get() as $product) {
            $recipeId = $product->activeRecipe?->id;

            if ($recipeId !== null) {
                $map[(string) $product->id] = (string) $recipeId;
            }
        }

        return $map;
    }
}
