import { useQuery } from '@tanstack/react-query';

import type { ComboboxOption } from '@/components/crud/combobox';
import { customersService } from '@/features/customers/services/customers-service';

export function useCustomerOptions() {
  return useQuery({
    queryKey: ['customers-all'],
    queryFn: async (): Promise<ComboboxOption[]> => {
      const result = await customersService.list({ per_page: 200, status: 'active' });
      return result.items.map((c) => ({ value: c.id, label: `${c.code} – ${c.name}` }));
    },
    staleTime: 60_000,
  });
}
