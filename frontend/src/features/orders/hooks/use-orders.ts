import { keepPreviousData, useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';

import { ordersService } from '@/features/orders/services/orders-service';
import type {
  CustomerLookupResult,
  ManualOrderPayload,
  OrderPayload,
  OrderStatus,
  OrderStatusCounts,
  OrdersQuery,
  ShippingCalcResult,
  ShippingPricingRule,
} from '@/features/orders/types/order';
import { STATUS_TAB_ORDER } from '@/features/orders/types/order';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

export type CustomerOrderStats = {
  total: number;
  completed: number;
  cancelled: number;
  totalSpend: number;
  lastOrderDate: string | null;
};

export const ORDERS_KEY = 'orders';

export function useOrdersQuery(params: OrdersQuery) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, ORDERS_KEY, params],
    queryFn: () => ordersService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useOrderQuery(id: string) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery({
    queryKey: ['company', companyId, ORDERS_KEY, id],
    queryFn: () => ordersService.get(id),
    enabled: Boolean(id),
  });
}

/** Per-status order counts for Status Tabs. Runs 15 parallel queries (1 per tab). */
export function useOrderStatusCounts(): OrderStatusCounts {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const tabs = STATUS_TAB_ORDER; // ['all', 'processing', ...]

  const results = useQueries({
    queries: tabs.map((tab) => ({
      queryKey: ['company', companyId, ORDERS_KEY, 'count', tab],
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
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: OrderPayload) => ordersService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, ORDERS_KEY] }),
  });
}

export function useUpdateOrder() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: OrderPayload }) =>
      ordersService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, ORDERS_KEY] }),
  });
}

export function useCreateManualOrder() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: ManualOrderPayload) => ordersService.createManual(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, ORDERS_KEY] }),
  });
}

export function useCustomerByPhone(phone: string) {
  return useQuery<CustomerLookupResult>({
    queryKey: ['customer-by-phone', phone],
    queryFn: () => ordersService.searchByPhone(phone),
    enabled: phone.length >= 8,
    staleTime: 30_000,
  });
}

export function usePatchOrder() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Record<string, unknown> }) =>
      ordersService.patchOrder(id, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, ORDERS_KEY] }),
  });
}

export function useAllShippingRules() {
  return useQuery<ShippingPricingRule[]>({
    queryKey: ['shipping-rules-all'],
    queryFn: () => ordersService.listShippingRules(),
    staleTime: 5 * 60_000,
  });
}

export function useCalculateShipping(params: { governorate: string; city?: string; area?: string }) {
  return useQuery<ShippingCalcResult>({
    queryKey: ['shipping-calc', params.governorate, params.city ?? '', params.area ?? ''],
    queryFn: () => ordersService.calculateShipping(params),
    enabled: Boolean(params.governorate),
    staleTime: 60_000,
  });
}

export function useDeleteOrder() {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => ordersService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company', companyId, ORDERS_KEY] }),
  });
}

/** Fetch order history for a single customer to power the customer badge popup. */
export function useCustomerOrderStats(customerId: string | null) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';
  return useQuery<CustomerOrderStats | null>({
    queryKey: ['company', companyId, ORDERS_KEY, 'customer-stats', customerId],
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
