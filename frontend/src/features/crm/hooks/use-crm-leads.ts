import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { crmLeadsService } from '@/features/crm/services/crm-leads-service';
import type {
  CrmLeadConvertValues,
  CrmLeadCreateValues,
  CrmLeadsQuery,
  CrmLeadStatus,
} from '@/features/crm/types/crm-lead';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

export const CRM_LEADS_KEY = 'crm-leads';

function useCompanyScope(): string {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useCrmLeadsQuery(params: CrmLeadsQuery) {
  const companyId = useCompanyScope();

  return useQuery({
    queryKey: ['company', companyId, CRM_LEADS_KEY, params],
    queryFn: () => crmLeadsService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCrmLeadQuery(id: string | null) {
  const companyId = useCompanyScope();

  return useQuery({
    queryKey: ['company', companyId, CRM_LEADS_KEY, id],
    queryFn: () => crmLeadsService.get(id as string),
    enabled: Boolean(id),
  });
}

export function useCreateCrmLead() {
  const companyId = useCompanyScope();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (values: CrmLeadCreateValues) => crmLeadsService.create(values),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['company', companyId, CRM_LEADS_KEY] });
    },
  });
}

function useInvalidateLead(companyId: string, leadId: string) {
  const queryClient = useQueryClient();

  return () => {
    void queryClient.invalidateQueries({ queryKey: ['company', companyId, CRM_LEADS_KEY] });
    void queryClient.invalidateQueries({ queryKey: ['company', companyId, CRM_LEADS_KEY, leadId] });
  };
}

export function useSetCrmLeadStatus(leadId: string) {
  const companyId = useCompanyScope();
  const invalidate = useInvalidateLead(companyId, leadId);

  return useMutation({
    mutationFn: (status: Exclude<CrmLeadStatus, 'converted'>) =>
      crmLeadsService.setStatus(leadId, status),
    onSuccess: invalidate,
  });
}

export function useConvertCrmLead(leadId: string) {
  const companyId = useCompanyScope();
  const invalidate = useInvalidateLead(companyId, leadId);

  return useMutation({
    mutationFn: (values: CrmLeadConvertValues) => crmLeadsService.convert(leadId, values),
    onSuccess: invalidate,
  });
}

export function useCrmLeadActivitiesQuery(leadId: string | null, enabled: boolean) {
  const companyId = useCompanyScope();

  return useQuery({
    queryKey: ['company', companyId, CRM_LEADS_KEY, leadId, 'activities'],
    queryFn: () => crmLeadsService.activities(leadId as string),
    enabled: Boolean(leadId) && enabled,
  });
}

export function useCreateCrmLeadActivity(leadId: string) {
  const companyId = useCompanyScope();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { activity_type: string; title: string; due_at?: string | null }) =>
      crmLeadsService.createActivity(leadId, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['company', companyId, CRM_LEADS_KEY, leadId, 'activities'],
      });
    },
  });
}

function useActivityMutation(leadId: string, fn: (id: string) => Promise<unknown>) {
  const companyId = useCompanyScope();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['company', companyId, CRM_LEADS_KEY, leadId, 'activities'],
      });
    },
  });
}

export function useCompleteCrmLeadActivity(leadId: string) {
  return useActivityMutation(leadId, (id) => crmLeadsService.completeActivity(id));
}

export function useCancelCrmLeadActivity(leadId: string) {
  return useActivityMutation(leadId, (id) => crmLeadsService.cancelActivity(id));
}
