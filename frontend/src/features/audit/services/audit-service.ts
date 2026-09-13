import { api } from '@/lib/axios';
import type { AuditLogListData, AuditLogListParams } from '@/features/audit/types/audit-log';

export const auditService = {
  async list(params: AuditLogListParams): Promise<AuditLogListData> {
    const response = await api.get<{ data: AuditLogListData }>('/audit', { params });
    return response.data.data;
  },
};
