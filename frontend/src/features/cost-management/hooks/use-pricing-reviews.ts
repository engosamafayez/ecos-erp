import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { pricingReviewService } from '@/features/cost-management/services/pricing-review-service';
import type {
  ApprovePayload,
  AssignPayload,
  BulkApprovePayload,
  PricingReviewsQuery,
  SnoozePayload,
} from '@/features/cost-management/types/pricing-review';

const KEY = 'pricing-reviews';

export function usePricingReviews(params: PricingReviewsQuery) {
  return useQuery({
    queryKey: [KEY, params],
    queryFn: () => pricingReviewService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useProductCostDetail(id: string | null) {
  return useQuery({
    queryKey: [KEY, 'detail', id],
    queryFn: () => pricingReviewService.getDetail(id!),
    enabled: Boolean(id),
    staleTime: 60_000,
  });
}

export function useApproveReview() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ApprovePayload }) =>
      pricingReviewService.approve(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: [KEY] }),
  });
}

export function useSnoozeReview() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: SnoozePayload }) =>
      pricingReviewService.snooze(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: [KEY] }),
  });
}

export function useAssignReview() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: AssignPayload }) =>
      pricingReviewService.assign(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: [KEY] }),
  });
}

export function useBulkApprove() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: BulkApprovePayload) =>
      pricingReviewService.bulkApprove(payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: [KEY] }),
  });
}
