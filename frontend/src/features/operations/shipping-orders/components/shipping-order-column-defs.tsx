import { MapPin } from 'lucide-react';
import type { TFunction } from 'i18next';

import type { DataGridColumnDef } from '@/components/data-grid/types';
import { mapsUrl } from '@/features/orders/components/order-address-cell';
import { ShippingOrderStatusBadge } from './shipping-order-status-badge';
import type { ShippingOrder } from '../types/shipping-order';

function formatMoney(n: number): string {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** §23 — the full delivery address, never truncated to a hover-only value. */
function formatAddress(order: ShippingOrder, t: TFunction<'shipping-orders'>): string {
  const a = order.address;
  return [
    a.shipping_address,
    a.building && t($ => $.drawer.building, { value: a.building }),
    a.floor && t($ => $.drawer.floor, { value: a.floor }),
    a.apartment && t($ => $.drawer.apartment, { value: a.apartment }),
    a.landmark, a.area, a.city, a.governorate, a.address_notes,
  ]
    .filter((v): v is string => Boolean(v))
    .join(', ');
}

export function createShippingOrderColumns(t: TFunction<'shipping-orders'>): DataGridColumnDef<ShippingOrder>[] {
  return [
    {
      key: 'order_number',
      label: t($ => $.columns.orderNumber),
      alwaysVisible: true,
      pin: 'left',
      width: 130,
      cell: (o) => <span className="font-mono text-xs font-medium">{o.order_number}</span>,
    },
    {
      key: 'brand',
      label: t($ => $.columns.brand),
      defaultVisible: true,
      cell: (o) => o.brand
        ? <span className="text-xs">{o.brand.name}</span>
        : <span className="text-xs text-muted-foreground">—</span>,
    },
    {
      key: 'customer',
      label: t($ => $.columns.customer),
      defaultVisible: true,
      width: 200,
      cell: (o) => o.customer ? (
        <div className="flex flex-col gap-0.5">
          <span className="text-xs font-medium">{o.customer.name}</span>
          <span className="font-mono text-[10px] text-muted-foreground">{o.customer.code}</span>
        </div>
      ) : <span className="text-xs text-muted-foreground">—</span>,
    },
    {
      key: 'order_value',
      label: t($ => $.columns.orderValue),
      defaultVisible: true,
      cell: (o) => (
        <div className="flex flex-col gap-0.5">
          <span className="text-xs font-semibold tabular-nums">{formatMoney(o.order_value)}</span>
          <span className="text-[10px] text-muted-foreground">{t(($) => $.paymentStatus[o.payment_status])}</span>
        </div>
      ),
    },
    {
      key: 'shipping_classification',
      label: t($ => $.columns.shippingStatus),
      defaultVisible: true,
      cell: (o) => <ShippingOrderStatusBadge classification={o.shipping_classification} />,
    },
    {
      // TASK-ECOS-SHIPPING-OS-REDESIGN-003 §12 — concise execution context (Trip +
      // stop position), from the SAME joined read model every other column already
      // reads (ShippingOrderResource::resolveTrip()) — not a second data source.
      // Deliberately terse (one line, no extra chrome): full Trip detail and the
      // "Open Trip" deep link live in the row drawer, per this section's own "use
      // row details/drawer for lower-priority information" guidance.
      key: 'trip',
      label: t($ => $.columns.trip),
      defaultVisible: true,
      cell: (o) => o.trip ? (
        <span className="text-xs">
          <span className="font-mono font-medium">{o.trip.number}</span>
          <span className="text-muted-foreground"> · {o.trip.stop_sequence}/{o.trip.stop_total}</span>
        </span>
      ) : <span className="text-xs text-muted-foreground">—</span>,
    },
    {
      key: 'shipping_company',
      label: t($ => $.columns.shippingCompany),
      defaultVisible: true,
      cell: (o) => o.shipping_company.type === 'internal'
        ? <span className="text-xs text-muted-foreground">{t(($) => $.internalFleet)}</span>
        : <span className="text-xs">{o.shipping_company.name ?? '—'}</span>,
    },
    {
      key: 'driver',
      label: t($ => $.columns.driver),
      defaultVisible: true,
      width: 160,
      cell: (o) => o.driver ? (
        <div className="flex flex-col gap-0.5">
          <span className="text-xs font-medium">{o.driver.name}</span>
          <span className="font-mono text-[10px] text-muted-foreground">{o.driver.code}</span>
        </div>
      ) : <span className="text-xs text-muted-foreground">—</span>,
    },
    {
      key: 'address',
      label: t($ => $.columns.address),
      defaultVisible: true,
      width: 320,
      cell: (o) => {
        const full = formatAddress(o, t);
        const href = mapsUrl(o.location, full);
        return (
          <div className="flex items-start gap-2">
            <span className="text-xs whitespace-normal break-words flex-1">{full || '—'}</span>
            {o.location ? (
              <a
                href={href}
                target="_blank"
                rel="noopener noreferrer"
                onClick={(e) => e.stopPropagation()}
                className="shrink-0 text-primary hover:underline"
                title={t(($) => $.viewLocation)}
              >
                <MapPin className="size-3.5" />
              </a>
            ) : (
              <span className="shrink-0 text-muted-foreground/50" title={t(($) => $.noLocation)}>
                <MapPin className="size-3.5" />
              </span>
            )}
          </div>
        );
      },
    },
  ];
}
