import axios from 'axios';
import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  ApprovePayload,
  AssignPayload,
  ProductCostDetail,
  PricingReviewsQuery,
  PricingReviewsResult,
  SnoozePayload,
} from '@/features/cost-management/types/pricing-review';

const BASE = '/cost-management/pricing-reviews';

async function safeFetch<T>(fn: () => Promise<T>, fallback: T): Promise<T> {
  try {
    return await fn();
  } catch (err) {
    if (axios.isAxiosError(err) && (err.response?.status === 404 || err.response?.status === 405)) {
      return fallback;
    }
    throw err;
  }
}

const emptyResult: PricingReviewsResult = {
  items: [],
  summary: {
    pending_count: 0,
    below_target_count: 0,
    above_target_count: 0,
    cost_increased_today: 0,
    cost_decreased_today: 0,
    expected_profit_change: 0,
  },
  meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 },
};

export const pricingReviewService = {
  async list(params: PricingReviewsQuery): Promise<PricingReviewsResult> {
    return safeFetch(async () => {
      const { data } = await api.get<ApiResponse<PricingReviewsResult>>(BASE, { params });
      return data.data;
    }, emptyResult);
  },

  async getDetail(id: string): Promise<ProductCostDetail | null> {
    return safeFetch(async () => {
      const { data } = await api.get<ApiResponse<ProductCostDetail>>(`${BASE}/${id}/detail`);
      return data.data;
    }, null);
  },

  async approve(id: string, payload: ApprovePayload): Promise<void> {
    await api.post(`${BASE}/${id}/approve`, payload);
  },

  async snooze(id: string, payload: SnoozePayload): Promise<void> {
    await api.post(`${BASE}/${id}/snooze`, payload);
  },

  async assign(id: string, payload: AssignPayload): Promise<void> {
    await api.post(`${BASE}/${id}/assign`, payload);
  },

  async bulkApprove(ids: string[], action: ApprovePayload['action']): Promise<void> {
    await api.post(`${BASE}/bulk-approve`, { ids, action });
  },
};
