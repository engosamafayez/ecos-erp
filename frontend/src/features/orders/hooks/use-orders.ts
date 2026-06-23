import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ordersService } from '@/features/orders/services/orders-service';
import type { OrderPayload, OrdersQuery } from '@/features/orders/types/order';

export const ORDERS_KEY = 'orders';

export function useOrdersQuery(params: OrdersQuery) {
  return useQuery({
    queryKey: [ORDERS_KEY, params],
    queryFn: () => ordersService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useOrderQuery(id: string) {
  return useQuery({
    queryKey: [ORDERS_KEY, id],
    queryFn: () => ordersService.get(id),
    enabled: Boolean(id),
  });
}

export function useCreateOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: OrderPayload) => ordersService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [ORDERS_KEY] }),
  });
}

export function useUpdateOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: OrderPayload }) =>
      ordersService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [ORDERS_KEY] }),
  });
}

export function useDeleteOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => ordersService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [ORDERS_KEY] }),
  });
}
