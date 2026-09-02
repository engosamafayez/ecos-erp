import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { roleTemplatesService } from '@/features/iam-admin/services/role-templates-service';
import type {
  CloneRoleTemplatePayload,
  CreateRoleTemplatePayload,
  UpdateRoleTemplatePayload,
} from '@/features/iam-admin/types/role-template';

const TEMPLATES_KEY = 'iam-role-templates';

export function useRoleTemplatesQuery() {
  return useQuery({ queryKey: [TEMPLATES_KEY], queryFn: () => roleTemplatesService.list() });
}

export function useRoleTemplateQuery(key: string | null) {
  return useQuery({
    queryKey: [TEMPLATES_KEY, 'detail', key],
    queryFn: () => roleTemplatesService.get(key as string),
    enabled: key !== null,
  });
}

export function useTemplateVersionsQuery(key: string | null) {
  return useQuery({
    queryKey: [TEMPLATES_KEY, 'versions', key],
    queryFn: () => roleTemplatesService.versions(key as string),
    enabled: key !== null,
  });
}

export function useTemplateComparisonQuery(key: string | null, otherKey: string | null) {
  return useQuery({
    queryKey: [TEMPLATES_KEY, 'compare', key, otherKey],
    queryFn: () => roleTemplatesService.compare(key as string, otherKey as string),
    enabled: key !== null && otherKey !== null && key !== otherKey,
  });
}

/**
 * §16: read-only, no caching beyond React Query's own — re-fetched every time the Apply
 * Version dialog opens so the preview always reflects the current definition vs. compiled state.
 */
export function useTemplateImpactPreview(key: string | null, enabled: boolean) {
  return useQuery({
    queryKey: [TEMPLATES_KEY, 'impact-preview', key],
    queryFn: () => roleTemplatesService.impactPreview(key as string),
    enabled: enabled && key !== null,
    staleTime: 0,
  });
}

function useInvalidateTemplates() {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: [TEMPLATES_KEY] });
}

export function useCreateRoleTemplate() {
  const invalidate = useInvalidateTemplates();
  return useMutation({
    mutationFn: (payload: CreateRoleTemplatePayload) => roleTemplatesService.create(payload),
    onSuccess: invalidate,
  });
}

export function useCloneRoleTemplate(key: string) {
  const invalidate = useInvalidateTemplates();
  return useMutation({
    mutationFn: (payload: CloneRoleTemplatePayload) => roleTemplatesService.cloneTemplate(key, payload),
    onSuccess: invalidate,
  });
}

export function useUpdateRoleTemplate(key: string) {
  const invalidate = useInvalidateTemplates();
  return useMutation({
    mutationFn: (payload: UpdateRoleTemplatePayload) => roleTemplatesService.update(key, payload),
    onSuccess: invalidate,
  });
}

export function useArchiveRoleTemplate(key: string) {
  const invalidate = useInvalidateTemplates();
  return useMutation({
    mutationFn: () => roleTemplatesService.archive(key),
    onSuccess: invalidate,
  });
}

export function useDeleteRoleTemplate(key: string) {
  const invalidate = useInvalidateTemplates();
  return useMutation({
    mutationFn: () => roleTemplatesService.destroy(key),
    onSuccess: invalidate,
  });
}

/**
 * D12/§12: the one mutation that can change EVERY current holder's effective access at once.
 * On success, invalidates the template list/detail — and, per §12's "refresh the relevant
 * authorization/user data through current frontend patterns", also the Users list, since
 * affected users' effective template/permission state is now stale in any open Users view.
 */
export function useApplyRoleTemplate(key: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => roleTemplatesService.apply(key),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [TEMPLATES_KEY] });
      queryClient.invalidateQueries({ queryKey: ['iam-users'] });
    },
  });
}
