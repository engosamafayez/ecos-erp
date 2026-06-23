import { useQuery } from '@tanstack/react-query';

import type { ComboboxOption } from '@/components/crud/combobox';
import { channelsService } from '@/features/channels/services/channels-service';

export function useChannelOptions() {
  return useQuery({
    queryKey: ['channels-all'],
    queryFn: async (): Promise<ComboboxOption[]> => {
      const result = await channelsService.list({ per_page: 200, status: 'active' });
      return result.items.map((c) => ({ value: c.id, label: c.name }));
    },
    staleTime: 60_000,
  });
}
