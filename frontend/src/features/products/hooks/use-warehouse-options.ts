import { useQuery } from '@tanstack/react-query';

import { warehousesService } from '@/features/warehouses/services/warehouses-service';

export type WarehouseOption = { value: string; label: string };

export function useWarehouseOptions() {
  return useQuery({
    queryKey: ['warehouses-options'],
    queryFn: async (): Promise<WarehouseOption[]> => {
      const result = await warehousesService.list({ per_page: 200 });
      return result.items.map((w) => ({ value: w.id, label: w.name }));
    },
    staleTime: 60_000,
  });
}
