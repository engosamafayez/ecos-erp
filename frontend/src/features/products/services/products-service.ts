import { api } from '@/lib/axios';
import type {
  ProductsQuery,
  ProductsResult,
  Product,
  ProductPayload,
} from '@/features/products/types/product';
import type { ApiResponse } from '@/types';

/**
 * Products API client. Unwraps the standardized ApiResponse envelope.
 */
export const productsService = {
  async list(params: ProductsQuery): Promise<ProductsResult> {
    const { data } = await api.get<ApiResponse<ProductsResult>>('/products', { params });
    return data.data;
  },

  async get(id: string): Promise<Product> {
    const { data } = await api.get<ApiResponse<Product>>(`/products/${id}`);
    return data.data;
  },

  async create(payload: ProductPayload): Promise<Product> {
    const { data } = await api.post<ApiResponse<Product>>('/products', payload);
    return data.data;
  },

  async update(id: string, payload: ProductPayload): Promise<Product> {
    const { data } = await api.put<ApiResponse<Product>>(`/products/${id}`, payload);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/products/${id}`);
  },
};
