import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  CreateSupplierInvoicePayload,
  SupplierInvoice,
  SupplierInvoiceDocument,
  SupplierInvoicesQuery,
  SupplierInvoicesResult,
} from '@/features/supplier-invoices/types/supplier-invoice';

type InvoiceStats = {
  total: number;
  draft: number;
  validated: number;
  posted: number;
  failed: number;
  total_value: number;
  pending_value: number;
};

export const supplierInvoicesService = {
  async list(params: SupplierInvoicesQuery): Promise<SupplierInvoicesResult> {
    const { data } = await api.get<ApiResponse<SupplierInvoicesResult>>('/supplier-invoices', { params });
    return data.data;
  },

  async get(id: string): Promise<SupplierInvoice> {
    const { data } = await api.get<ApiResponse<SupplierInvoice>>(`/supplier-invoices/${id}`);
    return data.data;
  },

  async create(payload: CreateSupplierInvoicePayload): Promise<SupplierInvoice> {
    const { data } = await api.post<ApiResponse<SupplierInvoice>>('/supplier-invoices', payload);
    return data.data;
  },

  async update(id: string, payload: CreateSupplierInvoicePayload): Promise<SupplierInvoice> {
    const { data } = await api.put<ApiResponse<SupplierInvoice>>(`/supplier-invoices/${id}`, payload);
    return data.data;
  },

  async delete(id: string): Promise<void> {
    await api.delete(`/supplier-invoices/${id}`);
  },

  async validate(id: string): Promise<SupplierInvoice> {
    const { data } = await api.post<ApiResponse<SupplierInvoice>>(`/supplier-invoices/${id}/validate`);
    return data.data;
  },

  async post(id: string): Promise<SupplierInvoice> {
    const { data } = await api.post<ApiResponse<SupplierInvoice>>(`/supplier-invoices/${id}/post`);
    return data.data;
  },

  async cancel(id: string): Promise<SupplierInvoice> {
    const { data } = await api.post<ApiResponse<SupplierInvoice>>(`/supplier-invoices/${id}/cancel`);
    return data.data;
  },

  async stats(): Promise<InvoiceStats> {
    const { data } = await api.get<ApiResponse<InvoiceStats>>('/supplier-invoices/stats');
    return data.data;
  },

  // ── Attachment (§3) — canonical DocumentService, private disk, auth+tenant gated ──
  async listDocuments(invoiceId: string): Promise<SupplierInvoiceDocument[]> {
    const { data } = await api.get<ApiResponse<SupplierInvoiceDocument[]>>(
      `/supplier-invoices/${invoiceId}/documents`,
    );
    return data.data;
  },

  async uploadDocument(invoiceId: string, file: File, notes?: string): Promise<SupplierInvoiceDocument> {
    const form = new FormData();
    form.append('file', file);
    if (notes) form.append('notes', notes);
    const { data } = await api.post<ApiResponse<SupplierInvoiceDocument>>(
      `/supplier-invoices/${invoiceId}/documents`,
      form,
    );
    return data.data;
  },

  /** The private file is streamed behind auth — fetch it as a blob (bearer token via the shared axios). */
  async downloadDocument(invoiceId: string, documentId: string): Promise<Blob> {
    const res = await api.get<Blob>(
      `/supplier-invoices/${invoiceId}/documents/${documentId}/download`,
      { responseType: 'blob' },
    );
    return res.data;
  },

  async deleteDocument(invoiceId: string, documentId: string): Promise<void> {
    await api.delete(`/supplier-invoices/${invoiceId}/documents/${documentId}`);
  },
};
