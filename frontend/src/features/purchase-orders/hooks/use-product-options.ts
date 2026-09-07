import { useQuery } from '@tanstack/react-query';

import { productsService } from '@/features/products/services/products-service';
import type { ComboboxOption } from '@/components/crud/combobox';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

/**
 * Active-product options for the Purchase Order line-item picker.
 *
 * Was a one-shot `per_page: 500` fetch with no search — beyond 500 active
 * products, later ones were unreachable. Now accepts an optional
 * (caller-debounced) `search` term forwarded to the already-supported
 * `GET /products?search=&per_page=` (the same endpoint `useProductsQuery` /
 * `ProductLineSelect` already use successfully in Supplier Invoices).
 * Existing callers that don't pass `search` keep working, just against a
 * smaller (`per_page: 50`) default page.
 */
export function useProductOptions(search?: string) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, 'products-all', search ?? ''],
    queryFn: async (): Promise<ComboboxOption[]> => {
      const result = await productsService.list({ search: search || undefined, per_page: 50, status: 'active' });
      return result.items.map((p) => ({ value: p.id, label: `${p.sku} – ${p.name}` }));
    },
    staleTime: 60_000,
  });
}
