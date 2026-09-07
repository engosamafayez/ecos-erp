import { useQuery } from '@tanstack/react-query';

import { suppliersService } from '@/features/suppliers/services/suppliers-service';
import type { ComboboxOption } from '@/components/crud/combobox';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

/**
 * Active-supplier options for pickers (Purchase Order header, Goods Receipt /
 * Receiving Center forms, Purchase Material supplier suggestions).
 *
 * Was a one-shot `per_page: 200` fetch with no search — beyond 200 active
 * suppliers, later ones were simply unreachable in any of these pickers. Now
 * accepts an optional (caller-debounced) `search` term forwarded to the
 * already-supported `GET /suppliers?search=&per_page=`, same shape the
 * Supplier list page itself uses. Existing callers that don't pass `search`
 * keep working, just against a smaller (`per_page: 50`) default page — the
 * ones with a lot of active suppliers should pass a search term.
 */
export function useSupplierOptions(search?: string) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, 'suppliers-all', search ?? ''],
    queryFn: async (): Promise<ComboboxOption[]> => {
      const result = await suppliersService.list({ search: search || undefined, per_page: 50, status: 'active' });
      return result.items.map((s) => ({ value: s.id, label: `${s.code} – ${s.name}` }));
    },
    staleTime: 60_000,
  });
}
