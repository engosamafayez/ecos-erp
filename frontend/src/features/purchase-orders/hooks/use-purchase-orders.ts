import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { purchaseOrdersService } from '@/features/purchase-orders/services/purchase-orders-service';
import type {
  PurchaseOrderPayload,
  PurchaseOrdersQuery,
} from '@/features/purchase-orders/types/purchase-order';

export const PO_KEY = 'purchase-orders';

export function usePurchaseOrdersQuery(params: PurchaseOrdersQuery) {
  return useQuery({
    queryKey: [PO_KEY, params],
    queryFn: () => purchaseOrdersService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function usePurchaseOrderQuery(id: string) {
  return useQuery({
    queryKey: [PO_KEY, id],
    queryFn: () => purchaseOrdersService.get(id),
    enabled: Boolean(id),
  });
}

export function useCreatePurchaseOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: PurchaseOrderPayload) => purchaseOrdersService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PO_KEY] }),
  });
}

export function useUpdatePurchaseOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: PurchaseOrderPayload }) =>
      purchaseOrdersService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PO_KEY] }),
  });
}

export function useDeletePurchaseOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => purchaseOrdersService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PO_KEY] }),
  });
}

export function useApprovePurchaseOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => purchaseOrdersService.approve(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PO_KEY] }),
  });
}

export function useCancelPurchaseOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => purchaseOrdersService.cancel(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PO_KEY] }),
  });
}

export function useSubmitPurchaseOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => purchaseOrdersService.submit(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PO_KEY] }),
  });
}
