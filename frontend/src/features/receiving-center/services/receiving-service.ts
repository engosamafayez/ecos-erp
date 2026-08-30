import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  ReceiveLineInput,
  ReceivingPoDetail,
  ReceivingQueueParams,
  ReceivingQueueResponse,
} from '../types/receiving';

// Read-only queue + a receive action that delegates to the certified Goods Receipt authority.
// This service never posts stock and never mutates Purchase Orders directly.
export const receivingService = {
  async queue(params: ReceivingQueueParams): Promise<ReceivingQueueResponse> {
    const { data } = await api.get<ApiResponse<ReceivingQueueResponse>>('/receiving/queue', { params });
    return data.data;
  },

  async poDetail(purchaseOrderId: string): Promise<ReceivingPoDetail> {
    const { data } = await api.get<ApiResponse<ReceivingPoDetail>>(
      `/receiving/purchase-orders/${purchaseOrderId}`,
    );
    return data.data;
  },

  async receive(
    purchaseOrderId: string,
    lines: ReceiveLineInput[],
  ): Promise<{ goods_receipt_id: string; purchase_order_id: string; status: string | null }> {
    const { data } = await api.post<
      ApiResponse<{ goods_receipt_id: string; purchase_order_id: string; status: string | null }>
    >(`/receiving/purchase-orders/${purchaseOrderId}/receive`, { lines });
    return data.data;
  },
};
