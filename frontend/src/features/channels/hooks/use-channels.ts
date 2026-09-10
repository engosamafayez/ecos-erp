import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type {
  ImportOrdersOptions,
  InitialImportPolicyPayload,
  OrdersSyncStatePayload,
} from '@/features/channels/services/channels-service';
import { channelsService } from '@/features/channels/services/channels-service';
import type {
  Channel,
  ChannelPayload,
  ChannelsQuery,
  ImportResult,
  OrderImportResult,
} from '@/features/channels/types/channel';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

export const CHANNELS_KEY = 'channels';

export function useChannelsQuery(params: ChannelsQuery, options?: { enabled?: boolean }) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, CHANNELS_KEY, params],
    queryFn: () => channelsService.list(params),
    placeholderData: keepPreviousData,
    enabled: options?.enabled ?? true,
  });
}

export function useCreateChannel() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: ChannelPayload) => channelsService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CHANNELS_KEY] }),
  });
}

export function useUpdateChannel() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ChannelPayload }) =>
      channelsService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CHANNELS_KEY] }),
  });
}

export function useDeleteChannel() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => channelsService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CHANNELS_KEY] }),
  });
}

export function useTestConnection() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => channelsService.testConnection(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CHANNELS_KEY] }),
  });
}

export function useImportProducts() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation<ImportResult, Error, string>({
    mutationFn: (id: string) => channelsService.importProducts(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['company', companyId, CHANNELS_KEY] });
      queryClient.invalidateQueries({ queryKey: ['company', companyId, 'product-mappings'] });
      queryClient.invalidateQueries({ queryKey: ['company', companyId, 'products-all'] });
    },
  });
}

export function useImportOrders() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation<OrderImportResult, Error, { id: string; options?: ImportOrdersOptions }>({
    mutationFn: ({ id, options }) => channelsService.importOrders(id, options),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['company', companyId, 'orders'] });
      queryClient.invalidateQueries({ queryKey: ['company', companyId, 'customers-all'] });
    },
  });
}

/** TASK-...-025 (P1/P3/W6-W8) — pause/resume, resume policy required only when resuming. */
export function useSetOrdersSyncState() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation<Channel, Error, { id: string; payload: OrdersSyncStatePayload }>({
    mutationFn: ({ id, payload }) => channelsService.setOrdersSyncState(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CHANNELS_KEY] }),
  });
}

/** TASK-...-025 (P4/W9) — first-activation cutoff policy. */
export function useSetInitialImportPolicy() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation<Channel, Error, { id: string; payload: InitialImportPolicyPayload }>({
    mutationFn: ({ id, payload }) => channelsService.setInitialImportPolicy(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CHANNELS_KEY] }),
  });
}
