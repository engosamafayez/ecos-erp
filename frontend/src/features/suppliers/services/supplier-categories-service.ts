import { api } from '@/lib/axios';
import type { SupplierCategory, SupplierCategoryPayload } from '@/features/suppliers/types/supplier';
import type { ApiResponse } from '@/types';

/**
 * Supplier Category API client. Unwraps the standardized ApiResponse envelope.
 */
export const supplierCategoriesService = {
  async list(params: { active_only?: boolean } = {}): Promise<SupplierCategory[]> {
    const { data } = await api.get<ApiResponse<SupplierCategory[]>>('/supplier-categories', { params });
    return data.data;
  },

  async create(payload: SupplierCategoryPayload): Promise<SupplierCategory> {
    const { data } = await api.post<ApiResponse<SupplierCategory>>('/supplier-categories', payload);
    return data.data;
  },

  async update(id: string, payload: SupplierCategoryPayload): Promise<SupplierCategory> {
    const { data } = await api.put<ApiResponse<SupplierCategory>>(`/supplier-categories/${id}`, payload);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/supplier-categories/${id}`);
  },
};
