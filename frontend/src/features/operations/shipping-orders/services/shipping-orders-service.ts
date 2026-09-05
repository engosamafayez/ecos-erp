import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type { ShippingOrdersQuery, ShippingOrdersResponse } from '../types/shipping-order';

export const shippingOrdersService = {
  async list(params: ShippingOrdersQuery): Promise<ShippingOrdersResponse> {
    const { data } = await api.get<ApiResponse<ShippingOrdersResponse>>(
      '/operations/shipping-orders',
      { params },
    );
    return data.data;
  },
};
