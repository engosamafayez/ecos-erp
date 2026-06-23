import { api } from '@/lib/axios';
import type {
  Order,
  OrderPayload,
  OrdersQuery,
  OrdersResult,
} from '@/features/orders/types/order';
import type { ApiResponse } from '@/types';

export const ordersService = {
  async list(params: OrdersQuery): Promise<OrdersResult> {
    const { data } = await api.get<ApiResponse<OrdersResult>>('/orders', { params });
    return data.data;
  },

  async get(id: string): Promise<Order> {
    const { data } = await api.get<ApiResponse<Order>>(`/orders/${id}`);
    return data.data;
  },

  async create(payload: OrderPayload): Promise<Order> {
    const { data } = await api.post<ApiResponse<Order>>('/orders', payload);
    return data.data;
  },

  async update(id: string, payload: OrderPayload): Promise<Order> {
    const { data } = await api.put<ApiResponse<Order>>(`/orders/${id}`, payload);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/orders/${id}`);
  },
};
