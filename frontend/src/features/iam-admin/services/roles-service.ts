import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type { PermissionCatalog, RoleDetail, RoleSummary } from '@/features/iam-admin/types/role';

/**
 * IAM Roles & Permissions API client — read-only, wraps /iam/roles and /iam/permissions.
 * There is no create/update/delete here by design: see RoleController.php's docblock —
 * role mutation happens exclusively through the Role Template API (rolesTemplatesService).
 */
export const rolesService = {
  async list(): Promise<RoleSummary[]> {
    const { data } = await api.get<ApiResponse<RoleSummary[]>>('/iam/roles');
    return data.data;
  },

  async get(id: string): Promise<RoleDetail> {
    const { data } = await api.get<ApiResponse<RoleDetail>>(`/iam/roles/${id}`);
    return data.data;
  },
};

export const permissionsService = {
  async catalog(): Promise<PermissionCatalog> {
    const { data } = await api.get<ApiResponse<PermissionCatalog>>('/iam/permissions');
    return data.data;
  },
};
