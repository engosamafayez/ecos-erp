import { useQuery } from '@tanstack/react-query';

import { OrderDetailDrawer } from '@/features/orders/components/order-detail-drawer';
import { ordersService } from '@/features/orders/services/orders-service';

/**
 * TASK-SHIPPING-DISTRIBUTION-WORKSPACE-COMPLETION-001 — PART 10/11.
 *
 * Distribution does NOT own an order-detail implementation. This is a thin
 * adapter: it fetches the canonical Order by id and hands it to the EXISTING
 * enterprise `OrderDetailDrawer` that the Orders workspace already uses, so an
 * order reviewed from Distribution shows exactly the same information — same
 * tabs, same financial fields, same customer profile — as everywhere else.
 *
 * No `DistributionOrderDrawer` exists, and none should: duplicating the drawer
 * is how two views of one entity drift apart (ADR-024).
 *
 * The drawer's own props are `{ order, open, onOpenChange, onEdit? }` and it
 * self-refetches fresh detail internally; all this adapter supplies is the
 * canonical object it expects.
 */
export function DistributionOrderDetail({
  orderId,
  open,
  onOpenChange,
}: {
  orderId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { data: order } = useQuery({
    queryKey: ['orders', 'detail', orderId],
    queryFn: () => ordersService.get(orderId as string),
    enabled: Boolean(orderId) && open,
  });

  return (
    <OrderDetailDrawer
      order={order ?? null}
      open={open}
      onOpenChange={onOpenChange}
      // Distribution never geocodes on open — resolution is an explicit action only
      // (TASK-DISTRIBUTION-MAP-EXPLICIT-GEOCODING-GATE-001).
      autoResolveLocation={false}
    />
  );
}
