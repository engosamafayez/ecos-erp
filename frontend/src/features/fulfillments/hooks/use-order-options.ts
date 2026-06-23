import { useQuery } from '@tanstack/react-query';

import type { ComboboxOption } from '@/components/crud/combobox';
import { ordersService } from '@/features/orders/services/orders-service';

export function useOrderOptions() {
  return useQuery({
    queryKey: ['orders-all'],
    queryFn: async (): Promise<ComboboxOption[]> => {
      const result = await ordersService.list({ per_page: 200 });
      return result.items.map((o) => ({
        value: o.id,
        label: `${o.order_number}${o.customer ? ` – ${o.customer.name}` : ''}`,
      }));
    },
    staleTime: 30_000,
  });
}
