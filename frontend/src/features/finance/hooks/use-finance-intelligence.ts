import { useQuery } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';

import { financeIntelligenceService } from '../services/finance-intelligence-service';
import type {
  CashFlowForecastParams,
  CostTrendParams,
  FinanceIntelligenceWindowParams,
} from '../types/finance-intelligence';

/**
 * React-query hooks for Finance Intelligence (Costing & Profitability) —
 * profitability, cost intelligence, cash-flow. Company-scoped keys, mirroring
 * use-finance-expense.ts / use-finance-cost-allocation.ts's exact shape. All
 * read-only; every endpoint is gated server-side by `finance.analytics.view`.
 */
function useCompanyId() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

// ── Profitability ─────────────────────────────────────────────────────────────

export function useProfitabilityCompany(params: FinanceIntelligenceWindowParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'profitability', 'company', params],
    queryFn: () => financeIntelligenceService.profitability.company(params),
  });
}

export function useProfitabilityBranch(params: FinanceIntelligenceWindowParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'profitability', 'branch', params],
    queryFn: () => financeIntelligenceService.profitability.branch(params),
  });
}

export function useProfitabilityCostCenter(params: FinanceIntelligenceWindowParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'profitability', 'cost-center', params],
    queryFn: () => financeIntelligenceService.profitability.costCenter(params),
  });
}

export function useProfitabilityProject(params: FinanceIntelligenceWindowParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'profitability', 'project', params],
    queryFn: () => financeIntelligenceService.profitability.project(params),
  });
}

export function useProfitabilityCustomer(params: FinanceIntelligenceWindowParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'profitability', 'customer', params],
    queryFn: () => financeIntelligenceService.profitability.customer(params),
  });
}

/** Always resolves to `{ available: false, ... }` — see ProfitabilityUnavailable. */
export function useProfitabilityProduct(params: FinanceIntelligenceWindowParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'profitability', 'product', params],
    queryFn: () => financeIntelligenceService.profitability.product(params),
  });
}

/** Always resolves to `{ available: false, ... }` — see ProfitabilityUnavailable. */
export function useProfitabilityChannel(params: FinanceIntelligenceWindowParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'profitability', 'channel', params],
    queryFn: () => financeIntelligenceService.profitability.channel(params),
  });
}

// ── Cost intelligence ─────────────────────────────────────────────────────────

export function useCostBreakdown(params: FinanceIntelligenceWindowParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'cost-intelligence', 'breakdown', params],
    queryFn: () => financeIntelligenceService.cost.breakdown(params),
  });
}

export function useCostOperational(params: FinanceIntelligenceWindowParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'cost-intelligence', 'operational', params],
    queryFn: () => financeIntelligenceService.cost.operational(params),
  });
}

/** Takes `months`, not from/to — see CostTrendParams. */
export function useCostTrend(params: CostTrendParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'cost-intelligence', 'trend', params],
    queryFn: () => financeIntelligenceService.cost.trend(params),
  });
}

// ── Cash-flow intelligence ────────────────────────────────────────────────────

/** Takes no params — always "as of today" / month-to-date. */
export function useCashFlowCurrent() {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'cash-flow', 'current'],
    queryFn: () => financeIntelligenceService.cashFlow.current(),
  });
}

/** Takes `horizon` (months), not from/to — see CashFlowForecastParams. */
export function useCashFlowForecast(params: CashFlowForecastParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'cash-flow', 'forecast', params],
    queryFn: () => financeIntelligenceService.cashFlow.forecast(params),
  });
}
