import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { iamDirectoriesService, usersService } from '@/features/iam-admin/services/users-service';
import type {
  AssignOrganizationPayload,
  CreateUserPayload,
  InvitePayload,
  LifecycleAction,
  OrganizationScopeAssignmentInput,
  ResetPasswordPayload,
  UpdateUserPayload,
  UsersQuery,
} from '@/features/iam-admin/types/user';

const USERS_KEY = 'iam-users';

export function useUsersQuery(params: UsersQuery) {
  return useQuery({
    queryKey: [USERS_KEY, params],
    queryFn: () => usersService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useUserQuery(id: number | null) {
  return useQuery({
    queryKey: [USERS_KEY, 'detail', id],
    queryFn: () => usersService.get(id as number),
    enabled: id !== null,
  });
}

/** §12: every mutation below invalidates the list + this user's detail through React Query's
 * normal cache-invalidation pattern — no optimistic success, no parallel permission cache.
 * A single broad invalidation is enough: React Query matches queryKey prefixes, so
 * [USERS_KEY] already covers every [USERS_KEY, 'detail', id] — a second, narrower call would
 * be redundant and (for an actively-mounted detail query) trigger a duplicate refetch. */
function useInvalidateUser() {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: [USERS_KEY] });
}

export function useCreateUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateUserPayload) => usersService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [USERS_KEY] }),
  });
}

export function useUpdateUser(id: number) {
  const invalidate = useInvalidateUser();
  return useMutation({
    mutationFn: (payload: UpdateUserPayload) => usersService.update(id, payload),
    onSuccess: invalidate,
  });
}

export function useAssignOrganization(id: number) {
  const invalidate = useInvalidateUser();
  return useMutation({
    mutationFn: (payload: AssignOrganizationPayload) => usersService.assignOrganization(id, payload),
    onSuccess: invalidate,
  });
}

/** §9 — save the user's whole organization scope in one authorized, validated, audited call. */
export function useSyncOrganizationScope(id: number) {
  const invalidate = useInvalidateUser();
  return useMutation({
    mutationFn: (assignments: OrganizationScopeAssignmentInput[]) => usersService.syncOrganizationScope(id, assignments),
    onSuccess: invalidate,
  });
}

export function useAssignTemplate(id: number) {
  const invalidate = useInvalidateUser();
  return useMutation({
    mutationFn: ({ templateKey, primary }: { templateKey: string; primary?: boolean }) =>
      usersService.assignTemplate(id, templateKey, primary),
    onSuccess: invalidate,
  });
}

export function useRevokeTemplate(id: number) {
  const invalidate = useInvalidateUser();
  return useMutation({
    mutationFn: (templateKey: string) => usersService.revokeTemplate(id, templateKey),
    onSuccess: invalidate,
  });
}

/** One hook for every D4 lifecycle action + restore — the UI decides which action is valid to show. */
export function useUserTransition(id: number) {
  const invalidate = useInvalidateUser();
  return useMutation({
    mutationFn: ({ action, reason }: { action: LifecycleAction; reason?: string }) =>
      usersService.transition(id, action, reason),
    onSuccess: invalidate,
  });
}

export function useResetPassword(id: number) {
  return useMutation({
    mutationFn: (payload: ResetPasswordPayload) => usersService.resetPassword(id, payload),
  });
}

export function useUserSessions(id: number | null, enabled: boolean) {
  return useQuery({
    queryKey: [USERS_KEY, 'sessions', id],
    queryFn: () => usersService.listSessions(id as number),
    enabled: enabled && id !== null,
  });
}

export function useRevokeSession(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (sessionId: string) => usersService.revokeSession(id, sessionId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [USERS_KEY, 'sessions', id] }),
  });
}

export function useForceLogout(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => usersService.forceLogout(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [USERS_KEY, 'sessions', id] }),
  });
}

/** CORE-02 Task 1 — this user's invitation history. */
export function useUserInvitations(id: number | null) {
  return useQuery({
    queryKey: [USERS_KEY, 'invitations', id],
    queryFn: () => usersService.invitations(id as number),
    enabled: id !== null,
  });
}

function useInvalidateInvitations(id: number) {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: [USERS_KEY, 'invitations', id] });
}

export function useInviteUser(id: number) {
  const invalidate = useInvalidateInvitations(id);
  return useMutation({
    mutationFn: (payload: InvitePayload = {}) => usersService.invite(id, payload),
    onSuccess: invalidate,
  });
}

export function useResendInvitation(id: number) {
  const invalidate = useInvalidateInvitations(id);
  return useMutation({
    mutationFn: (payload: InvitePayload = {}) => usersService.resendInvitation(id, payload),
    onSuccess: invalidate,
  });
}

export function useRevokeInvitation(id: number) {
  const invalidate = useInvalidateInvitations(id);
  return useMutation({
    mutationFn: (invitationId: string) => usersService.revokeInvitation(id, invitationId),
    onSuccess: invalidate,
  });
}

/** §9 — the canonical organization hierarchy for the scope picker. */
export function useOrganizationDirectoryQuery(search: string) {
  return useQuery({
    queryKey: [USERS_KEY, 'org-directory', search],
    queryFn: () => iamDirectoriesService.organization({ q: search || undefined }),
    staleTime: 60_000,
  });
}

/** §6 — searchable lookup over EXISTING employees for the employee link field. */
export function useEmployeeDirectoryQuery(search: string, onlyUnlinked: boolean) {
  return useQuery({
    queryKey: [USERS_KEY, 'employee-directory', search, onlyUnlinked],
    queryFn: () => iamDirectoriesService.employees({ q: search || undefined, only_unlinked: onlyUnlinked }),
    staleTime: 30_000,
  });
}
