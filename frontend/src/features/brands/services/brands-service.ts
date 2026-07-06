import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type { Brand, BrandPayload, BrandsQuery, BrandsResult } from '@/features/brands/types/brand';

export const brandsService = {
  async list(params: BrandsQuery): Promise<BrandsResult> {
    const { data } = await api.get<ApiResponse<BrandsResult>>('/brands', { params });
    return data.data;
  },

  async get(id: string): Promise<Brand> {
    const { data } = await api.get<ApiResponse<Brand>>(`/brands/${id}`);
    return data.data;
  },

  async create(payload: BrandPayload): Promise<Brand> {
    const { data } = await api.post<ApiResponse<Brand>>('/brands', payload);
    return data.data;
  },

  async update(id: string, payload: Omit<BrandPayload, 'company_id'>): Promise<Brand> {
    const { data } = await api.put<ApiResponse<Brand>>(`/brands/${id}`, payload);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/brands/${id}`);
  },
};
