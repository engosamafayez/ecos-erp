import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

import {
  useDistributionOrders,
  useMoveOrderToSlot,
} from '../hooks/use-distribution-workspace';
import { DistributionOrderDetail } from './distribution-order-detail';
import type {
  DistributionOrder,
  DistributionWindow,
  SlotSummary,
  ZoneSummary,
} from '../types';

type Props = {
  window: DistributionWindow;
  zone: ZoneSummary;
  slots: SlotSummary[];
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

/**
 * Zone detail — every Order in the zone, and the per-Order slot move.
 *
 * The move sends ONE assignment to the API. The backend writes `virtual_slot_id`
 * only, so the Order's Zone and its Warehouse are untouched; peers in the same
 * zone are unaffected. On success the whole workspace query root is invalidated,
 * which is what refreshes source slot, destination slot, zone summary, capacity
 * and the KPI row together.
 */
export function ZoneOrdersDrawer({ window, zone, slots, open, onOpenChange }: Props) {
  // The drawer must be scoped to the same warehouse as the board behind it,
  // otherwise opening a zone would reveal another warehouse's orders inside a
  // warehouse-scoped screen.
  const { activeWarehouseId } = useOrganizationContext();
  const { t } = useTranslation('logistics');

  const { data: orders, isLoading, isError } = useDistributionOrders(
    window.id,
    activeWarehouseId,
    zone.zone_id,
    null,
    open,
  );
  const move = useMoveOrderToSlot();
  const [movingOrder, setMovingOrder] = useState<DistributionOrder | null>(null);
  const [detailOrderId, setDetailOrderId] = useState<string | null>(null);

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-2xl" data-testid="zone-orders-drawer">
        <SheetHeader>
          <SheetTitle>
            {zone.zone_name ?? t(($) => $.distributionWorkspace.zoneOrders.unzoned)}
          </SheetTitle>
          <SheetDescription>
            {t(($) => $.distributionWorkspace.zoneOrders.summary, {
              count: zone.order_count,
              value: zone.total_value.toLocaleString(),
            })}
            {zone.spans_slots ? t(($) => $.distributionWorkspace.zoneOrders.spansSlots) : ''}
          </SheetDescription>
        </SheetHeader>

        {isLoading ? (
          <div className="mt-4 space-y-2">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-16 w-full rounded-lg" />
            ))}
          </div>
        ) : isError ? (
          <p className="mt-6 text-sm text-red-600" data-testid="zone-orders-error">
            {t(($) => $.distributionWorkspace.zoneOrders.loadFailed)}
          </p>
        ) : !orders || orders.length === 0 ? (
          <p className="mt-6 text-sm text-muted-foreground" data-testid="zone-orders-empty">
            {t(($) => $.distributionWorkspace.zoneOrders.empty)}
          </p>
        ) : (
          <ul className="mt-4 space-y-2" data-testid="zone-orders-list">
            {orders.map((order) => {
              const slot = slots.find((s) => s.slot_id === order.virtual_slot_id);
              return (
                <li
                  key={order.assignment_id}
                  className="rounded-lg border p-3"
                  data-testid={`order-row-${order.order_number}`}
                >
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        {/* Opens the EXISTING enterprise Order drawer — Distribution
                            owns no order-detail implementation of its own. */}
                        <button
                          type="button"
                          className="font-medium underline-offset-2 hover:underline"
                          onClick={() => setDetailOrderId(order.order_id)}
                          data-testid={`open-order-${order.order_number}`}
                        >
                          {order.order_number}
                        </button>
                        <Badge variant="outline">{order.order_status}</Badge>
                        {order.assignment_source === 'manual_late' ? (
                          <Badge variant="secondary">
                            {t(($) => $.distributionWorkspace.zoneOrders.late)}
                          </Badge>
                        ) : null}
                      </div>
                      <div className="mt-1 text-sm text-muted-foreground">
                        {order.customer_name ?? '—'}
                        {order.phone ? ` · ${order.phone}` : ''}
                      </div>
                      <div className="mt-1 text-xs text-muted-foreground">
                        {t(($) => $.distributionWorkspace.zoneOrders.slotLine, {
                          slot: slot
                            ? (slot.name ?? slot.code)
                            : t(($$) => $$.distributionWorkspace.zoneOrders.slotUnassigned),
                          method:
                            order.payment_method ??
                            t(($$) => $$.distributionWorkspace.zoneOrders.noPaymentMethod),
                          value: order.total.toLocaleString(),
                        })}
                      </div>
                    </div>

                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => setMovingOrder(order)}
                      disabled={!window.accepts_manual_assignment}
                      data-testid={`move-order-${order.order_number}`}
                      title={
                        window.accepts_manual_assignment
                          ? t(($) => $.distributionWorkspace.zoneOrders.moveTitle)
                          : t(($) => $.distributionWorkspace.zoneOrders.moveClosedTitle)
                      }
                    >
                      {t(($) => $.distributionWorkspace.zoneOrders.move)}
                    </Button>
                  </div>

                  {movingOrder?.assignment_id === order.assignment_id ? (
                    <div className="mt-3 rounded-md bg-muted/50 p-3" data-testid="move-order-panel">
                      <p className="text-xs text-muted-foreground">
                        {t(($) => $.distributionWorkspace.zoneOrders.moveHint)}
                      </p>
                      <div className="mt-2 space-y-1">
                        {slots
                          .filter((s) => s.slot_id !== order.virtual_slot_id)
                          .map((s) => (
                            <button
                              key={s.slot_id}
                              type="button"
                              className="flex w-full items-center justify-between rounded-md border px-3 py-2 text-left text-sm hover:bg-accent disabled:opacity-50"
                              disabled={move.isPending}
                              data-testid={`move-target-${s.code}`}
                              onClick={() =>
                                move.mutate(
                                  {
                                    assignmentId: order.assignment_id,
                                    slotId: s.slot_id,
                                    reason: 'Manual move from Distribution Workspace',
                                  },
                                  { onSuccess: () => setMovingOrder(null) },
                                )
                              }
                            >
                              <span>{s.name ?? s.code}</span>
                              <span className="text-xs text-muted-foreground">
                                {s.demand_orders}
                                {s.capacity_orders !== null ? `/${s.capacity_orders}` : ''}
                                {s.is_over_capacity
                                  ? t(($) => $.distributionWorkspace.zoneOrders.overCapacity)
                                  : ''}
                                {s.is_warning
                                  ? t(($) => $.distributionWorkspace.zoneOrders.nearLimit)
                                  : ''}
                              </span>
                            </button>
                          ))}
                      </div>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="mt-2"
                        onClick={() => setMovingOrder(null)}
                      >
                        {t(($) => $.common.cancel)}
                      </Button>
                    </div>
                  ) : null}
                </li>
              );
            })}
          </ul>
        )}
      </SheetContent>

      <DistributionOrderDetail
        orderId={detailOrderId}
        open={Boolean(detailOrderId)}
        onOpenChange={(o) => !o && setDetailOrderId(null)}
      />
    </Sheet>
  );
}
