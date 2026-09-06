import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type {
  ApAging,
  ApAllocation,
  ApAllocationReversal,
  ApApplyAdvanceResult,
  ApAutoAllocateResult,
  ApBill,
  ApBillParams,
  ApControlReconciliation,
  ApPayment,
  ApPaymentParams,
  ApReversePostingResult,
  SupplierLedger,
} from '../types/finance-ap';

/**
 * Accounts Payable API client (EPIC-FINANCE-UI-001 Phase 5, extended by
 * TASK-ECOS-FINANCE-AP-AR-MUTATION-UX with the allocation/reversal write
 * actions). Against the certified AP endpoints only; unwraps the `{ data }`
 * envelope. No backend changes beyond what the Allocation Engine already
 * ships (see AllocationEngine / SupplierPaymentController).
 */
export const financeApService = {
  async aging(params: { as_of?: string; supplier_id?: string } = {}): Promise<ApAging> {
    const { data } = await api.get<ApiResponse<ApAging>>('/finance/ap/aging', { params });
    return data.data;
  },

  async bills(params: ApBillParams = {}): Promise<ApBill[]> {
    const { data } = await api.get<ApiResponse<ApBill[]>>('/finance/ap/bills', { params });
    return data.data;
  },

  async payments(params: ApPaymentParams = {}): Promise<ApPayment[]> {
    const { data } = await api.get<ApiResponse<ApPayment[]>>('/finance/ap/payments', { params });
    return data.data;
  },

  /** Allocate part (or all) of a posted payment to one posted bill. */
  async allocate(uuid: string, billId: string, amount: number): Promise<ApAllocation> {
    const { data } = await api.post<ApiResponse<ApAllocation>>(`/finance/ap/payments/${uuid}/allocate`, {
      bill_id: billId,
      amount,
    });
    return data.data;
  },

  /** Auto-allocate a payment across the supplier's open bills (oldest first). */
  async autoAllocate(uuid: string): Promise<ApAutoAllocateResult> {
    const { data } = await api.post<ApiResponse<ApAutoAllocateResult>>(
      `/finance/ap/payments/${uuid}/auto-allocate`,
    );
    return data.data;
  },

  /** Reverse part (or all) of one posted allocation with a new, append-only contra-allocation. */
  async reverseAllocation(
    uuid: string,
    allocationUuid: string,
    amount: number,
    reason: string,
  ): Promise<ApAllocationReversal> {
    const { data } = await api.post<ApiResponse<ApAllocationReversal>>(
      `/finance/ap/payments/${uuid}/allocations/${allocationUuid}/reverse`,
      { amount, reason },
    );
    return data.data;
  },

  /** Reverse a posted payment's journal AND its supplier-ledger entry together. */
  async reversePosting(uuid: string, reason: string): Promise<ApReversePostingResult> {
    const { data } = await api.post<ApiResponse<ApReversePostingResult>>(
      `/finance/ap/payments/${uuid}/reverse-posting`,
      { reason },
    );
    return data.data;
  },

  /**
   * Apply part (or all) of the supplier's available advance to ONE posted bill — an
   * explicit, single-bill, user-confirmed write (never an automatic sweep). The
   * `idempotencyKey` guards against a duplicate submission of the same command
   * (double-click, browser/client retry); callers must reuse the SAME key across
   * retries of one logical confirmation and mint a fresh one for each new attempt.
   */
  async applyAdvance(uuid: string, amount: number, idempotencyKey: string): Promise<ApApplyAdvanceResult> {
    const { data } = await api.post<ApiResponse<ApApplyAdvanceResult>>(
      `/finance/ap/bills/${uuid}/apply-advance`,
      { amount },
      { headers: { 'Idempotency-Key': idempotencyKey } },
    );
    return data.data;
  },

  async supplierLedger(supplierId: string, params: { from?: string; to?: string } = {}): Promise<SupplierLedger> {
    const { data } = await api.get<ApiResponse<SupplierLedger>>(
      `/finance/ap/suppliers/${supplierId}/ledger`,
      { params },
    );
    return data.data;
  },

  async controlReconciliation(): Promise<ApControlReconciliation> {
    const { data } = await api.get<ApiResponse<ApControlReconciliation>>(
      '/finance/control-reconciliation/payable',
    );
    return data.data;
  },
};
