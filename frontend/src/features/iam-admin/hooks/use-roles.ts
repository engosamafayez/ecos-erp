import { useQuery } from '@tanstack/react-query';

import { permissionsService, rolesService } from '@/features/iam-admin/services/roles-service';

const ROLES_KEY = 'iam-roles';
const PERMISSIONS_KEY = 'iam-permissions';

/** Read-only, matching RoleController's own architecture (§10/§14 of the report). */
export function useRolesQuery() {
  return useQuery({ queryKey: [ROLES_KEY], queryFn: () => rolesService.list() });
}

export function useRoleQuery(id: string | null) {
  return useQuery({
    queryKey: [ROLES_KEY, 'detail', id],
    queryFn: () => rolesService.get(id as string),
    enabled: id !== null,
  });
}

export function usePermissionCatalogQuery() {
  return useQuery({ queryKey: [PERMISSIONS_KEY], queryFn: () => permissionsService.catalog() });
}
