import { ProductsView } from '@/features/products/components/products-view';

/**
 * Finished goods catalog. Reuses {@link ProductsView} with the finished_good type.
 */
export function ProductsPage() {
  return (
    <ProductsView
      productType="finished_good"
      title="Products"
      subtitle="Manage finished goods in the catalog."
      breadcrumbLabel="Products"
      searchPlaceholder="Search products…"
      createLabel="New Product"
      entityNoun="product"
    />
  );
}
