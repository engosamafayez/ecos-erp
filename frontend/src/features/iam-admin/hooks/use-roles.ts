import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { permissionsService, rolesService } from '@/features/iam-admin/services/roles-service';
import type { CreateRolePayload, UpdateRolePayload } from '@/features/iam-admin/types/role';

const ROLES_KEY = 'iam-roles';
const PERMISSIONS_KEY = 'iam-permissions';

/**
 * IAM Roles & Permissions queries and mutations.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11/§14: Roles are now writable, served
 * by RoleAuthoringService → the one canonical RoleTemplateCompiler (see roles-service.ts).
 * Every mutation invalidates the roles list AND that role's own detail query, since a
 * grant change, a rename, an archive/restore or a clone all change what `useRoleQuery`
 * would return next.
 */
export function useRolesQuery(includeArchived = false) {
  return useQuery({
    queryKey: [ROLES_KEY, { includeArchived }],
    queryFn: () => rolesService.list(includeArchived),
  });
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

function useInvalidateRoles() {
  const queryClient = useQueryClient();
  return (id?: string) => {
    queryClient.invalidateQueries({ queryKey: [ROLES_KEY] });
    if (id) queryClient.invalidateQueries({ queryKey: [ROLES_KEY, 'detail', id] });
  };
}

export function useCreateRoleMutation() {
  const invalidate = useInvalidateRoles();
  return useMutation({
    mutationFn: (payload: CreateRolePayload) => rolesService.create(payload),
    onSuccess: () => invalidate(),
  });
}

export function useUpdateRoleMutation(id: string) {
  const invalidate = useInvalidateRoles();
  return useMutation({
    mutationFn: (payload: UpdateRolePayload) => rolesService.update(id, payload),
    onSuccess: () => invalidate(id),
  });
}

/** §14 — save the editable permission matrix. `permissions` is the COMPLETE desired set. */
export function useUpdateRolePermissionsMutation(id: string) {
  const invalidate = useInvalidateRoles();
  return useMutation({
    mutationFn: (permissions: string[]) => rolesService.updatePermissions(id, permissions),
    onSuccess: () => invalidate(id),
  });
}

export function useCloneRoleMutation(id: string) {
  const invalidate = useInvalidateRoles();
  return useMutation({
    mutationFn: (name: string) => rolesService.clone(id, name),
    onSuccess: () => invalidate(),
  });
}

export function useArchiveRoleMutation(id: string) {
  const invalidate = useInvalidateRoles();
  return useMutation({
    mutationFn: (reason?: string) => rolesService.archive(id, reason),
    onSuccess: () => invalidate(id),
  });
}

export function useRestoreRoleMutation(id: string) {
  const invalidate = useInvalidateRoles();
  return useMutation({
    mutationFn: () => rolesService.restore(id),
    onSuccess: () => invalidate(id),
  });
}

export function useDeleteRoleMutation() {
  const invalidate = useInvalidateRoles();
  return useMutation({
    mutationFn: (id: string) => rolesService.remove(id),
    onSuccess: () => invalidate(),
  });
}
