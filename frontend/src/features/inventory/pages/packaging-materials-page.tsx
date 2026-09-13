import { RawMaterialsPage } from '@/features/raw-materials/pages/raw-materials-page';

/**
 * Packaging materials share the same canonical reporting authority as raw
 * materials — both are `Product` rows with real `inventory_items` stock,
 * discriminated only by `product_type` (see Product::TYPE_PACKAGING_MATERIAL).
 * `RawMaterialsPage` already builds its On Hand/Reserved/Available/Value
 * table, filters and stats generically over both types (its filter bar and
 * stats card already branch on 'packaging_material'); this page only needed
 * to actually render that existing machinery, pre-scoped to packaging
 * materials, instead of the placeholder it was.
 */
export function PackagingMaterialsPage() {
  return <RawMaterialsPage defaultMaterialType="packaging_material" />;
}
