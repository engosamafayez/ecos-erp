import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from '@/components/ds/use-toast';

import { supplierInvoicesService } from '@/features/supplier-invoices/services/supplier-invoices-service';
import type {
  CreateSupplierInvoicePayload,
  SupplierInvoicesQuery,
} from '@/features/supplier-invoices/types/supplier-invoice';

const KEYS = {
  all:   ['supplier-invoices'] as const,
  list:  (q: SupplierInvoicesQuery) => ['supplier-invoices', 'list', q] as const,
  detail:(id: string) => ['supplier-invoices', id] as const,
  stats: ['supplier-invoices', 'stats'] as const,
};

export function useSupplierInvoicesQuery(params: SupplierInvoicesQuery) {
  return useQuery({
    queryKey: KEYS.list(params),
    queryFn:  () => supplierInvoicesService.list(params),
    placeholderData: (prev) => prev,
  });
}

export function useSupplierInvoice(id: string | null) {
  return useQuery({
    queryKey: KEYS.detail(id ?? ''),
    queryFn:  () => supplierInvoicesService.get(id!),
    enabled:  id !== null,
  });
}

export function useSupplierInvoiceStats() {
  return useQuery({
    queryKey: KEYS.stats,
    queryFn:  () => supplierInvoicesService.stats(),
  });
}

export function useCreateSupplierInvoice() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateSupplierInvoicePayload) =>
      supplierInvoicesService.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Supplier invoice created');
    },
    onError: () => toast.error('Failed to create invoice'),
  });
}

export function useUpdateSupplierInvoice(id: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateSupplierInvoicePayload) =>
      supplierInvoicesService.update(id, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice updated');
    },
    onError: () => toast.error('Failed to update invoice'),
  });
}

export function useDeleteSupplierInvoice() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => supplierInvoicesService.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice deleted');
    },
    onError: () => toast.error('Failed to delete invoice'),
  });
}

export function useValidateSupplierInvoice() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => supplierInvoicesService.validate(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice validated — ready to post');
    },
    onError: () => toast.error('Validation failed'),
  });
}

export function usePostSupplierInvoice() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => supplierInvoicesService.post(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice posted — inventory updated');
    },
    onError: () => toast.error('Posting failed'),
  });
}

export function useCancelSupplierInvoice() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => supplierInvoicesService.cancel(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.all });
      toast.success('Invoice cancelled');
    },
    onError: () => toast.error('Failed to cancel invoice'),
  });
}
