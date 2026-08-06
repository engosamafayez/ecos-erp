import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { crmCustomersService } from '@/features/crm/services/crm-customers-service';
import type { CrmCustomersQuery } from '@/features/crm/types/crm-customer';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

export const CRM_CUSTOMERS_KEY = 'crm-customers';

/**
 * Every key is scoped by company, matching the rest of the platform: switching
 * company must not surface another tenant's cached rows.
 */
function useCompanyScope(): string {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useCrmCustomersQuery(params: CrmCustomersQuery) {
  const companyId = useCompanyScope();

  return useQuery({
    queryKey: ['company', companyId, CRM_CUSTOMERS_KEY, params],
    queryFn: () => crmCustomersService.list(params),
    // Keeps the previous page on screen while the next loads, so paging and
    // filtering do not blank the grid.
    placeholderData: keepPreviousData,
  });
}

export function useCrmCustomerQuery(id: string) {
  const companyId = useCompanyScope();

  return useQuery({
    queryKey: ['company', companyId, CRM_CUSTOMERS_KEY, id],
    queryFn: () => crmCustomersService.get(id),
    enabled: Boolean(id),
  });
}

export function useCrmCustomerGroupsQuery() {
  const companyId = useCompanyScope();

  return useQuery({
    queryKey: ['company', companyId, CRM_CUSTOMERS_KEY, 'groups'],
    queryFn: () => crmCustomersService.groups(),
    staleTime: 5 * 60 * 1000,
  });
}

export function useArchiveCrmCustomer() {
  const companyId = useCompanyScope();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => crmCustomersService.archive(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['company', companyId, CRM_CUSTOMERS_KEY],
      });
    },
  });
}
