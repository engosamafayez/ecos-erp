import { api } from '@/lib/axios';
import type {
  Customer,
  CustomerBlock,
  CustomerPayload,
  CustomersQuery,
  CustomersResult,
  SalesOwnerOption,
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

  // ── Print / Export (TASK-...-FINAL-UI-CLOSURE-014 §10/§11) ───────────────
  // Backend-authoritative: the SAME filters as list(), covering the full filtered
  // population server-side — never just the currently-rendered page, never built
  // from paginated fetches in the browser.

  async exportCsv(params: CustomersQuery): Promise<Blob> {
    const { data } = await api.get<Blob>('/customers/export', {
      params: { ...params, format: 'csv' },
      responseType: 'blob',
    });
    return data;
  },

  async exportHtml(params: CustomersQuery): Promise<string> {
    const { data } = await api.get<string>('/customers/export', {
      params: { ...params, format: 'html' },
      responseType: 'text',
    });
    return data;
  },

  // ── Sales Owner filter options (TASK-...-FINAL-UI-CLOSURE-014 §15) ───────

  async salesOwnerOptions(): Promise<SalesOwnerOption[]> {
    const { data } = await api.get<ApiResponse<SalesOwnerOption[]>>('/customers/sales-owners');
    return data.data;
  },
};
