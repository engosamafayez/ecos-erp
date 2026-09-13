import { useQuery } from '@tanstack/react-query';

import { crmMyWorkService } from '@/features/crm/services/crm-my-work-service';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

export function useCrmMyWorkQuery() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';

  return useQuery({
    queryKey: ['company', companyId, 'crm-my-work'],
    queryFn: () => crmMyWorkService.get(),
  });
}
