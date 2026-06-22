import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { companiesService } from '@/features/companies/services/companies-service';
import type { CompaniesQuery, CompanyPayload } from '@/features/companies/types/company';

const COMPANIES_KEY = 'companies';

/** Paginated, filtered, sorted companies list. */
export function useCompaniesQuery(params: CompaniesQuery) {
  return useQuery({
    queryKey: [COMPANIES_KEY, params],
    queryFn: () => companiesService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCreateCompany() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CompanyPayload) => companiesService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [COMPANIES_KEY] }),
  });
}

export function useUpdateCompany() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CompanyPayload }) =>
      companiesService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [COMPANIES_KEY] }),
  });
}

export function useDeleteCompany() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => companiesService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [COMPANIES_KEY] }),
  });
}
