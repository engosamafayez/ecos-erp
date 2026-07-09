import { useQuery } from '@tanstack/react-query';

import { ordersService } from '@/features/orders/services/orders-service';
import type { OrderFinancialSnapshot } from '@/features/orders/types/order';

/**
 * Fetches the immutable financial snapshot for an order.
 * staleTime is Infinity — snapshots are write-once and never change.
 */
export function useOrderSnapshot(orderId: string | null | undefined): {
  snapshot: OrderFinancialSnapshot | null | undefined;
  isLoading: boolean;
} {
  const query = useQuery({
    queryKey: ['order-snapshot', orderId],
    queryFn: () => ordersService.snapshot(orderId!),
    enabled: Boolean(orderId),
    staleTime: Infinity,
  });

  return { snapshot: query.data, isLoading: query.isLoading };
}
