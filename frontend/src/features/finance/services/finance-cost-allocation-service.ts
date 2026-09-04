import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type {
  CostAllocation,
  CostAllocationCreateInput,
  CostAllocationListParams,
} from '../types/finance-cost-allocation';

/**
 * Finance Cost Allocation API client (Costing & Profitability), against the
 * already-implemented CostAllocationController endpoints. Unwraps the
 * `{ data }` envelope. No backend changes.
 */
export const financeCostAllocationService = {
  async list(params: CostAllocationListParams = {}): Promise<CostAllocation[]> {
    const { data } = await api.get<ApiResponse<CostAllocation[]>>('/finance/cost-allocations', { params });
    return data.data;
  },

  async create(input: CostAllocationCreateInput): Promise<CostAllocation[]> {
    const { data } = await api.post<ApiResponse<CostAllocation[]>>('/finance/cost-allocations', input);
    return data.data;
  },

  async reverse(uuid: string, reason: string): Promise<CostAllocation> {
    const { data } = await api.post<ApiResponse<CostAllocation>>(
      `/finance/cost-allocations/${uuid}/reverse`,
      { reason },
    );
    return data.data;
  },
};
