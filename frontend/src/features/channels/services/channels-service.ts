import { api } from '@/lib/axios';
import type {
  Channel,
  ChannelPayload,
  ChannelsQuery,
  ChannelsResult,
  ImportResult,
  OrderImportResult,
} from '@/features/channels/types/channel';
import type { ApiResponse } from '@/types';

export const channelsService = {
  async list(params: ChannelsQuery): Promise<ChannelsResult> {
    const { data } = await api.get<ApiResponse<ChannelsResult>>('/channels', { params });
    return data.data;
  },

  async get(id: string): Promise<Channel> {
    const { data } = await api.get<ApiResponse<Channel>>(`/channels/${id}`);
    return data.data;
  },

  async create(payload: ChannelPayload): Promise<Channel> {
    const { data } = await api.post<ApiResponse<Channel>>('/channels', payload);
    return data.data;
  },

  async update(id: string, payload: ChannelPayload): Promise<Channel> {
    const { data } = await api.put<ApiResponse<Channel>>(`/channels/${id}`, payload);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/channels/${id}`);
  },

  async testConnection(id: string): Promise<Channel> {
    const { data } = await api.post<ApiResponse<Channel>>(`/channels/${id}/test-connection`);
    return data.data;
  },

  async importProducts(id: string): Promise<ImportResult> {
    const { data } = await api.post<ApiResponse<ImportResult>>(`/channels/${id}/import-products`);
    return data.data;
  },

  async importOrders(id: string, options?: ImportOrdersOptions): Promise<OrderImportResult> {
    const { data } = await api.post<ApiResponse<OrderImportResult>>(`/channels/${id}/import-orders`, options);
    return data.data;
  },

  async setOrdersSyncState(id: string, payload: OrdersSyncStatePayload): Promise<Channel> {
    const { data } = await api.post<ApiResponse<Channel>>(`/channels/${id}/orders-sync/state`, payload);
    return data.data;
  },

  async setInitialImportPolicy(id: string, payload: InitialImportPolicyPayload): Promise<Channel> {
    const { data } = await api.post<ApiResponse<Channel>>(
      `/channels/${id}/orders-sync/initial-import-policy`,
      payload,
    );
    return data.data;
  },
};

export type ImportOrdersOptions = {
  mode?: 'live' | 'historical';
  after?: string;
  batch_id?: string;
};

export type OrdersSyncStatePayload = {
  state: 'paused' | 'enabled';
  resume_policy?: 'catch_up' | 'resume_from_now' | 'resume_from_point';
  resume_from?: string;
};

export type InitialImportPolicyPayload = {
  policy: 'from_now' | 'from_date' | 'last_n_days' | 'historical';
  date?: string;
  days?: number;
};
