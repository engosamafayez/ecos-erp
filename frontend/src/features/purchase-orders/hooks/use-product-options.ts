import { useQuery } from '@tanstack/react-query';

import { productsService } from '@/features/products/services/products-service';
import type { ComboboxOption } from '@/components/crud/combobox';

export function useProductOptions() {
  return useQuery({
    queryKey: ['products-all'],
    queryFn: async (): Promise<ComboboxOption[]> => {
      const result = await productsService.list({ per_page: 500, status: 'active' });
      return result.items.map((p) => ({ value: p.id, label: `${p.sku} – ${p.name}` }));
    },
    staleTime: 60_000,
  });
}
