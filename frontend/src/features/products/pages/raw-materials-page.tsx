import { ProductsView } from '@/features/products/components/products-view';

/**
 * Raw materials catalog. Reuses {@link ProductsView} with the raw_material type.
 */
export function RawMaterialsPage() {
  return (
    <ProductsView
      productType="raw_material"
      title="Raw Materials"
      subtitle="Manage raw materials used to build finished goods."
      breadcrumbLabel="Raw Materials"
      searchPlaceholder="Search raw materials…"
      createLabel="New Raw Material"
      entityNoun="raw material"
    />
  );
}
