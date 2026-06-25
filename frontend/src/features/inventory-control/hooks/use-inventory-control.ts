import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { inventoryControlService } from '@/features/inventory-control/services/inventory-control-service';
import type {
  AbcClassificationsQuery,
  CycleCountPlansQuery,
  VarianceAnalyticsQuery,
  WarehousePerformanceQuery,
} from '@/features/inventory-control/types/inventory-control';

const DASHBOARD_KEY       = 'inventory-dashboard';
const ABC_KEY             = 'inventory-abc-classifications';
const VARIANCE_KEY        = 'inventory-variance-analytics';
const WAREHOUSE_PERF_KEY  = 'inventory-warehouse-performance';
const CYCLE_PLANS_KEY     = 'inventory-cycle-count-plans';

export function useInventoryDashboard() {
  return useQuery({
    queryKey: [DASHBOARD_KEY],
    queryFn:  () => inventoryControlService.getDashboard(),
  });
}

export function useAbcClassifications(params: AbcClassificationsQuery) {
  return useQuery({
    queryKey: [ABC_KEY, params],
    queryFn:  () => inventoryControlService.getAbcClassifications(params),
    placeholderData: keepPreviousData,
  });
}

export function useRecalculateAbc() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => inventoryControlService.recalculateAbc(),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: [ABC_KEY] });
      void queryClient.invalidateQueries({ queryKey: [CYCLE_PLANS_KEY] });
      void queryClient.invalidateQueries({ queryKey: [DASHBOARD_KEY] });
    },
  });
}

export function useVarianceAnalytics(params: VarianceAnalyticsQuery = {}) {
  return useQuery({
    queryKey: [VARIANCE_KEY, params],
    queryFn:  () => inventoryControlService.getVarianceAnalytics(params),
  });
}

export function useWarehousePerformance(params: WarehousePerformanceQuery = {}) {
  return useQuery({
    queryKey: [WAREHOUSE_PERF_KEY, params],
    queryFn:  () => inventoryControlService.getWarehousePerformance(params),
  });
}

export function useCycleCountPlans(params: CycleCountPlansQuery) {
  return useQuery({
    queryKey: [CYCLE_PLANS_KEY, params],
    queryFn:  () => inventoryControlService.getCycleCountPlans(params),
    placeholderData: keepPreviousData,
  });
}
