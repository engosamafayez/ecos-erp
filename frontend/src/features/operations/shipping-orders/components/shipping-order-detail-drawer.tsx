import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowUpRight, MapPin, Route } from 'lucide-react';

import { PageDrawer } from '@/components/page/drawer/page-drawer';
import { Button } from '@/components/ui/button';
import { ROUTES } from '@/router/routes';

import { ShippingOrderStatusBadge } from './shipping-order-status-badge';
import type { ShippingOrder } from '../types/shipping-order';

function formatMoney(n: number): string {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §12/§15 — row-detail drawer for
 * lower-priority information the table itself must stay lean without
 * ("do not overload the table"), plus the one real cross-surface link this
 * read model can support precisely: the order's own Trip (via the SAME
 * `trip.id` `ShippingOrderResource::resolveTrip()` exposes — see that
 * method's own docblock for why it can legitimately be null this early).
 *
 * No Driver/Group deep link here: `ShippingOrder.driver` carries a name/code
 * only (no id — Driver detail has no dedicated page today), and Group
 * context is not in this read model's join (adding it would be a new join
 * this task's own research found unnecessary for what §12 actually asks
 * for). Not a silent gap — Trip is the one link that is both requested
 * (§15) and cheaply, precisely available.
 */
export function ShippingOrderDetailDrawer({
  order,
  open,
  onOpenChange,
}: {
  order: ShippingOrder | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('shipping-orders');
  const navigate = useNavigate();

  return (
    <PageDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={order?.order_number ?? ''}
      size="lg"
    >
      {order ? (
        <div className="flex flex-col gap-5 p-1">
          <div className="flex flex-wrap items-center gap-2">
            <ShippingOrderStatusBadge classification={order.shipping_classification} />
            <span className="text-sm text-muted-foreground">
              {t($ => $.paymentStatus[order.payment_status])} · {formatMoney(order.order_value)}
            </span>
          </div>

          <section className="space-y-2">
            <h3 className="text-sm font-medium">{t($ => $.drawer.execution)}</h3>
            {order.trip ? (
              <div className="flex flex-col gap-2 rounded-lg border p-3">
                <div className="flex items-center justify-between gap-2">
                  <div className="flex items-center gap-2 text-sm">
                    <Route className="size-4 text-muted-foreground" aria-hidden />
                    <span className="font-medium">{order.trip.number}</span>
                    <span className="text-muted-foreground">
                      {t($ => $.drawer.stopPosition, {
                        sequence: order.trip.stop_sequence,
                        total: order.trip.stop_total,
                      })}
                    </span>
                  </div>
                  <Button
                    size="sm"
                    variant="outline"
                    className="h-7 gap-1 text-xs"
                    onClick={() => navigate(`${ROUTES.logisticsTrips}?tripId=${order.trip?.id}`)}
                    data-testid="shipping-order-open-trip"
                  >
                    {t($ => $.drawer.openTrip)}
                    <ArrowUpRight className="size-3" />
                  </Button>
                </div>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">{t($ => $.drawer.noTrip)}</p>
            )}
          </section>

          <section className="space-y-2">
            <h3 className="text-sm font-medium">{t($ => $.drawer.customer)}</h3>
            <div className="rounded-lg border p-3 text-sm">
              {order.customer ? (
                <>
                  <p className="font-medium">{order.customer.name}</p>
                  <p className="font-mono text-xs text-muted-foreground">{order.customer.code}</p>
                </>
              ) : (
                <p className="text-muted-foreground">—</p>
              )}
            </div>
          </section>

          <section className="space-y-2">
            <h3 className="text-sm font-medium">{t($ => $.columns.address)}</h3>
            <div className="flex items-start justify-between gap-2 rounded-lg border p-3 text-sm">
              <div className="space-y-0.5">
                <p>{order.address.shipping_address ?? '—'}</p>
                {order.address.building ? <p className="text-muted-foreground">{t($ => $.drawer.building, { value: order.address.building })}</p> : null}
                {order.address.floor ? <p className="text-muted-foreground">{t($ => $.drawer.floor, { value: order.address.floor })}</p> : null}
                {order.address.apartment ? <p className="text-muted-foreground">{t($ => $.drawer.apartment, { value: order.address.apartment })}</p> : null}
                {order.address.landmark ? <p className="text-muted-foreground">{order.address.landmark}</p> : null}
                {order.address.address_notes ? <p className="text-muted-foreground">{order.address.address_notes}</p> : null}
              </div>
              {order.location ? (
                <a
                  href={`https://www.google.com/maps?q=${order.location.lat},${order.location.lng}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="shrink-0 text-primary hover:underline"
                >
                  <MapPin className="size-4" />
                </a>
              ) : null}
            </div>
          </section>

          <section className="space-y-2">
            <h3 className="text-sm font-medium">{t($ => $.columns.shippingCompany)}</h3>
            <p className="text-sm">
              {order.shipping_company.type === 'internal'
                ? t($ => $.internalFleet)
                : (order.shipping_company.name ?? '—')}
            </p>
          </section>
        </div>
      ) : null}
    </PageDrawer>
  );
}
