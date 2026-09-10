import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { toast } from '@/components/ds/use-toast';

import { supplierInvoicesService } from '@/features/supplier-invoices/services/supplier-invoices-service';
import type {
  CreateSupplierInvoicePayload,
  SupplierInvoicesQuery,
} from '@/features/supplier-invoices/types/supplier-invoice';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

/** Real backend message when there is one, instead of a generic guess. */
function extractMessage(error: unknown, fallback: string): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : fallback;
}

function useKeys() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return {
    all:   ['company', companyId, 'supplier-invoices'] as const,
    list:  (q: SupplierInvoicesQuery) => ['company', companyId, 'supplier-invoices', 'list', q] as const,
    detail:(id: string) => ['company', companyId, 'supplier-invoices', id] as const,
    stats: ['company', companyId, 'supplier-invoices', 'stats'] as const,
  };
}

export function useSupplierInvoicesQuery(params: SupplierInvoicesQuery) {
  const KEYS = useKeys();
  return useQuery({
    queryKey: KEYS.list(params),
    queryFn:  () => supplierInvoicesService.list(params),
    placeholderData: (prev) => prev,
  });
}

export function useSupplierInvoice(id: string | null) {
  const KEYS = useKeys();
  return useQuery({
    queryKey: KEYS.detail(id ?? ''),
    queryFn:  () => supplierInvoicesService.get(id!),
    enabled:  id !== null,
  });
}

// §9 (remediation-004) — eligible Goods Receipt Lines for the invoice line editor's explicit
// anchor picker. Disabled until both supplier and product are known (an anchor is meaningless
// without both), matching the pattern other scoped option hooks in this editor already use.
export function useEligibleReceiptLines(supplierId: string, productId: string, excludeInvoiceId?: string) {
  const KEYS = useKeys();
  return useQuery({
    queryKey: [...KEYS.all, 'eligible-receipt-lines', supplierId, productId, excludeInvoiceId ?? ''],
    queryFn: () => supplierInvoicesService.eligibleReceiptLines({
      supplier_id: supplierId,
      product_id: productId,
      exclude_invoice_id: excludeInvoiceId,
    }),
    enabled: supplierId !== '' && productId !== '',
    staleTime: 30_000,
  });
}

export function useSupplierInvoiceStats() {
  const KEYS = useKeys();
  return useQuery({
    queryKey: KEYS.stats,
    queryFn:  () => supplierInvoicesService.stats(),
  });
}

export function useCreateSupplierInvoice() {
  const KEYS = useKeys();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateSupplierInvoicePayload) =>
      supplierInvoicesService.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Supplier invoice created');
    },
    onError: (error) => toast.error(extractMessage(error, 'Failed to create invoice')),
  });
}

export function useUpdateSupplierInvoice(id: string) {
  const KEYS = useKeys();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateSupplierInvoicePayload) =>
      supplierInvoicesService.update(id, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice updated');
    },
    onError: (error) => toast.error(extractMessage(error, 'Failed to update invoice')),
  });
}

export function useDeleteSupplierInvoice() {
  const KEYS = useKeys();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => supplierInvoicesService.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice deleted');
    },
    onError: (error) => toast.error(extractMessage(error, 'Failed to delete invoice')),
  });
}

export function useValidateSupplierInvoice() {
  const KEYS = useKeys();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => supplierInvoicesService.validate(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice validated — ready to post');
    },
    onError: (error) => toast.error(extractMessage(error, 'Validation failed')),
  });
}

export function usePostSupplierInvoice() {
  const KEYS = useKeys();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => supplierInvoicesService.post(id),
    // The backend now returns a specific, actionable reason on failure (e.g. a missing
    // goods-receipt anchor, or an unmapped Finance account role) — surface it instead of a
    // dead-end generic string.
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice posted successfully');
    },
    onError: (error) => toast.error(extractMessage(error, 'Posting failed')),
  });
}

export function useCancelSupplierInvoice() {
  const KEYS = useKeys();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => supplierInvoicesService.cancel(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice cancelled');
    },
    onError: (error) => toast.error(extractMessage(error, 'Failed to cancel invoice')),
  });
}

// §12/§13 — Warehouse Full Rejection; same invalidation as every other lifecycle mutation above.
export function useRejectInvoiceReceiving() {
  const KEYS = useKeys();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      supplierInvoicesService.rejectReceiving(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice rejected — receiving cancelled');
    },
    onError: (error) => toast.error(extractMessage(error, 'Failed to reject receiving')),
  });
}

// ── Attachment (§3) — toast-free; the attachment UI owns the i18n feedback ──
export function useInvoiceDocuments(invoiceId: string | null) {
  const KEYS = useKeys();
  return useQuery({
    queryKey: [...KEYS.detail(invoiceId ?? ''), 'documents'],
    queryFn: () => supplierInvoicesService.listDocuments(invoiceId as string),
    enabled: invoiceId !== null,
  });
}

export function useUploadInvoiceDocument(invoiceId: string) {
  const KEYS = useKeys();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ file, notes }: { file: File; notes?: string }) =>
      supplierInvoicesService.uploadDocument(invoiceId, file, notes),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...KEYS.detail(invoiceId), 'documents'] }),
  });
}

export function useDeleteInvoiceDocument(invoiceId: string) {
  const KEYS = useKeys();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (documentId: string) => supplierInvoicesService.deleteDocument(invoiceId, documentId),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...KEYS.detail(invoiceId), 'documents'] }),
  });
}
