import { keepPreviousData, useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';

import { ordersService } from '@/features/orders/services/orders-service';
import type { OrderPayload, OrderStatus, OrderStatusCounts, OrdersQuery } from '@/features/orders/types/order';
import { STATUS_TAB_ORDER } from '@/features/orders/types/order';

export type CustomerOrderStats = {
  total: number;
  completed: number;
  cancelled: number;
  totalSpend: number;
  lastOrderDate: string | null;
};

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

/** Per-status order counts for Status Tabs. Runs 15 parallel queries (1 per tab). */
export function useOrderStatusCounts(): OrderStatusCounts {
  const tabs = STATUS_TAB_ORDER; // ['all', 'processing', ...]

  const results = useQueries({
    queries: tabs.map((tab) => ({
      queryKey: [ORDERS_KEY, 'count', tab],
      queryFn: () =>
        ordersService.list({
          ...(tab !== 'all' ? { status: tab as OrderStatus } : {}),
          per_page: 1,
          page: 1,
        }),
      staleTime: 30_000,
    })),
  });

  const counts: OrderStatusCounts = {};
  tabs.forEach((tab, i) => {
    counts[tab as OrderStatus | 'all'] = results[i]?.data?.meta.total ?? 0;
  });

  return counts;
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

/** Fetch order history for a single customer to power the customer badge popup. */
export function useCustomerOrderStats(customerId: string | null) {
  return useQuery<CustomerOrderStats | null>({
    queryKey: [ORDERS_KEY, 'customer-stats', customerId],
    queryFn: async () => {
      if (!customerId) return null;
      const result = await ordersService.list({ customer_id: customerId, per_page: 200 });
      const items = result.items;
      return {
        total: result.meta.total,
        completed: items.filter((o) => o.status === 'completed').length,
        cancelled: items.filter((o) => o.status === 'cancelled').length,
        totalSpend: items.reduce((sum, o) => sum + o.total, 0),
        lastOrderDate: items[0]?.order_date ?? null,
      };
    },
    enabled: Boolean(customerId),
    staleTime: 30_000,
  });
}
