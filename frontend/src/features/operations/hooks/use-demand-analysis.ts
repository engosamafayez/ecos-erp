import { useQuery } from '@tanstack/react-query';

import { demandAnalysisService } from '@/features/operations/services/demand-analysis-service';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

const DEMAND_ANALYSIS_KEY = 'operations-demand-analysis';

export function useDemandAnalysis(date?: string) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, DEMAND_ANALYSIS_KEY, date],
    queryFn: () => demandAnalysisService.getAnalysis(date),
  });
}
