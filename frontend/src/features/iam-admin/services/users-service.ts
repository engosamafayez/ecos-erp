import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  AssignOrganizationPayload,
  CreateUserPayload,
  LifecycleAction,
  ResetPasswordPayload,
  UpdateUserPayload,
  UserDetail,
  UserSession,
  UsersListResult,
  UsersQuery,
} from '@/features/iam-admin/types/user';

/**
 * IAM Users API client — wraps TASK-ECOS-IAM-SECURE-ADMIN-API-002's /iam/users surface.
 * Unwraps the standardized ApiResponse envelope, exactly like branchesService.
 */
export const usersService = {
  async list(params: UsersQuery): Promise<UsersListResult> {
    const { data } = await api.get<ApiResponse<UsersListResult>>('/iam/users', { params });
    return data.data;
  },

  async get(id: number): Promise<UserDetail> {
    const { data } = await api.get<ApiResponse<UserDetail>>(`/iam/users/${id}`);
    return data.data;
  },

  /** D2: no company_id in the payload — the backend derives it server-side. */
  async create(payload: CreateUserPayload): Promise<UserDetail> {
    const { data } = await api.post<ApiResponse<UserDetail>>('/iam/users', payload);
    return data.data;
  },

  /** D3: same — company_id is not a field this payload type even carries. */
  async update(id: number, payload: UpdateUserPayload): Promise<UserDetail> {
    const { data } = await api.patch<ApiResponse<UserDetail>>(`/iam/users/${id}`, payload);
    return data.data;
  },

  async assignOrganization(id: number, payload: AssignOrganizationPayload): Promise<UserDetail> {
    const { data } = await api.put<ApiResponse<UserDetail>>(`/iam/users/${id}/organization`, payload);
    return data.data;
  },

  async assignTemplate(id: number, templateKey: string, primary = false): Promise<UserDetail> {
    const { data } = await api.put<ApiResponse<UserDetail>>(
      `/iam/users/${id}/templates/${templateKey}`,
      { primary },
    );
    return data.data;
  },

  async revokeTemplate(id: number, templateKey: string): Promise<UserDetail> {
    const { data } = await api.delete<ApiResponse<UserDetail>>(`/iam/users/${id}/templates/${templateKey}`);
    return data.data;
  },

  /** One call per D4 lifecycle action — the endpoint IS the action, backend remains authoritative. */
  async transition(id: number, action: LifecycleAction, reason?: string): Promise<UserDetail> {
    const { data } = await api.post<ApiResponse<UserDetail>>(
      `/iam/users/${id}/${action}`,
      action === 'restore' || action === 'unlock' || action === 'activate' ? undefined : { reason },
    );
    return data.data;
  },

  /** D1: the backend enforces the full status-aware lifecycle gate — this call never assumes it. */
  async resetPassword(id: number, payload: ResetPasswordPayload): Promise<void> {
    await api.post(`/iam/users/${id}/reset-password`, payload);
  },

  async listSessions(id: number): Promise<UserSession[]> {
    const { data } = await api.get<ApiResponse<UserSession[]>>(`/iam/users/${id}/sessions`);
    return data.data;
  },

  async revokeSession(id: number, sessionId: string): Promise<void> {
    await api.delete(`/iam/users/${id}/sessions/${sessionId}`);
  },

  async forceLogout(id: number): Promise<{ revoked: number }> {
    const { data } = await api.post<ApiResponse<{ revoked: number }>>(`/iam/users/${id}/sessions/force-logout`);
    return data.data;
  },
};
