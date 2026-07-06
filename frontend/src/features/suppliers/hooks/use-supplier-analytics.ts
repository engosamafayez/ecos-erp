import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { supplierAnalyticsService } from '@/features/suppliers/services/supplier-analytics-service';

export function useSupplierSummaryStats() {
  return useQuery({
    queryKey: ['supplier-summary-stats'],
    queryFn: () => supplierAnalyticsService.getSummaryStats(),
    staleTime: 60_000,
  });
}

export function useSupplierAnalytics(supplierId: string) {
  return useQuery({
    queryKey: ['supplier-analytics', supplierId],
    queryFn: () => supplierAnalyticsService.getAnalytics(supplierId),
    enabled: Boolean(supplierId),
  });
}

export function useSupplierInventoryBreakdown(supplierId: string) {
  return useQuery({
    queryKey: ['supplier-inventory-breakdown', supplierId],
    queryFn: () => supplierAnalyticsService.getInventoryBreakdown(supplierId),
    enabled: Boolean(supplierId),
  });
}

export function useSupplierHealth(supplierId: string) {
  return useQuery({
    queryKey: ['supplier-health', supplierId],
    queryFn: () => supplierAnalyticsService.getHealth(supplierId),
    enabled: Boolean(supplierId),
    staleTime: 5 * 60_000,
  });
}

export function useSupplierPriceHistory(supplierId: string) {
  return useQuery({
    queryKey: ['supplier-price-history', supplierId],
    queryFn: () => supplierAnalyticsService.getPriceHistory(supplierId),
    enabled: Boolean(supplierId),
  });
}

export function useSupplierTimeline(supplierId: string) {
  return useQuery({
    queryKey: ['supplier-timeline', supplierId],
    queryFn: () => supplierAnalyticsService.getTimeline(supplierId),
    enabled: Boolean(supplierId),
  });
}

export function useSupplierDocuments(supplierId: string) {
  return useQuery({
    queryKey: ['supplier-documents', supplierId],
    queryFn: () => supplierAnalyticsService.getDocuments(supplierId),
    enabled: Boolean(supplierId),
  });
}

export function useUploadSupplierDocument(supplierId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (formData: FormData) => supplierAnalyticsService.uploadDocument(supplierId, formData),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['supplier-documents', supplierId] });
    },
  });
}

export function useDeleteSupplierDocument(supplierId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (documentId: string) => supplierAnalyticsService.deleteDocument(supplierId, documentId),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['supplier-documents', supplierId] });
    },
  });
}
