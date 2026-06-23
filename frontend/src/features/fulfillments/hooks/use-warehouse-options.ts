import { useQuery } from '@tanstack/react-query';

import type { ComboboxOption } from '@/components/crud/combobox';
import { warehousesService } from '@/features/warehouses/services/warehouses-service';

export function useWarehouseOptions() {
  return useQuery({
    queryKey: ['warehouses-all'],
    queryFn: async (): Promise<ComboboxOption[]> => {
      const result = await warehousesService.list({ per_page: 200 });
      return result.items.map((w) => ({ value: w.id, label: `${w.code} – ${w.name}` }));
    },
    staleTime: 60_000,
  });
}
