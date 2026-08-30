import { api } from '@/lib/axios';
import type {
  SuppliersQuery,
  SuppliersResult,
  Supplier,
  SupplierPayload,
} from '@/features/suppliers/types/supplier';
import type { ApiResponse } from '@/types';

/**
 * Suppliers API client. Unwraps the standardized ApiResponse envelope.
 */
export const suppliersService = {
  async list(params: SuppliersQuery): Promise<SuppliersResult> {
    const { data } = await api.get<ApiResponse<SuppliersResult>>('/suppliers', { params });
    return data.data;
  },

  async get(id: string): Promise<Supplier> {
    const { data } = await api.get<ApiResponse<Supplier>>(`/suppliers/${id}`);
    return data.data;
  },

  async create(payload: SupplierPayload): Promise<Supplier> {
    const { data } = await api.post<ApiResponse<Supplier>>('/suppliers', payload);
    return data.data;
  },

  async update(id: string, payload: SupplierPayload): Promise<Supplier> {
    const { data } = await api.put<ApiResponse<Supplier>>(`/suppliers/${id}`, payload);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/suppliers/${id}`);
  },

  /**
   * Ledger-derived financial summary for Supplier 360 — Outstanding Payable and Available
   * Advance are DISTINCT figures (advance is a prepaid credit, never shown as debt).
   * TASK-PROC-SUPPLIER-OPENING-BALANCE-001.
   */
  async financialSummary(id: string): Promise<SupplierFinancialSummary> {
    const { data } = await api.get<ApiResponse<SupplierFinancialSummary>>(`/suppliers/${id}/financial-summary`);
    return data.data;
  },

  /** Post a supplier opening balance (payable or advance). Backed by Finance (no fake bill). */
  async postOpeningBalance(id: string, payload: OpeningBalancePayload): Promise<OpeningBalanceResult> {
    const { data } = await api.post<ApiResponse<OpeningBalanceResult>>(`/suppliers/${id}/opening-balance`, payload);
    return data.data;
  },
};

export type SupplierFinancialSummary = {
  outstanding_payable: number;
  available_advance: number;
  net_balance: number;
};

export type OpeningBalancePayload = {
  type: 'payable' | 'advance';
  amount: number;
  opening_date: string;
  reference?: string;
  notes?: string;
};

export type OpeningBalanceResult = {
  entry_id: number;
  entry_type: string;
  journal_entry_id: number;
  summary: SupplierFinancialSummary;
};
