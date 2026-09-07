import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { crmPortfolioService } from '@/features/crm/services/crm-portfolio-service';
import type { CrmPortfolioQuery } from '@/features/crm/types/crm-customer';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

export const CRM_PORTFOLIO_KEY = 'crm-portfolio';

function useCompanyScope(): string {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useCrmPortfolioQuery(params: CrmPortfolioQuery) {
  const companyId = useCompanyScope();

  return useQuery({
    queryKey: ['company', companyId, CRM_PORTFOLIO_KEY, params],
    queryFn: () => crmPortfolioService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useAssignSalesOwner() {
  const companyId = useCompanyScope();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ customerId, salesOwnerId }: { customerId: string; salesOwnerId: string | null }) =>
      crmPortfolioService.assignOwner(customerId, salesOwnerId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['company', companyId, CRM_PORTFOLIO_KEY] });
      void queryClient.invalidateQueries({ queryKey: ['company', companyId, 'crm-customers'] });
    },
  });
}
