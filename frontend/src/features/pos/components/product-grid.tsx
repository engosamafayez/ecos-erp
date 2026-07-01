import { Loader2 } from 'lucide-react';

import { ProductCard } from '@/features/pos/components/product-card';
import { ProductSearch } from '@/features/pos/components/product-search';
import { useCatalog } from '@/features/pos/hooks/use-pos-queries';
import type { Product } from '@/features/pos/types';
import { useState } from 'react';

type ProductGridProps = {
  onProductSelect: (product: Product) => void;
};

export function ProductGrid({ onProductSelect }: ProductGridProps) {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, isFetching } = useCatalog({
    search: search || undefined,
    page,
  });

  const products = data?.data ?? [];
  const totalPages = data?.last_page ?? 1;

  return (
    <div className="flex flex-col gap-3 h-full min-h-0">
      <ProductSearch value={search} onChange={(v) => { setSearch(v); setPage(1); }} />

      {/* Grid */}
      <div className="flex-1 overflow-y-auto">
        {isLoading ? (
          <div className="flex h-32 items-center justify-center">
            <Loader2 className="size-6 animate-spin text-muted-foreground" />
          </div>
        ) : products.length === 0 ? (
          <div className="flex h-32 items-center justify-center text-sm text-muted-foreground">
            {search ? 'No products found' : 'No products available'}
          </div>
        ) : (
          <div className="grid grid-cols-3 gap-2 pb-2">
            {products.map((product) => (
              <ProductCard
                key={product.id}
                product={product}
                onClick={onProductSelect}
                disabled={isFetching}
              />
            ))}
          </div>
        )}
      </div>

      {/* Pagination */}
      {totalPages > 1 && (
        <div className="flex items-center justify-center gap-2 pt-1">
          <button
            disabled={page <= 1}
            onClick={() => setPage((p) => p - 1)}
            className="rounded px-2 py-0.5 text-xs disabled:opacity-40 hover:bg-accent"
          >
            ‹ Prev
          </button>
          <span className="text-xs text-muted-foreground">
            {page} / {totalPages}
          </span>
          <button
            disabled={page >= totalPages}
            onClick={() => setPage((p) => p + 1)}
            className="rounded px-2 py-0.5 text-xs disabled:opacity-40 hover:bg-accent"
          >
            Next ›
          </button>
        </div>
      )}
    </div>
  );
}
