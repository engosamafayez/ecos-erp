import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type {
  CashFlowCurrent,
  CashFlowForecast,
  CashFlowForecastParams,
  CostBreakdown,
  CostOperationalClassification,
  CostTrend,
  CostTrendParams,
  FinanceIntelligenceWindowParams,
  ProfitabilityByBrand,
  ProfitabilityByCustomer,
  ProfitabilityByDimension,
  ProfitabilityCompany,
  ProfitabilityUnavailable,
} from '../types/finance-intelligence';

/**
 * Finance Intelligence API client (Costing & Profitability) — profitability,
 * cost intelligence and cash-flow, against the certified
 * `Modules\Finance\Intelligence` endpoints. Unwraps the `{ data }` envelope.
 * Read-only; no backend changes.
 *
 * Every route below is gated server-side by `permission:finance.analytics.view`
 * (the `finance/intelligence` route group in routes/api.php) — the same
 * permission the existing Finance Executive workspace already uses
 * (finance-executive-page.tsx) for a similarly-scoped read surface.
 */
const BASE = '/finance/intelligence';

export const financeIntelligenceService = {
  profitability: {
    async company(params: FinanceIntelligenceWindowParams = {}): Promise<ProfitabilityCompany> {
      const { data } = await api.get<ApiResponse<ProfitabilityCompany>>(`${BASE}/profitability/company`, { params });
      return data.data;
    },

    async branch(params: FinanceIntelligenceWindowParams = {}): Promise<ProfitabilityByDimension> {
      const { data } = await api.get<ApiResponse<ProfitabilityByDimension>>(`${BASE}/profitability/branch`, { params });
      return data.data;
    },

    async costCenter(params: FinanceIntelligenceWindowParams = {}): Promise<ProfitabilityByDimension> {
      const { data } = await api.get<ApiResponse<ProfitabilityByDimension>>(`${BASE}/profitability/cost-center`, { params });
      return data.data;
    },

    async project(params: FinanceIntelligenceWindowParams = {}): Promise<ProfitabilityByDimension> {
      const { data } = await api.get<ApiResponse<ProfitabilityByDimension>>(`${BASE}/profitability/project`, { params });
      return data.data;
    },

    async brand(params: FinanceIntelligenceWindowParams = {}): Promise<ProfitabilityByBrand> {
      const { data } = await api.get<ApiResponse<ProfitabilityByBrand>>(`${BASE}/profitability/brand`, { params });
      return data.data;
    },

    async customer(params: FinanceIntelligenceWindowParams = {}): Promise<ProfitabilityByCustomer> {
      const { data } = await api.get<ApiResponse<ProfitabilityByCustomer>>(`${BASE}/profitability/customer`, { params });
      return data.data;
    },

    /** Deliberately returns `available:false` — see ProfitabilityUnavailable. */
    async product(params: FinanceIntelligenceWindowParams = {}): Promise<ProfitabilityUnavailable> {
      const { data } = await api.get<ApiResponse<ProfitabilityUnavailable>>(`${BASE}/profitability/product`, { params });
      return data.data;
    },

    /** Deliberately returns `available:false` — see ProfitabilityUnavailable. */
    async channel(params: FinanceIntelligenceWindowParams = {}): Promise<ProfitabilityUnavailable> {
      const { data } = await api.get<ApiResponse<ProfitabilityUnavailable>>(`${BASE}/profitability/channel`, { params });
      return data.data;
    },
  },

  cost: {
    async breakdown(params: FinanceIntelligenceWindowParams = {}): Promise<CostBreakdown> {
      const { data } = await api.get<ApiResponse<CostBreakdown>>(`${BASE}/cost/breakdown`, { params });
      return data.data;
    },

    async operational(params: FinanceIntelligenceWindowParams = {}): Promise<CostOperationalClassification> {
      const { data } = await api.get<ApiResponse<CostOperationalClassification>>(`${BASE}/cost/operational`, { params });
      return data.data;
    },

    /** Takes `months`, not from/to — see CostIntelligenceController::trend(). */
    async trend(params: CostTrendParams = {}): Promise<CostTrend> {
      const { data } = await api.get<ApiResponse<CostTrend>>(`${BASE}/cost/trend`, { params });
      return data.data;
    },
  },

  cashFlow: {
    /** Takes no params at all — always "as of today" / month-to-date. */
    async current(): Promise<CashFlowCurrent> {
      const { data } = await api.get<ApiResponse<CashFlowCurrent>>(`${BASE}/cash-flow/current`);
      return data.data;
    },

    /** Takes `horizon` (months, default 3), not from/to — see CashFlowController::forecast(). */
    async forecast(params: CashFlowForecastParams = {}): Promise<CashFlowForecast> {
      const { data } = await api.get<ApiResponse<CashFlowForecast>>(`${BASE}/cash-flow/forecast`, { params });
      return data.data;
    },
  },
};
