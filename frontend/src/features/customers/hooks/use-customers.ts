import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { customersService } from '@/features/customers/services/customers-service';
import type { CustomerPayload, CustomersQuery } from '@/features/customers/types/customer';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

export const CUSTOMERS_KEY = 'customers';

export function useCustomersQuery(params: CustomersQuery) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, CUSTOMERS_KEY, params],
    queryFn: () => customersService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCustomerQuery(id: string) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, CUSTOMERS_KEY, id],
    queryFn: () => customersService.get(id),
    enabled: Boolean(id),
  });
}

export function useCreateCustomer() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CustomerPayload) => customersService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CUSTOMERS_KEY] }),
  });
}

export function useUpdateCustomer() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CustomerPayload }) =>
      customersService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CUSTOMERS_KEY] }),
  });
}

export function useDeleteCustomer() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => customersService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CUSTOMERS_KEY] }),
  });
}

// ── Blocked Customer (TASK-...-BLOCKED-CUSTOMERS-009) ──────────────────────

export function useBlockCustomer() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => customersService.block(id, reason),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CUSTOMERS_KEY] }),
  });
}

export function useBlockPhone() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ phone, reason }: { phone: string; reason: string }) =>
      customersService.blockPhone(phone, reason),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CUSTOMERS_KEY] }),
  });
}

export function useUnblockCustomer() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, blockId, reason }: { id: string; blockId: string; reason: string }) =>
      customersService.unblock(id, blockId, reason),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, CUSTOMERS_KEY] }),
  });
}

export function useCustomerBlockHistory(id: string, enabled: boolean) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, CUSTOMERS_KEY, id, 'block-history'],
    queryFn: () => customersService.blockHistory(id),
    enabled: enabled && Boolean(id),
  });
}

// ── Sales Owner filter options (TASK-...-FINAL-UI-CLOSURE-014 §15) ─────────
// Distinct owners already referenced by this company's Customers — not an employee
// directory, so this naturally returns [] until a future task adds the assignment
// action. Same {value,label} shape useBrandOptions/useChannelOptions return.

export function useSalesOwnerOptions() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, CUSTOMERS_KEY, 'sales-owner-options'],
    queryFn: async () => {
      const owners = await customersService.salesOwnerOptions();
      return owners.map((o) => ({ value: o.id, label: o.name ?? o.id }));
    },
    staleTime: 60_000,
  });
}
