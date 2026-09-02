import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';

import { financeCostAllocationService } from '../services/finance-cost-allocation-service';
import type { CostAllocationCreateInput, CostAllocationListParams } from '../types/finance-cost-allocation';

/**
 * React-query hooks for the Cost Allocation tab (Costing & Profitability).
 * Company-scoped keys, mirroring use-finance-expense.ts's exact shape (query
 * keys, mutation invalidation).
 */
function useCompanyId() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useCostAllocations(params: CostAllocationListParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'cost-allocations', params],
    queryFn: () => financeCostAllocationService.list(params),
  });
}

export function useCreateCostAllocation() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CostAllocationCreateInput) => financeCostAllocationService.create(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'cost-allocations'] }),
  });
}

export function useReverseCostAllocation() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ uuid, reason }: { uuid: string; reason: string }) =>
      financeCostAllocationService.reverse(uuid, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'cost-allocations'] }),
  });
}
