import { api } from '@/lib/axios';

/** A company warehouse plus whether it currently serves the brand. */
export type BrandWarehouseCoverageRow = {
  id: string;
  name: string;
  code: string | null;
  is_active: boolean;
  serves_brand: boolean;
};

const base = (brandId: string) => `/brands/${brandId}/warehouse-coverage`;

export const brandWarehouseCoverageService = {
  async list(brandId: string): Promise<BrandWarehouseCoverageRow[]> {
    const { data } = await api.get<{ data: BrandWarehouseCoverageRow[] }>(base(brandId));
    return data.data;
  },

  /** Save the COMPLETE set of warehouses that serve the brand (checkbox list). */
  async save(brandId: string, warehouseIds: string[]): Promise<BrandWarehouseCoverageRow[]> {
    const { data } = await api.put<{ data: BrandWarehouseCoverageRow[] }>(base(brandId), {
      warehouse_ids: warehouseIds,
    });
    return data.data;
  },
};
