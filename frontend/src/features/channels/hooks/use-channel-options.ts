import { useQuery } from '@tanstack/react-query';

import { channelsService } from '@/features/channels/services/channels-service';

export type ChannelOption = { value: string; label: string };

export function useChannelOptions() {
  return useQuery({
    queryKey: ['channels-all'],
    queryFn: async (): Promise<ChannelOption[]> => {
      const result = await channelsService.list({ per_page: 200 });
      return result.items.map((c) => ({ value: c.id, label: c.name }));
    },
    staleTime: 60_000,
  });
}
