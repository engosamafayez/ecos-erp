import { useQuery } from '@tanstack/react-query';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { engineeringService } from '../services/engineering-service';

export function useEngineeringDashboard() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, 'engineering', 'dashboard'],
    queryFn: () => engineeringService.getDashboard(),
    staleTime: 30_000,
    refetchInterval: 30_000,
  });
}

export function useEngineeringRuns(page = 1, perPage = 15) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, 'engineering', 'runs', page, perPage],
    queryFn: () => engineeringService.getRuns(page, perPage),
    staleTime: 30_000,
    refetchInterval: 30_000,
    placeholderData: (prev) => prev,
  });
}

export function useEngineeringRun(id: string | null) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, 'engineering', 'run', id],
    queryFn: () => engineeringService.getRun(id!),
    enabled: !!id,
    staleTime: 60_000,
  });
}

export function useEngineeringFindings(params: {
  page?: number;
  perPage?: number;
  severity?: string;
  runId?: string;
} = {}) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, 'engineering', 'findings', params],
    queryFn: () => engineeringService.getFindings(params),
    staleTime: 30_000,
    refetchInterval: 30_000,
    placeholderData: (prev) => prev,
  });
}
