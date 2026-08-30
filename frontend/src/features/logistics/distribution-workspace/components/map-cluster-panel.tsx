import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';

import type { DistributionOrder, MapOrder } from '../types';

/**
 * TASK-DISTRIBUTION-MAP-COMPLETE-UX-CORRECTION-001 — Problem B, §9.
 *
 * The panel an AGGREGATED marker opens: several orders share one exact delivery
 * coordinate, so the map draws a single marker and this lists EVERY order behind it —
 * none hidden because it shares a point. It is a routing surface, not a second
 * order-detail implementation: selecting a row hands off to the existing per-order
 * panel (`MapOrderPanel` → the canonical drawer). Zone/group membership is untouched;
 * the orders here may belong to different groups and each keeps its own.
 */
export function MapClusterPanel({
  orders,
  ordersById,
  onOpenChange,
  onOpenOrder,
}: {
  /** Every order at the clicked coordinate. Null keeps the panel closed. */
  orders: MapOrder[] | null;
  /** The window's canonical orders, for each row's status. */
  ordersById: Map<string, DistributionOrder>;
  onOpenChange: (open: boolean) => void;
  /** Open one order's full detail — the existing per-order panel/drawer. */
  onOpenOrder: (order: MapOrder) => void;
}) {
  const { t } = useTranslation('logistics');

  const open = orders !== null && orders.length > 0;
  const count = orders?.length ?? 0;

  return (
    <Sheet open={open} onOpenChange={(next) => (next ? undefined : onOpenChange(false))}>
      <SheetContent
        className="w-full overflow-y-auto sm:max-w-md"
        data-testid="map-cluster-panel"
      >
        <SheetHeader>
          <SheetTitle>{t(($) => $.distributionWorkspace.map.cluster.title, { count })}</SheetTitle>
          <SheetDescription>
            {t(($) => $.distributionWorkspace.map.cluster.subtitle)}
          </SheetDescription>
        </SheetHeader>

        <ul className="mt-4 divide-y rounded-lg border" data-testid="map-cluster-list">
          {(orders ?? []).map((o) => {
            const detail = ordersById.get(o.order_id);
            const status = detail?.order_status ?? null;

            return (
              <li key={o.order_id}>
                <button
                  type="button"
                  onClick={() => onOpenOrder(o)}
                  className="flex w-full items-center gap-3 px-3 py-2.5 text-start transition-colors hover:bg-muted"
                  data-testid={`map-cluster-order-${o.order_number ?? o.order_id}`}
                >
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium" dir="ltr">
                      {o.order_number ?? o.order_id}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                      {detail?.customer_name ?? o.customer_name ?? '—'}
                    </p>
                  </div>
                  {status ? (
                    <Badge variant="outline" className="shrink-0">
                      {status}
                    </Badge>
                  ) : null}
                </button>
              </li>
            );
          })}
        </ul>
      </SheetContent>
    </Sheet>
  );
}
