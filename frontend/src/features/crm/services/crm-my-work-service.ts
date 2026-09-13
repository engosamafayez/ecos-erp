import { api } from '@/lib/axios';
import type { CrmMyWork } from '@/features/crm/types/crm-pipeline';
import type { ApiResponse } from '@/types';

/** CRM-01 Task 2 — the current user's composed CRM work (see MyWorkController). */
export const crmMyWorkService = {
  async get(): Promise<CrmMyWork> {
    const { data } = await api.get<ApiResponse<CrmMyWork>>('/crm/my-work');
    return data.data;
  },
};
