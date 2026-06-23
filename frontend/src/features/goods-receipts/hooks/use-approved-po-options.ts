import { useQuery } from '@tanstack/react-query';

import { purchaseOrdersService } from '@/features/purchase-orders/services/purchase-orders-service';
import type { ComboboxOption } from '@/components/crud/combobox';

export function useApprovedPoOptions() {
  return useQuery({
    queryKey: ['purchase-orders-approved'],
    queryFn: async (): Promise<ComboboxOption[]> => {
      const result = await purchaseOrdersService.list({ per_page: 200, status: 'approved' });
      return result.items.map((po) => ({ value: po.id, label: po.po_number }));
    },
    staleTime: 30_000,
  });
}
