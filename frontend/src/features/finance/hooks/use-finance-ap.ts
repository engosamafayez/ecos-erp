import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { suppliersService } from '@/features/suppliers/services/suppliers-service';

import { financeApService } from '../services/finance-ap-service';
import type { ApBillParams, ApPaymentParams } from '../types/finance-ap';

/**
 * React-query hooks for the Accounts Payable workspace (Phase 5, extended by
 * TASK-ECOS-FINANCE-AP-AR-MUTATION-UX with the allocation/reversal mutations).
 * Company-scoped keys. The supplier-ledger query is lazy (enabled only when a
 * drawer opens).
 */
function useCompanyId() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useApAging() {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ap', 'aging'],
    queryFn: () => financeApService.aging(),
  });
}

export function useApBills(params: ApBillParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ap', 'bills', params],
    queryFn: () => financeApService.bills(params),
  });
}

/**
 * A single bill, looked up by id from the already-fetched (unfiltered) bills list
 * rather than a fresh GET — mirrors useApPayment() exactly: the backend exposes no
 * single-bill `show` endpoint on this route group (only index/store/post/apply-
 * advance), and BillsTab already calls useApBills() with the same (empty) params,
 * so this shares that exact query-cache entry.
 */
export function useApBill(uuid: string | null) {
  const bills = useApBills();
  return {
    data: uuid ? bills.data?.find((b) => b.id === uuid) : undefined,
    isLoading: bills.isLoading,
    isError: bills.isError,
  };
}

export function useApPayments(params: ApPaymentParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ap', 'payments', params],
    queryFn: () => financeApService.payments(params),
  });
}

/**
 * A single payment, looked up by id from the already-fetched (unfiltered)
 * payments list rather than a fresh GET — the backend exposes no single-
 * payment `show` endpoint (only index/store/approve/post/allocate/…, see
 * routes/api.php's `finance/ap/payments` group). Since PaymentsTab already
 * calls `useApPayments()` with the same (empty) params, this shares that
 * exact query-cache entry: no extra network request is made just to open
 * the detail drawer.
 */
export function useApPayment(uuid: string | null) {
  const payments = useApPayments();
  return {
    data: uuid ? payments.data?.find((p) => p.id === uuid) : undefined,
    isLoading: payments.isLoading,
    isError: payments.isError,
  };
}

/** Allocate part (or all) of a posted payment to one posted bill. */
export function useAllocatePayment() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ uuid, billId, amount }: { uuid: string; billId: string; amount: number }) =>
      financeApService.allocate(uuid, billId, amount),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'payments'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'bills'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'aging'] });
    },
  });
}

/**
 * Available advance for one supplier — reads the existing, already-certified
 * Purchasing endpoint (`suppliers/{id}/financial-summary`, TASK-PROC-SUPPLIER-
 * OPENING-BALANCE-001) rather than duplicating that read on the Finance side.
 * Lazy: only fetches once a bill detail is actually opened for that supplier.
 */
export function useSupplierFinancialSummary(supplierId: string | null) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'suppliers', supplierId, 'financial-summary'],
    queryFn: () => suppliersService.financialSummary(supplierId as string),
    enabled: Boolean(supplierId),
  });
}

/**
 * Apply part (or all) of the supplier's available advance to ONE posted bill — an
 * explicit, single-bill, user-confirmed write (TASK-ECOS-PROCUREMENT-SUPPLIERS-
 * BATCH-01-FINAL-IMPLEMENTATION-CLOSURE-002). Never triggered automatically. The
 * caller must supply a stable `idempotencyKey` for the lifetime of one confirmation
 * (reused across this mutation's own retries, not regenerated per attempt) so a
 * duplicate submission of the same command cannot double-apply the advance.
 */
export function useApplyAdvance() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ uuid, amount, idempotencyKey }: { uuid: string; amount: number; idempotencyKey: string }) =>
      financeApService.applyAdvance(uuid, amount, idempotencyKey),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'bills'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'aging'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'suppliers', result.supplier_id, 'financial-summary'] });
    },
  });
}

/** Auto-allocate a payment across the supplier's open bills (oldest first). */
export function useAutoAllocatePayment() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (uuid: string) => financeApService.autoAllocate(uuid),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'payments'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'bills'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'aging'] });
    },
  });
}

/**
 * Reverse part (or all) of one posted allocation with a new, append-only
 * contra-allocation. Exposed here to mirror the full backend contract, but
 * no drawer currently calls it — see payment-detail-drawer.tsx's header
 * comment for why (no endpoint lists a payment's existing allocations to
 * pick one to reverse).
 */
export function useReversePaymentAllocation() {
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
    }) => financeApService.reverseAllocation(uuid, allocationUuid, amount, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'payments'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'bills'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'aging'] });
    },
  });
}

/** Reverse a posted payment's journal AND its supplier-ledger entry together. */
export function useReversePaymentPosting() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ uuid, reason }: { uuid: string; reason: string }) =>
      financeApService.reversePosting(uuid, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'payments'] });
      qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'ap', 'aging'] });
    },
  });
}

export function useSupplierLedger(supplierId: string | null) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ap', 'ledger', supplierId],
    queryFn: () => financeApService.supplierLedger(supplierId as string),
    enabled: Boolean(supplierId),
  });
}

export function useApControlReconciliation() {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'ap', 'reconciliation'],
    queryFn: () => financeApService.controlReconciliation(),
  });
}
