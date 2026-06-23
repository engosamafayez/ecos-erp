import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { channelsService } from '@/features/channels/services/channels-service';
import type { ChannelPayload, ChannelsQuery, ImportResult } from '@/features/channels/types/channel';

export const CHANNELS_KEY = 'channels';

export function useChannelsQuery(params: ChannelsQuery) {
  return useQuery({
    queryKey: [CHANNELS_KEY, params],
    queryFn: () => channelsService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCreateChannel() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: ChannelPayload) => channelsService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CHANNELS_KEY] }),
  });
}

export function useUpdateChannel() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ChannelPayload }) =>
      channelsService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CHANNELS_KEY] }),
  });
}

export function useDeleteChannel() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => channelsService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CHANNELS_KEY] }),
  });
}

export function useTestConnection() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => channelsService.testConnection(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CHANNELS_KEY] }),
  });
}

export function useImportProducts() {
  const queryClient = useQueryClient();
  return useMutation<ImportResult, Error, string>({
    mutationFn: (id: string) => channelsService.importProducts(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [CHANNELS_KEY] });
      queryClient.invalidateQueries({ queryKey: ['product-mappings'] });
      queryClient.invalidateQueries({ queryKey: ['products-all'] });
    },
  });
}
