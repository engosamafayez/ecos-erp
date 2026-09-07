import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { suppliersService } from '@/features/suppliers/services/suppliers-service';
import type { OpeningBalancePayload } from '@/features/suppliers/services/suppliers-service';
import type { SuppliersQuery, SupplierPayload } from '@/features/suppliers/types/supplier';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

const SUPPLIERS_KEY = 'suppliers';
const FINANCIAL_SUMMARY_KEY = 'supplier-financial-summary';

export function useSupplierQuery(id: string) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, SUPPLIERS_KEY, id],
    queryFn: () => suppliersService.get(id),
    enabled: Boolean(id),
  });
}

/** Paginated, filtered, sorted suppliers list. */
export function useSuppliersQuery(params: SuppliersQuery) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, SUPPLIERS_KEY, params],
    queryFn: () => suppliersService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCreateSupplier() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: SupplierPayload) => suppliersService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, SUPPLIERS_KEY] }),
  });
}

export function useUpdateSupplier() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: SupplierPayload }) =>
      suppliersService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, SUPPLIERS_KEY] }),
  });
}

export function useDeleteSupplier() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => suppliersService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, SUPPLIERS_KEY] }),
  });
}

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-FINAL-USER-REVIEW-REMEDIATION-002 §6.
 *
 * The ledger-derived AP position (Outstanding Payable / Available Advance / Net Balance) —
 * DISTINCT from the purchase-history-derived KPIs `useSupplierAnalytics` returns. Backed by
 * `GET /suppliers/{id}/financial-summary` → `SupplierLedgerService`, the same canonical AP
 * authority Supplier Invoice posting and settlement use; not a second balance source.
 */
export function useSupplierFinancialSummary(supplierId: string) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, FINANCIAL_SUMMARY_KEY, supplierId],
    queryFn: () => suppliersService.financialSummary(supplierId),
    enabled: Boolean(supplierId),
  });
}

/**
 * Posts a Supplier opening balance (payable or advance) through the canonical
 * SupplierOpeningBalanceService — idempotent server-side (re-posting the same supplier's
 * opening balance is a no-op), so this mutation is safe to retry.
 */
export function usePostSupplierOpeningBalance(supplierId: string) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: OpeningBalancePayload) => suppliersService.postOpeningBalance(supplierId, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['company', companyId, FINANCIAL_SUMMARY_KEY, supplierId] });
      void queryClient.invalidateQueries({ queryKey: ['company', companyId, SUPPLIERS_KEY] });
    },
  });
}
