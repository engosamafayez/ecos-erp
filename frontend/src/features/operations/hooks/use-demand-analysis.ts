import { useQuery } from '@tanstack/react-query';

import { demandAnalysisService } from '@/features/operations/services/demand-analysis-service';

const DEMAND_ANALYSIS_KEY = 'operations-demand-analysis';

export function useDemandAnalysis(date?: string) {
  return useQuery({
    queryKey: [DEMAND_ANALYSIS_KEY, date],
    queryFn: () => demandAnalysisService.getAnalysis(date),
  });
}
