import { api } from '@/lib/axios';
import type {
  GoLiveStatus,
  OpeningBalancePayload,
  OpeningInventoryLine,
  OpeningInventoryResult,
  ResetExecutePayload,
  ResetExecuteResult,
  ResetPreview,
  ResetPreviewPayload,
} from '@/features/golive/types/golive';
import type { ApiResponse } from '@/types';

/**
 * TASK-...-026 — thin API layer over the Go-Live Preparation backend. Company scope is always
 * the authenticated actor's own tenant context (resolved server-side); no company id is ever
 * passed from here.
 */
export const goliveService = {
  async status(): Promise<GoLiveStatus> {
    const { data } = await api.get<ApiResponse<GoLiveStatus>>('/golive/status');
    return data.data;
  },

  async preview(payload: ResetPreviewPayload): Promise<ResetPreview> {
    const { data } = await api.post<ApiResponse<ResetPreview>>('/golive/reset/preview', payload);
    return data.data;
  },

  async execute(payload: ResetExecutePayload): Promise<ResetExecuteResult> {
    const { data } = await api.post<ApiResponse<ResetExecuteResult>>('/golive/reset/execute', payload);
    return data.data;
  },

  async establishOpeningInventory(lines: OpeningInventoryLine[]): Promise<OpeningInventoryResult> {
    const { data } = await api.post<ApiResponse<OpeningInventoryResult>>('/golive/opening-inventory', { lines });
    return data.data;
  },

  async postSupplierOpeningBalance(
    supplierId: string,
    payload: OpeningBalancePayload & { type: 'payable' | 'advance' },
  ): Promise<{ entry_id: string }> {
    const { data } = await api.post<ApiResponse<{ entry_id: string }>>(
      `/suppliers/${supplierId}/opening-balance`,
      payload,
    );
    return data.data;
  },

  async postCustomerOpeningBalance(customerId: string, payload: OpeningBalancePayload): Promise<{ entry_id: string }> {
    const { data } = await api.post<ApiResponse<{ entry_id: string }>>(
      `/customers/${customerId}/opening-balance`,
      payload,
    );
    return data.data;
  },

  async activate(): Promise<GoLiveStatus> {
    const { data } = await api.post<ApiResponse<GoLiveStatus>>('/golive/activate');
    return data.data;
  },
};
