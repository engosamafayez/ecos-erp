import { useQuery } from '@tanstack/react-query';

import { warehousesService } from '@/features/warehouses/services/warehouses-service';
import type { ComboboxOption } from '@/components/crud/combobox';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

/**
 * Active-warehouse options for Goods Receipt / Receiving Center pickers.
 *
 * Was a one-shot `per_page: 200` fetch with no search — beyond 200 active
 * warehouses, later ones were unreachable. Now accepts an optional
 * (caller-debounced) `search` term forwarded to the already-supported
 * `GET /warehouses?search=&per_page=`. Existing callers that don't pass
 * `search` keep working, just against a smaller (`per_page: 50`) default page.
 */
export function useWarehouseOptions(search?: string) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, 'warehouses-all', search ?? ''],
    queryFn: async (): Promise<ComboboxOption[]> => {
      const result = await warehousesService.list({ search: search || undefined, per_page: 50, status: 'active' });
      return result.items.map((w) => ({ value: w.id, label: `${w.code} – ${w.name}` }));
    },
    staleTime: 60_000,
  });
}
