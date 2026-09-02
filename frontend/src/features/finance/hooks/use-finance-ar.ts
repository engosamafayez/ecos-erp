import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';

import { financeArService } from '../services/finance-ar-service';
import type { ArInvoiceParams, ArReceiptParams } from '../types/finance-ar';

/**
 * React-query hooks for the Accounts Receivable workspace (Phase 4, extended
 * by TASK-ECOS-FINANCE-AP-AR-MUTATION-UX with the allocation/reversal
 * mutations). Company-scoped keys. The customer-ledger query is lazy
 * (enabled only when a drawer opens).
 */
function useCompanyId() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useArAging() {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ar', 'aging'],
    queryFn: () => financeArService.aging(),
  });
}

export function useArInvoices(params: ArInvoiceParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ar', 'invoices', params],
    queryFn: () => financeArService.invoices(params),
  });
}

export function useArReceipts(params: ArReceiptParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ar', 'receipts', params],
    queryFn: () => financeArService.receipts(params),
  });
}

/**
 * A single receipt, looked up by id from the already-fetched (unfiltered)
 * receipts list rather than a fresh GET — the backend exposes no single-
 * receipt `show` endpoint (only index/store/post/allocate/…, see
 * routes/api.php's `finance/ar/receipts` group). Since ReceiptsTab already
 * calls `useArReceipts()` with the same (empty) params, this shares that
 * exact query-cache entry: no extra network request is made just to open
 * the detail drawer.
 */
export function useArReceipt(uuid: string | null) {
  const receipts = useArReceipts();
  return {
    data: uuid ? receipts.data?.find((r) => r.id === uuid) : undefined,
    isLoading: receipts.isLoading,
    isError: receipts.isError,
  };
}

/** Allocate part (or all) of a posted receipt to one posted invoice. */
export function useAllocateReceipt() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ uuid, invoiceId, amount }: { uuid: string; invoiceId: string; amount: number }) =>
      financeArService.allocate(uuid, invoiceId, amount),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'receipts'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'invoices'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'aging'] });
    },
  });
}

/** Auto-allocate a receipt across the customer's open invoices (FIFO). */
export function useAutoAllocateReceipt() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (uuid: string) => financeArService.autoAllocate(uuid),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'receipts'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'invoices'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'aging'] });
    },
  });
}

/**
 * Reverse part (or all) of one posted allocation with a new, append-only
 * contra-allocation. Exposed here to mirror the full backend contract, but
 * no drawer currently calls it — see receipt-detail-drawer.tsx's header
 * comment for why (no endpoint lists a receipt's existing allocations to
 * pick one to reverse).
 */
export function useReverseReceiptAllocation() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      uuid,
      allocationUuid,
      amount,
      reason,
    }: {
      uuid: string;
      allocationUuid: string;
      amount: number;
      reason: string;
    }) => financeArService.reverseAllocation(uuid, allocationUuid, amount, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'receipts'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'invoices'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'aging'] });
    },
  });
}

/** Reverse a posted receipt's journal AND its customer-ledger entry together. */
export function useReverseReceiptPosting() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ uuid, reason }: { uuid: string; reason: string }) =>
      financeArService.reversePosting(uuid, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'receipts'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ar', 'aging'] });
    },
  });
}

export function useCustomerLedger(customerId: string | null) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ar', 'ledger', customerId],
    queryFn: () => financeArService.customerLedger(customerId as string),
    enabled: Boolean(customerId),
  });
}

export function useArControlReconciliation() {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ar', 'reconciliation'],
    queryFn: () => financeArService.controlReconciliation(),
  });
}
