import { useQuery } from '@tanstack/react-query';
import { supplierAnalyticsService } from '@/features/suppliers/services/supplier-analytics-service';

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
