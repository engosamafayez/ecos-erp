import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { fulfillmentsService } from '@/features/fulfillments/services/fulfillments-service';
import type { FulfillmentPayload, FulfillmentsQuery } from '@/features/fulfillments/types/fulfillment';

export const FULFILLMENTS_KEY = 'fulfillments';

export function useFulfillmentsQuery(params: FulfillmentsQuery) {
  return useQuery({
    queryKey: [FULFILLMENTS_KEY, params],
    queryFn: () => fulfillmentsService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useFulfillmentQuery(id: string) {
  return useQuery({
    queryKey: [FULFILLMENTS_KEY, id],
    queryFn: () => fulfillmentsService.get(id),
    enabled: Boolean(id),
  });
}

export function useCreateFulfillment() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: FulfillmentPayload) => fulfillmentsService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [FULFILLMENTS_KEY] }),
  });
}

export function useDeleteFulfillment() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => fulfillmentsService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [FULFILLMENTS_KEY] }),
  });
}

export function useFulfillFulfillment() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => fulfillmentsService.fulfill(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [FULFILLMENTS_KEY] });
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] });
    },
  });
}

export function useCancelFulfillment() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => fulfillmentsService.cancel(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [FULFILLMENTS_KEY] }),
  });
}
