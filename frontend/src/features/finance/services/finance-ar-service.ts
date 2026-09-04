import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type {
  ArAging,
  ArAllocation,
  ArAllocationReversal,
  ArAutoAllocateResult,
  ArControlReconciliation,
  ArInvoice,
  ArInvoiceParams,
  ArReceipt,
  ArReceiptParams,
  ArReversePostingResult,
  CustomerLedger,
} from '../types/finance-ar';

/**
 * Accounts Receivable API client (EPIC-FINANCE-UI-001 Phase 4, extended by
 * TASK-ECOS-FINANCE-AP-AR-MUTATION-UX with the allocation/reversal write
 * actions). Against the certified AR endpoints only; unwraps the `{ data }`
 * envelope. No backend changes beyond what the Allocation Engine already
 * ships (see AllocationEngine / CustomerReceiptController).
 */
export const financeArService = {
  async aging(params: { as_of?: string; customer_id?: string } = {}): Promise<ArAging> {
    const { data } = await api.get<ApiResponse<ArAging>>('/finance/ar/aging', { params });
    return data.data;
  },

  async invoices(params: ArInvoiceParams = {}): Promise<ArInvoice[]> {
    const { data } = await api.get<ApiResponse<ArInvoice[]>>('/finance/ar/invoices', { params });
    return data.data;
  },

  async receipts(params: ArReceiptParams = {}): Promise<ArReceipt[]> {
    const { data } = await api.get<ApiResponse<ArReceipt[]>>('/finance/ar/receipts', { params });
    return data.data;
  },

  /** Allocate part (or all) of a posted receipt to one posted invoice. */
  async allocate(uuid: string, invoiceId: string, amount: number): Promise<ArAllocation> {
    const { data } = await api.post<ApiResponse<ArAllocation>>(`/finance/ar/receipts/${uuid}/allocate`, {
      invoice_id: invoiceId,
      amount,
    });
    return data.data;
  },

  /** Auto-allocate a receipt across the customer's open invoices (FIFO). */
  async autoAllocate(uuid: string): Promise<ArAutoAllocateResult> {
    const { data } = await api.post<ApiResponse<ArAutoAllocateResult>>(
      `/finance/ar/receipts/${uuid}/auto-allocate`,
    );
    return data.data;
  },

  /** Reverse part (or all) of one posted allocation with a new, append-only contra-allocation. */
  async reverseAllocation(
    uuid: string,
    allocationUuid: string,
    amount: number,
    reason: string,
  ): Promise<ArAllocationReversal> {
    const { data } = await api.post<ApiResponse<ArAllocationReversal>>(
      `/finance/ar/receipts/${uuid}/allocations/${allocationUuid}/reverse`,
      { amount, reason },
    );
    return data.data;
  },

  /** Reverse a posted receipt's journal AND its customer-ledger entry together. */
  async reversePosting(uuid: string, reason: string): Promise<ArReversePostingResult> {
    const { data } = await api.post<ApiResponse<ArReversePostingResult>>(
      `/finance/ar/receipts/${uuid}/reverse-posting`,
      { reason },
    );
    return data.data;
  },

  async customerLedger(customerId: string, params: { from?: string; to?: string } = {}): Promise<CustomerLedger> {
    const { data } = await api.get<ApiResponse<CustomerLedger>>(
      `/finance/ar/customers/${customerId}/ledger`,
      { params },
    );
    return data.data;
  },

  async controlReconciliation(): Promise<ArControlReconciliation> {
    const { data } = await api.get<ApiResponse<ArControlReconciliation>>(
      '/finance/control-reconciliation/receivable',
    );
    return data.data;
  },
};
