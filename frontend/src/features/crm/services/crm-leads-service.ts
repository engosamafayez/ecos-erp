import { api } from '@/lib/axios';
import type {
  CrmLead,
  CrmLeadConvertResult,
  CrmLeadConvertValues,
  CrmLeadCreateValues,
  CrmLeadsMeta,
  CrmLeadsQuery,
  CrmLeadsResult,
  CrmLeadStatus,
  CrmSalesActivity,
} from '@/features/crm/types/crm-lead';
import type { ApiResponse } from '@/types';

/**
 * CRM-01 Task 1 — canonical CRM Lead API (`/crm/sales/leads`). Not the
 * CustomerEngagement `/customer-engagement/leads` endpoint — see crm-lead.ts.
 */
export const crmLeadsService = {
  async list(params: CrmLeadsQuery): Promise<CrmLeadsResult> {
    const { data } = await api.get<{ data: CrmLead[]; meta: CrmLeadsMeta }>('/crm/sales/leads', {
      params: { ...params, status: params.status === 'all' ? undefined : params.status },
    });
    return { data: data.data, meta: data.meta };
  },

  async get(id: string): Promise<CrmLead> {
    const { data } = await api.get<ApiResponse<CrmLead>>(`/crm/sales/leads/${id}`);
    return data.data;
  },

  async create(values: CrmLeadCreateValues): Promise<CrmLead> {
    const { data } = await api.post<ApiResponse<CrmLead>>('/crm/sales/leads', values);
    return data.data;
  },

  async setStatus(id: string, status: Exclude<CrmLeadStatus, 'converted'>): Promise<CrmLead> {
    const { data } = await api.patch<ApiResponse<CrmLead>>(`/crm/sales/leads/${id}/status`, {
      status,
    });
    return data.data;
  },

  async convert(id: string, values: CrmLeadConvertValues): Promise<CrmLeadConvertResult> {
    const { data } = await api.post<ApiResponse<CrmLeadConvertResult>>(
      `/crm/sales/leads/${id}/convert`,
      values,
    );
    return data.data;
  },

  async activities(leadId: string): Promise<CrmSalesActivity[]> {
    const { data } = await api.get<ApiResponse<CrmSalesActivity[]>>('/crm/sales/activities', {
      params: { subject_type: 'lead', subject_id: leadId },
    });
    return data.data;
  },

  async createActivity(
    leadId: string,
    payload: { activity_type: string; title: string; due_at?: string | null },
  ): Promise<CrmSalesActivity> {
    const { data } = await api.post<ApiResponse<CrmSalesActivity>>('/crm/sales/activities', {
      subject_type: 'lead',
      subject_id: leadId,
      ...payload,
    });
    return data.data;
  },

  async completeActivity(id: string): Promise<CrmSalesActivity> {
    const { data } = await api.patch<ApiResponse<CrmSalesActivity>>(
      `/crm/sales/activities/${id}/complete`,
    );
    return data.data;
  },

  async cancelActivity(id: string): Promise<CrmSalesActivity> {
    const { data } = await api.patch<ApiResponse<CrmSalesActivity>>(
      `/crm/sales/activities/${id}/cancel`,
    );
    return data.data;
  },
};
