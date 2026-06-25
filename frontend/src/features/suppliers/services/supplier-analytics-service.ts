import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  SupplierAnalytics,
  SupplierInventoryProduct,
} from '@/features/suppliers/types/supplier-analytics';

export const supplierAnalyticsService = {
  async getAnalytics(supplierId: string): Promise<SupplierAnalytics> {
    const { data } = await api.get<ApiResponse<SupplierAnalytics>>(
      `/suppliers/${supplierId}/analytics`,
    );
    return data.data;
  },

  async getInventoryBreakdown(supplierId: string): Promise<SupplierInventoryProduct[]> {
    const { data } = await api.get<ApiResponse<SupplierInventoryProduct[]>>(
      `/suppliers/${supplierId}/inventory-breakdown`,
    );
    return data.data;
  },
};
