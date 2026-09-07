import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  CreateRolePayload,
  PermissionCatalog,
  RoleDetail,
  RoleListResult,
  RoleSummary,
  UpdateRolePayload,
} from '@/features/iam-admin/types/role';

/**
 * IAM Roles & Permissions API client.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11/§14: roles are now writable. Every
 * write below hits a route that delegates to RoleAuthoringService, which authors the
 * backing Role Template and calls the ONE canonical RoleTemplateCompiler — grants are
 * never written directly, matching the architecture decision RoleController.php's
 * docblock records. `list()`/`get()` return the full `ApiResponse` envelope so the meta
 * (business catalogue keys, categories) reaches the workspace, unlike the previous
 * `.data.data`-only unwrap.
 */
export const rolesService = {
  async list(includeArchived = false): Promise<RoleListResult> {
    const { data } = await api.get<ApiResponse<RoleListResult>>('/iam/roles', {
      params: includeArchived ? { include_archived: 1 } : undefined,
    });
    return data.data;
  },

  async get(id: string): Promise<RoleDetail> {
    const { data } = await api.get<ApiResponse<RoleDetail>>(`/iam/roles/${id}`);
    return data.data;
  },

  async create(payload: CreateRolePayload): Promise<RoleSummary> {
    const { data } = await api.post<ApiResponse<RoleSummary>>('/iam/roles', payload);
    return data.data;
  },

  async update(id: string, payload: UpdateRolePayload): Promise<RoleSummary> {
    const { data } = await api.patch<ApiResponse<RoleSummary>>(`/iam/roles/${id}`, payload);
    return data.data;
  },

  /** §14 — save the whole editable permission matrix; `permissions` is the complete set. */
  async updatePermissions(id: string, permissions: string[]): Promise<RoleDetail> {
    const { data } = await api.put<ApiResponse<RoleDetail>>(`/iam/roles/${id}/permissions`, { permissions });
    return data.data;
  },

  async clone(id: string, name: string): Promise<RoleSummary> {
    const { data } = await api.post<ApiResponse<RoleSummary>>(`/iam/roles/${id}/clone`, { name });
    return data.data;
  },

  async archive(id: string, reason?: string): Promise<RoleSummary> {
    const { data } = await api.post<ApiResponse<RoleSummary>>(`/iam/roles/${id}/archive`, { reason });
    return data.data;
  },

  async restore(id: string): Promise<RoleSummary> {
    const { data } = await api.post<ApiResponse<RoleSummary>>(`/iam/roles/${id}/restore`);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/iam/roles/${id}`);
  },
};

export const permissionsService = {
  async catalog(): Promise<PermissionCatalog> {
    const { data } = await api.get<ApiResponse<PermissionCatalog>>('/iam/permissions');
    return data.data;
  },
};
