import { api } from '@/lib/axios';
import type {
  CustomerLookupResult,
  ManualOrderPayload,
  Order,
  OrderFinancialSnapshot,
  OrderPayload,
  OrdersQuery,
  OrdersResult,
  ProductPricingResult,
  ShippingCalcResult,
  ShippingPricingRule,
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

  async createManual(payload: ManualOrderPayload): Promise<Order> {
    const { data } = await api.post<ApiResponse<Order>>('/orders/manual', payload);
    return data.data;
  },

  async update(id: string, payload: OrderPayload): Promise<Order> {
    const { data } = await api.put<ApiResponse<Order>>(`/orders/${id}`, payload);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/orders/${id}`);
  },

  async searchByPhone(phone: string): Promise<CustomerLookupResult> {
    const { data } = await api.get<ApiResponse<CustomerLookupResult>>('/customers/search-by-phone', {
      params: { phone },
    });
    return data.data;
  },

  async patchOrder(id: string, payload: Record<string, unknown>): Promise<Order> {
    const { data } = await api.patch<ApiResponse<Order>>(`/orders/${id}`, payload);
    return data.data;
  },

  async productPricing(productId: string): Promise<ProductPricingResult> {
    const { data } = await api.get<ApiResponse<ProductPricingResult>>(`/orders/pricing/product/${productId}`);
    return data.data;
  },

  async snapshot(orderId: string): Promise<OrderFinancialSnapshot | null> {
    const { data } = await api.get<ApiResponse<OrderFinancialSnapshot | null>>(`/orders/${orderId}/snapshot`);
    return data.data;
  },

  async listShippingRules(): Promise<ShippingPricingRule[]> {
    const { data } = await api.get<ApiResponse<ShippingPricingRule[]>>('/shipping-pricing');
    return data.data;
  },

  async calculateShipping(
    params: { governorate: string; city?: string; area?: string },
  ): Promise<ShippingCalcResult> {
    const { data } = await api.get<ApiResponse<ShippingCalcResult>>('/shipping-pricing/calculate', { params });
    return data.data;
  },
};
