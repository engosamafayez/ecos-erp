import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  AssignOrganizationPayload,
  CreateUserPayload,
  EmployeeDirectoryResult,
  Invitation,
  InvitationIssueResult,
  InvitePayload,
  LifecycleAction,
  OrganizationDirectoryResult,
  OrganizationScopeAssignmentInput,
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

  /**
   * §9 — save the whole organization scope in one call. `assignments` is the COMPLETE
   * desired set; anything not listed is withdrawn.
   */
  async syncOrganizationScope(id: number, assignments: OrganizationScopeAssignmentInput[]): Promise<UserDetail> {
    const { data } = await api.put<ApiResponse<UserDetail>>(`/iam/users/${id}/organization-scope`, { assignments });
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

  /** CORE-02 Task 1 — this user's invitation history, most recent first. Never a token. */
  async invitations(id: number): Promise<Invitation[]> {
    const { data } = await api.get<ApiResponse<Invitation[]>>(`/iam/users/${id}/invitations`);
    return data.data;
  },

  /**
   * Issues a fresh invitation. The raw `invitation_token` is returned exactly once, in this
   * response only — hand it to the invitee (e.g. as `${origin}/accept-invitation?token=...`)
   * and never store it; it cannot be retrieved again afterward.
   */
  async invite(id: number, payload: InvitePayload = {}): Promise<InvitationIssueResult> {
    const { data } = await api.post<ApiResponse<InvitationIssueResult>>(`/iam/users/${id}/invitations`, payload);
    return data.data;
  },

  async resendInvitation(id: number, payload: InvitePayload = {}): Promise<InvitationIssueResult> {
    const { data } = await api.post<ApiResponse<InvitationIssueResult>>(`/iam/users/${id}/invitations/resend`, payload);
    return data.data;
  },

  async revokeInvitation(id: number, invitationId: string): Promise<void> {
    await api.post(`/iam/users/${id}/invitations/${invitationId}/revoke`);
  },
};

/**
 * §6/§9 — the two read-only directories the Create/Edit User workflow consumes: existing
 * EMPLOYEES (so the employee link is a selection, never a typed number) and the canonical
 * ORGANIZATION hierarchy (so scope is entity selection, never a raw type/id triple). Both
 * read straight from EmployeeDirectory / OrganizationScopeDirectory on the backend and write
 * nothing — see UserController::employeeDirectory()/organizationDirectory().
 */
export const iamDirectoriesService = {
  async employees(params: { q?: string; only_unlinked?: boolean; limit?: number } = {}): Promise<EmployeeDirectoryResult> {
    const { data } = await api.get<ApiResponse<EmployeeDirectoryResult>>('/iam/users/directory/employees', { params });
    return data.data;
  },

  async organization(params: { q?: string; limit?: number } = {}): Promise<OrganizationDirectoryResult> {
    const { data } = await api.get<ApiResponse<OrganizationDirectoryResult>>('/iam/users/directory/organization', { params });
    return data.data;
  },
};
