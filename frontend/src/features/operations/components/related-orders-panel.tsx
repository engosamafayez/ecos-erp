import { useState, type ReactNode } from 'react';
import { CalendarClock, Loader2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { toast } from '@/components/ds/use-toast';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import { usePostponeWaveOrder } from '../hooks/use-preparation';
import type { RelatedOrderBase } from '../types/preparation';

/**
 * Related Orders — ONE panel, used by both Product Demand and Missing Materials.
 *
 * Both tabs open the same component so the two drill-downs cannot drift apart in
 * layout, fields or behaviour; only the extra per-context columns differ, and those
 * arrive through `extraColumns`.
 *
 * Postpone goes through `usePostponeWaveOrder` — the SAME canonical mutation the Orders
 * tab uses, hitting the same endpoint, with the same confirmation copy. No postponement
 * rule is restated here: the row is never hidden locally, and the hook invalidates the
 * whole wave-demand scope, so the order leaves this list only because the backend
 * stopped returning it. Required, Remaining, Missing Materials, the orders count and
 * the wave KPIs all re-read from the server in the same pass.
 *
 * `OrderStatusBadge` is imported from the Orders feature rather than re-implemented, so
 * a status renders identically in Preparation and in the Orders workspace.
 */
export function RelatedOrdersPanel<T extends RelatedOrderBase>({
  open,
  onOpenChange,
  title,
  entityName,
  entitySubtitle,
  waveId,
  orders,
  isLoading,
  emptyLabel,
  extraColumns,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  entityName?: string;
  entitySubtitle?: string | null;
  waveId: string | null;
  orders: T[] | undefined;
  isLoading: boolean;
  emptyLabel: string;
  /** Context columns: Required for a product, Product + Material Qty for a material. */
  extraColumns: Array<{ key: string; header: string; cell: (order: T) => ReactNode; align?: 'start' | 'end' }>;
}) {
  const { t } = useTranslation('operations');
  const postpone = usePostponeWaveOrder();
  const [pending, setPending] = useState<T | null>(null);

  const rows = orders ?? [];

  function confirmPostpone() {
    if (!pending || !waveId) return;
    const order = pending;
    setPending(null);
    postpone.mutate(
      { waveId, orderId: order.order_id },
      {
        onSuccess: () => toast.success(t($ => $.wave.orders.postpone.success)),
        onError: () => toast.error(t($ => $.wave.orders.postpone.error)),
      },
    );
  }

  function addressOf(o: T): string | null {
    // Deliberately not padded with a fallback: an order with no address recorded shows
    // an em dash, it does not borrow the governorate and pretend to be an address.
    const parts = [o.shipping_address, o.city, o.governorate].filter(Boolean);
    return parts.length > 0 ? parts.join(' · ') : null;
  }

  return (
    <>
      <Sheet open={open} onOpenChange={onOpenChange}>
        {/* ~60% of the viewport on desktop, full width on mobile. */}
        <SheetContent className="w-full overflow-y-auto sm:!max-w-none sm:w-[60vw]">
          <SheetHeader>
            <SheetTitle>{title}</SheetTitle>
          </SheetHeader>

          {entityName && (
            <div className="mt-1 mb-3">
              <p className="text-sm font-medium">{entityName}</p>
              {entitySubtitle && (
                <p className="text-[11px] font-mono text-muted-foreground">{entitySubtitle}</p>
              )}
            </div>
          )}

          {isLoading ? (
            <div className="flex items-center gap-2 py-8 text-muted-foreground">
              <Loader2 className="size-4 animate-spin" />
              <span className="text-sm">{t($ => $.wave.loading)}</span>
            </div>
          ) : rows.length === 0 ? (
            <p className="py-8 text-sm text-muted-foreground">{emptyLabel}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-xs text-muted-foreground">
                    <th className="py-2 pe-3 text-start font-medium">{t($ => $.wave.orders.columns.orderNo)}</th>
                    <th className="py-2 pe-3 text-start font-medium">{t($ => $.wave.orders.columns.customer)}</th>
                    <th className="py-2 pe-3 text-start font-medium">{t($ => $.wave.relatedOrders.status)}</th>
                    <th className="py-2 pe-3 text-start font-medium">{t($ => $.wave.orders.columns.deliveryZone)}</th>
                    <th className="py-2 pe-3 text-start font-medium">{t($ => $.wave.relatedOrders.address)}</th>
                    <th className="py-2 pe-3 text-start font-medium">{t($ => $.wave.orders.columns.payment)}</th>
                    <th className="py-2 pe-3 text-end font-medium">{t($ => $.wave.relatedOrders.total)}</th>
                    {extraColumns.map((c) => (
                      <th
                        key={c.key}
                        className={`py-2 pe-3 font-medium ${c.align === 'end' ? 'text-end' : 'text-start'}`}
                      >
                        {c.header}
                      </th>
                    ))}
                    <th className="py-2 text-end font-medium">{t($ => $.wave.orders.columns.actions)}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((o) => (
                    <tr key={o.order_id} className="border-b border-border/40 last:border-0 align-top">
                      <td className="py-2 pe-3 font-mono text-xs font-medium whitespace-nowrap">{o.order_number}</td>
                      <td className="py-2 pe-3">
                        {o.customer_name ?? <span className="text-muted-foreground">&mdash;</span>}
                      </td>
                      <td className="py-2 pe-3 whitespace-nowrap">
                        <OrderStatusBadge status={o.status} />
                      </td>
                      <td className="py-2 pe-3">
                        {o.delivery_zone ?? (
                          <Badge variant="outline" className="text-[11px] font-normal text-muted-foreground">
                            {t($ => $.wave.orders.unassignedZone)}
                          </Badge>
                        )}
                      </td>
                      <td className="py-2 pe-3 text-xs text-muted-foreground max-w-[180px]">
                        {addressOf(o) ?? <span>&mdash;</span>}
                      </td>
                      <td className="py-2 pe-3 text-xs">
                        {o.payment_status ?? <span className="text-muted-foreground">&mdash;</span>}
                      </td>
                      <td className="py-2 pe-3 text-end tabular-nums whitespace-nowrap">
                        {o.total != null
                          ? o.total.toLocaleString(undefined, { maximumFractionDigits: 2 })
                          : <span className="text-muted-foreground">&mdash;</span>}
                      </td>
                      {extraColumns.map((c) => (
                        <td
                          key={c.key}
                          className={`py-2 pe-3 ${c.align === 'end' ? 'text-end tabular-nums' : 'text-start'}`}
                        >
                          {c.cell(o)}
                        </td>
                      ))}
                      <td className="py-2 text-end">
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 gap-1.5 text-xs whitespace-nowrap"
                          title={t($ => $.wave.orders.postpone.tooltip)}
                          disabled={!waveId || postpone.isPending}
                          onClick={() => setPending(o)}
                        >
                          <CalendarClock className="size-3.5" />
                          {t($ => $.wave.orders.postpone.action)}
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </SheetContent>
      </Sheet>

      {/* Same confirmation the Orders tab shows — postponement is one decision with one
          meaning, wherever the operator triggers it from. */}
      <AlertDialog open={pending !== null} onOpenChange={(o) => { if (!o) setPending(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t($ => $.wave.orders.postpone.title)}</AlertDialogTitle>
            <AlertDialogDescription>{t($ => $.wave.orders.postpone.body)}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t($ => $.wave.orders.postpone.cancel)}</AlertDialogCancel>
            <AlertDialogAction onClick={confirmPostpone}>
              {t($ => $.wave.orders.postpone.confirm)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
