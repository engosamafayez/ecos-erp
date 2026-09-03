import { api } from '@/lib/axios';
import type {
  Customer,
  CustomerBlock,
  CustomerPayload,
  CustomersQuery,
  CustomersResult,
} from '@/features/customers/types/customer';
import type { ApiResponse } from '@/types';

export const customersService = {
  async list(params: CustomersQuery): Promise<CustomersResult> {
    const { data } = await api.get<ApiResponse<CustomersResult>>('/customers', { params });
    return data.data;
  },

  async get(id: string): Promise<Customer> {
    const { data } = await api.get<ApiResponse<Customer>>(`/customers/${id}`);
    return data.data;
  },

  async create(payload: CustomerPayload): Promise<Customer> {
    const { data } = await api.post<ApiResponse<Customer>>('/customers', payload);
    return data.data;
  },

  async update(id: string, payload: CustomerPayload): Promise<Customer> {
    const { data } = await api.put<ApiResponse<Customer>>(`/customers/${id}`, payload);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/customers/${id}`);
  },

  // ── Blocked Customer (TASK-...-BLOCKED-CUSTOMERS-009) ────────────────────

  async block(id: string, reason: string): Promise<CustomerBlock> {
    const { data } = await api.post<ApiResponse<CustomerBlock>>(`/customers/${id}/block`, { reason });
    return data.data;
  },

  async blockPhone(phone: string, reason: string): Promise<CustomerBlock> {
    const { data } = await api.post<ApiResponse<CustomerBlock>>('/customers/block-phone', { phone, reason });
    return data.data;
  },

  async unblock(id: string, blockId: string, reason: string): Promise<CustomerBlock> {
    const { data } = await api.post<ApiResponse<CustomerBlock>>(`/customers/${id}/unblock`, {
      block_id: blockId,
      reason,
    });
    return data.data;
  },

  async blockHistory(id: string): Promise<CustomerBlock[]> {
    const { data } = await api.get<ApiResponse<CustomerBlock[]>>(`/customers/${id}/block-history`);
    return data.data;
  },
};
