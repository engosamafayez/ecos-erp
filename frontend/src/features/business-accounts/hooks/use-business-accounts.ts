import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { businessAccountsService } from '@/features/business-accounts/services/business-accounts-service';
import type { BusinessAccountPayload, BusinessAccountsQuery } from '@/features/business-accounts/types/business-account';

const BUSINESS_ACCOUNTS_KEY = 'business-accounts';

export function useBusinessAccountsQuery(params: BusinessAccountsQuery, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: [BUSINESS_ACCOUNTS_KEY, params],
    queryFn: () => businessAccountsService.list(params),
    placeholderData: keepPreviousData,
    enabled: options?.enabled ?? true,
  });
}

export function useBusinessAccountQuery(id: string) {
  return useQuery({
    queryKey: [BUSINESS_ACCOUNTS_KEY, id],
    queryFn: () => businessAccountsService.get(id),
    enabled: !!id,
  });
}

export function useCreateBusinessAccount() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: BusinessAccountPayload) => businessAccountsService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [BUSINESS_ACCOUNTS_KEY] }),
  });
}

export function useUpdateBusinessAccount() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Omit<BusinessAccountPayload, 'company_id'> }) =>
      businessAccountsService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [BUSINESS_ACCOUNTS_KEY] }),
  });
}

export function useDeleteBusinessAccount() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => businessAccountsService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [BUSINESS_ACCOUNTS_KEY] }),
  });
}
