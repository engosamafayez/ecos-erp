import { Package, Plus, Upload } from 'lucide-react';

import { Button } from '@/components/ui/button';

type ProductEmptyStateProps = {
  hasFilters: boolean;
  onCreateProduct: () => void;
  onImportProducts?: () => void;
  onClearFilters?: () => void;
};

export function ProductEmptyState({
  hasFilters,
  onCreateProduct,
  onImportProducts,
  onClearFilters,
}: ProductEmptyStateProps) {
  if (hasFilters) {
    return (
      <div className="flex flex-col items-center justify-center py-16 text-center">
        <div className="flex size-16 items-center justify-center rounded-2xl bg-muted">
          <Package className="size-8 text-muted-foreground" />
        </div>
        <h3 className="mt-4 text-base font-semibold">No products match your filters</h3>
        <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
          Try adjusting or clearing your current filters to see more products.
        </p>
        {onClearFilters ? (
          <Button variant="outline" size="sm" className="mt-4" onClick={onClearFilters}>
            Clear filters
          </Button>
        ) : null}
      </div>
    );
  }

  return (
    <div className="flex flex-col items-center justify-center py-16 text-center">
      <div className="flex size-20 items-center justify-center rounded-2xl bg-primary/10">
        <Package className="size-10 text-primary" />
      </div>
      <h3 className="mt-5 text-lg font-semibold">No products yet</h3>
      <p className="mt-2 max-w-sm text-sm text-muted-foreground">
        Start building your product catalog. Create your first product manually or
        import from a file.
      </p>
      <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
        <Button onClick={onCreateProduct}>
          <Plus className="size-4" />
          Create Product
        </Button>
        {onImportProducts ? (
          <Button variant="outline" onClick={onImportProducts}>
            <Upload className="size-4" />
            Import Products
          </Button>
        ) : null}
      </div>
    </div>
  );
}
