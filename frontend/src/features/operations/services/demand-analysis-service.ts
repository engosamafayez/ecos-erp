import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type { DemandAnalysisResult } from '@/features/operations/types/demand-analysis';

export const demandAnalysisService = {
  async getAnalysis(date?: string): Promise<DemandAnalysisResult> {
    const { data } = await api.get<ApiResponse<DemandAnalysisResult>>(
      '/operations/demand-analysis',
      { params: date ? { date } : {} },
    );
    return data.data;
  },
};
