import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { crmPipelineService } from '@/features/crm/services/crm-pipeline-service';
import type {
  CrmOpportunitiesQuery,
  CrmOpportunityCreateValues,
} from '@/features/crm/types/crm-pipeline';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

export const CRM_PIPELINE_KEY = 'crm-pipeline';
export const CRM_OPPORTUNITIES_KEY = 'crm-opportunities';

function useCompanyScope(): string {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useCrmPipelinesQuery() {
  const companyId = useCompanyScope();

  return useQuery({
    queryKey: ['company', companyId, CRM_PIPELINE_KEY],
    queryFn: () => crmPipelineService.pipelines(),
    staleTime: 60 * 1000,
  });
}

export function useCrmOpportunitiesQuery(params: CrmOpportunitiesQuery, enabled = true) {
  const companyId = useCompanyScope();

  return useQuery({
    queryKey: ['company', companyId, CRM_OPPORTUNITIES_KEY, params],
    queryFn: () => crmPipelineService.opportunities(params),
    enabled,
  });
}

function useInvalidateOpportunities() {
  const companyId = useCompanyScope();
  const queryClient = useQueryClient();

  return () => {
    void queryClient.invalidateQueries({ queryKey: ['company', companyId, CRM_OPPORTUNITIES_KEY] });
  };
}

export function useCreateCrmOpportunity() {
  const invalidate = useInvalidateOpportunities();

  return useMutation({
    mutationFn: (values: CrmOpportunityCreateValues) => crmPipelineService.create(values),
    onSuccess: invalidate,
  });
}

export function useMoveCrmOpportunityStage() {
  const invalidate = useInvalidateOpportunities();

  return useMutation({
    mutationFn: ({ id, stageId }: { id: string; stageId: string }) =>
      crmPipelineService.moveStage(id, stageId),
    onSuccess: invalidate,
  });
}

export function useWinCrmOpportunity() {
  const invalidate = useInvalidateOpportunities();

  return useMutation({
    mutationFn: ({ id, orderReference }: { id: string; orderReference?: string | null }) =>
      crmPipelineService.win(id, orderReference),
    onSuccess: invalidate,
  });
}

export function useLoseCrmOpportunity() {
  const invalidate = useInvalidateOpportunities();

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => crmPipelineService.lose(id, reason),
    onSuccess: invalidate,
  });
}

export function useReopenCrmOpportunity() {
  const invalidate = useInvalidateOpportunities();

  return useMutation({
    mutationFn: (id: string) => crmPipelineService.reopen(id),
    onSuccess: invalidate,
  });
}
