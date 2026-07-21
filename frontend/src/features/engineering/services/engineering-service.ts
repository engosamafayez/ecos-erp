import { api } from '@/lib/axios';
import type {
  EngineeringDashboard,
  EngineeringFinding,
  EngineeringRun,
  PaginatedResponse,
} from '../types/engineering';

export const engineeringService = {
  async getDashboard(): Promise<EngineeringDashboard> {
    const res = await api.get<{ data: EngineeringDashboard }>('/system/engineering/dashboard');
    return res.data.data;
  },

  async getRuns(page = 1, perPage = 15): Promise<PaginatedResponse<EngineeringRun>> {
    const res = await api.get<{ data: EngineeringRun[]; meta: PaginatedResponse<EngineeringRun>['meta'] }>(
      '/system/engineering/runs',
      { params: { page, per_page: perPage } },
    );
    return { data: res.data.data, meta: res.data.meta };
  },

  async getRun(id: string): Promise<EngineeringRun> {
    const res = await api.get<{ data: EngineeringRun }>(`/system/engineering/runs/${id}`);
    return res.data.data;
  },

  async getFindings(params: {
    page?: number;
    perPage?: number;
    severity?: string;
    runId?: string;
  } = {}): Promise<PaginatedResponse<EngineeringFinding>> {
    const res = await api.get<{ data: EngineeringFinding[]; meta: PaginatedResponse<EngineeringFinding>['meta'] }>(
      '/system/engineering/findings',
      {
        params: {
          page: params.page ?? 1,
          per_page: params.perPage ?? 25,
          severity: params.severity,
          run_id: params.runId,
        },
      },
    );
    return { data: res.data.data, meta: res.data.meta };
  },
};
