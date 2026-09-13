import { api } from '@/lib/axios';
import type {
  CrmOpportunitiesQuery,
  CrmOpportunity,
  CrmOpportunityCreateValues,
  CrmPipeline,
} from '@/features/crm/types/crm-pipeline';
import type { ApiResponse } from '@/types';

/** CRM-01 Task 2 — canonical `Crm\Sales` Pipeline/Opportunity API. */
export const crmPipelineService = {
  async pipelines(): Promise<CrmPipeline[]> {
    const { data } = await api.get<ApiResponse<CrmPipeline[]>>('/crm/sales/pipelines');
    return data.data;
  },

  async opportunities(params: CrmOpportunitiesQuery): Promise<CrmOpportunity[]> {
    const { data } = await api.get<ApiResponse<CrmOpportunity[]>>('/crm/sales/opportunities', {
      params: { ...params, status: params.status === 'all' ? undefined : params.status },
    });
    return data.data;
  },

  async create(values: CrmOpportunityCreateValues): Promise<CrmOpportunity> {
    const { data } = await api.post<ApiResponse<CrmOpportunity>>('/crm/sales/opportunities', values);
    return data.data;
  },

  async moveStage(id: string, stageId: string): Promise<CrmOpportunity> {
    const { data } = await api.patch<ApiResponse<CrmOpportunity>>(
      `/crm/sales/opportunities/${id}/stage`,
      { stage_id: stageId },
    );
    return data.data;
  },

  async win(id: string, orderReference?: string | null): Promise<CrmOpportunity> {
    const { data } = await api.patch<ApiResponse<CrmOpportunity>>(`/crm/sales/opportunities/${id}/win`, {
      order_reference: orderReference ?? null,
    });
    return data.data;
  },

  async lose(id: string, reason: string): Promise<CrmOpportunity> {
    const { data } = await api.patch<ApiResponse<CrmOpportunity>>(`/crm/sales/opportunities/${id}/lose`, {
      reason,
    });
    return data.data;
  },

  async reopen(id: string): Promise<CrmOpportunity> {
    const { data } = await api.patch<ApiResponse<CrmOpportunity>>(`/crm/sales/opportunities/${id}/reopen`);
    return data.data;
  },
};
