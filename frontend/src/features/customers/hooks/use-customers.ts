import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { customersService } from '@/features/customers/services/customers-service';
import type { CustomerPayload, CustomersQuery } from '@/features/customers/types/customer';

export const CUSTOMERS_KEY = 'customers';

export function useCustomersQuery(params: CustomersQuery) {
  return useQuery({
    queryKey: [CUSTOMERS_KEY, params],
    queryFn: () => customersService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCustomerQuery(id: string) {
  return useQuery({
    queryKey: [CUSTOMERS_KEY, id],
    queryFn: () => customersService.get(id),
    enabled: Boolean(id),
  });
}

export function useCreateCustomer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CustomerPayload) => customersService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CUSTOMERS_KEY] }),
  });
}

export function useUpdateCustomer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CustomerPayload }) =>
      customersService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CUSTOMERS_KEY] }),
  });
}

export function useDeleteCustomer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => customersService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CUSTOMERS_KEY] }),
  });
}
