import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  CloneRoleTemplatePayload,
  CreateRoleTemplatePayload,
  RoleTemplateComparison,
  RoleTemplateDetail,
  RoleTemplateSummary,
  TemplateApplyResult,
  TemplateImpactPreview,
  TemplateVersionEntry,
  UpdateRoleTemplatePayload,
} from '@/features/iam-admin/types/role-template';

/**
 * IAM Role Templates API client — wraps /iam/role-templates. Every mutating call maps 1:1
 * to a RoleTemplateController endpoint from TASK-ECOS-IAM-SECURE-ADMIN-API-002.
 */
export const roleTemplatesService = {
  async list(): Promise<RoleTemplateSummary[]> {
    const { data } = await api.get<ApiResponse<RoleTemplateSummary[]>>('/iam/role-templates');
    return data.data;
  },

  async get(key: string): Promise<RoleTemplateDetail> {
    const { data } = await api.get<ApiResponse<RoleTemplateDetail>>(`/iam/role-templates/${key}`);
    return data.data;
  },

  async versions(key: string): Promise<TemplateVersionEntry[]> {
    const { data } = await api.get<ApiResponse<TemplateVersionEntry[]>>(`/iam/role-templates/${key}/versions`);
    return data.data;
  },

  async compare(key: string, otherKey: string): Promise<RoleTemplateComparison> {
    const { data } = await api.get<ApiResponse<RoleTemplateComparison>>(
      `/iam/role-templates/${key}/compare/${otherKey}`,
    );
    return data.data;
  },

  /** D2/D11: no company_id field — server-derives ownership, exactly like Users. */
  async create(payload: CreateRoleTemplatePayload): Promise<RoleTemplateDetail> {
    const { data } = await api.post<ApiResponse<RoleTemplateDetail>>('/iam/role-templates', payload);
    return data.data;
  },

  async cloneTemplate(key: string, payload: CloneRoleTemplatePayload): Promise<RoleTemplateDetail> {
    const { data } = await api.post<ApiResponse<RoleTemplateDetail>>(
      `/iam/role-templates/${key}/clone`,
      payload,
    );
    return data.data;
  },

  async update(key: string, payload: UpdateRoleTemplatePayload): Promise<RoleTemplateDetail> {
    const { data } = await api.patch<ApiResponse<RoleTemplateDetail>>(`/iam/role-templates/${key}`, payload);
    return data.data;
  },

  /** D13 preferred lifecycle path — safe for a used template, unlike destroy(). */
  async archive(key: string): Promise<RoleTemplateDetail> {
    const { data } = await api.post<ApiResponse<RoleTemplateDetail>>(`/iam/role-templates/${key}/archive`);
    return data.data;
  },

  /**
   * D13 hard security contract: the backend itself refuses this when the template is in use
   * (409 RoleTemplateInUseException). The UI must only ever offer this button when the
   * caller already knows assignment_count is 0 — see the workspace's delete-gating logic.
   */
  async destroy(key: string): Promise<void> {
    await api.delete(`/iam/role-templates/${key}`);
  },

  async exportTemplate(key: string): Promise<Record<string, unknown>> {
    const { data } = await api.get<ApiResponse<Record<string, unknown>>>(`/iam/role-templates/${key}/export`);
    return data.data;
  },

  async impactPreview(key: string): Promise<TemplateImpactPreview> {
    const { data } = await api.get<ApiResponse<TemplateImpactPreview>>(
      `/iam/role-templates/${key}/impact-preview`,
    );
    return data.data;
  },

  /**
   * D12: applies the template's current definition to its compiled role — TEMPLATE-WIDE by
   * architecture (every current holder shares one compiled Role). Never call this without the
   * explicit confirmation UX described in the workspace's Apply Version dialog.
   */
  async apply(key: string): Promise<TemplateApplyResult> {
    const { data } = await api.post<ApiResponse<TemplateApplyResult>>(`/iam/role-templates/${key}/apply`);
    return data.data;
  },
};
