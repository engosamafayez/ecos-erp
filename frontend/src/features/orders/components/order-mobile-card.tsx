import { MapPin } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { MobileDataCard, type MobileDataCardField } from '@/components/mobile';

import { OrderPhoneCell } from './order-phone-cell';
import { OrderStatusBadge } from './order-status-badge';
import type { Order } from '../types/order';

type OrderMobileCardProps = {
  order: Order;
  isSelected?: boolean;
  isFocused?: boolean;
  onView: (order: Order) => void;
  onSelect?: (id: string, checked: boolean) => void;
};

function formatTotal(total: number): string {
  return total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(d: string | null | undefined): string | null {
  if (!d) return null;
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

/**
 * Mobile-optimised card for a single order — built on the shared MobileDataCard
 * foundation (A6) rather than a second bespoke responsive system.
 *
 * Status changes are not offered inline here: MobileDataCard's title/status/fields
 * all render inside the card's single open-details tap target, so a second nested
 * interactive control (a Select trigger) isn't valid there. Tapping the card opens
 * the same order detail view used on desktop, whose Workflow tab is the one
 * canonical status-transition surface — avoiding a duplicate mobile-only
 * status-change implementation.
 */
export function OrderMobileCard({
  order,
  isSelected = false,
  isFocused = false,
  onView,
  onSelect,
}: OrderMobileCardProps) {
  const { t } = useTranslation('orders');
  const phone = order.billing_phone;

  const remaining = order.remaining_balance ?? (order.grand_total - (order.deposit_paid ?? 0));
  // F3 — include the street so the mobile summary carries the same delivery-point
  // specificity as the desktop grid's Address column, not just city/governorate.
  const addressSummary = [order.shipping_address, order.city, order.governorate].filter(Boolean).join(', ');
  const paymentMethod = order.payment_method_manual ?? order.payment_method;
  const scheduledDate = formatDate(order.requested_delivery_date);
  const mapHref = order.location ? `https://www.google.com/maps?q=${order.location.lat},${order.location.lng}` : null;

  const fields: MobileDataCardField[] = [
    {
      label: t($ => $.columns.total),
      align: 'end',
      value: (
        <div className="flex flex-col items-end leading-tight">
          <span>{formatTotal(order.grand_total)}</span>
          {remaining > 0 && remaining < order.grand_total ? (
            <span className="text-[11px] font-normal text-amber-600 dark:text-amber-400">
              {t($ => $.columns.totalDue, { amount: formatTotal(remaining) })}
            </span>
          ) : null}
        </div>
      ),
    },
    {
      label: t($ => $.mobileCard.payment),
      value: paymentMethod ? (
        <span className="capitalize">{paymentMethod}</span>
      ) : (
        <span className="text-muted-foreground">—</span>
      ),
    },
    {
      label: t($ => $.columns.address),
      value: addressSummary || <span className="text-muted-foreground">—</span>,
    },
    {
      label: t($ => $.columns.zone),
      value: order.delivery_zone ?? <span className="text-muted-foreground">—</span>,
    },
    {
      label: t($ => $.mobileCard.delivery),
      value: scheduledDate ?? <span className="text-muted-foreground">—</span>,
    },
    {
      label: t($ => $.mobileCard.warehouse),
      value: order.assigned_warehouse?.name ?? <span className="text-muted-foreground">—</span>,
    },
    {
      label: t($ => $.columns.driver),
      value: order.driver?.full_name ?? <span className="text-muted-foreground">{t($ => $.columns.driverUnassigned)}</span>,
    },
  ];

  return (
    <MobileDataCard
      title={
        <span className="flex items-center gap-1.5">
          <span className="font-mono">{order.order_number}</span>
          {order.channel?.name ? (
            <span className="truncate font-normal text-muted-foreground">· {order.channel.name}</span>
          ) : null}
        </span>
      }
      subtitle={order.customer?.name ?? '—'}
      status={<OrderStatusBadge status={order.status} />}
      fields={fields}
      selected={isSelected}
      onSelect={onSelect ? (checked) => onSelect(order.id, checked) : undefined}
      selectLabel={t($ => $.mobileCard.selectOrder, { number: order.order_number })}
      focused={isFocused}
      onOpen={() => onView(order)}
      openLabel={t($ => $.mobileCard.viewOrder, { number: order.order_number })}
      actions={
        phone || mapHref ? (
          <>
            {phone ? (
              <div onClick={(e) => e.stopPropagation()} onMouseDown={(e) => e.stopPropagation()}>
                <OrderPhoneCell phone={phone} variant="icon" ariaLabel={t($ => $.columns.phone)} />
              </div>
            ) : null}
            {mapHref ? (
              <Button variant="ghost" size="icon" className="size-7" asChild>
                <a
                  href={mapHref}
                  target="_blank"
                  rel="noopener noreferrer"
                  aria-label={t($ => $.drawer.shipping.openMap)}
                  onClick={(e) => e.stopPropagation()}
                >
                  <MapPin className="size-3.5" />
                </a>
              </Button>
            ) : null}
          </>
        ) : undefined
      }
    />
  );
}
