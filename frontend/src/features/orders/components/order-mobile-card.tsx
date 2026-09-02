import { MapPin } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { MobileDataCard, type MobileDataCardField } from '@/components/mobile';

import { OrderConfirmationBadge } from './order-confirmation-badge';
import { OrderInventoryExecutionCell } from './order-inventory-execution-cell';
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
 * TASK-ECOS-MOBILE-INTEGRATION-CONFLICT-RESOLUTION-002 — this is the reconciled
 * merge of two independently-built versions of this exact component (the
 * Commerce lane's develop-side rebuild and the Mobile milestone's Task 3/5
 * version), per CTO arbitration. Neither side was discarded:
 *
 *   Commerce capabilities preserved: the MobileDataCard-based architecture,
 *   Warehouse, Driver, full street address (`addressSummary`), the Map action
 *   (`mapHref`), and `OrderPhoneCell` (the shared, canonical phone component).
 *
 *   Mobile capabilities preserved: the reservation/stock-execution status
 *   (`OrderInventoryExecutionCell` — TASK-005), the confirmation-call result
 *   (`OrderConfirmationBadge` — TASK-003), and the has-note indicator
 *   (TASK-005).
 *
 *   Money hierarchy — CTO-authoritative (integration-conflict-resolution §8):
 *   `grand_total` is the PRIMARY commercial figure (the card's main Total
 *   value); `remaining_balance` is the SECONDARY financial figure, always
 *   shown and explicitly labeled underneath it via the existing
 *   `columns.totalDue` caption — never collapsed into one ambiguous amount,
 *   never computed in the frontend beyond the same defensive fallback
 *   (`remaining_balance ?? grand_total - deposit_paid`) both original
 *   versions already used.
 *
 * Status changes are not offered inline here: MobileDataCard's title/status/fields
 * all render inside the card's single open-details tap target, so a second nested
 * interactive control (a Select trigger) isn't valid there. Tapping the card opens
 * the same order detail view used on desktop, whose Workflow tab is the one
 * canonical status-transition surface — avoiding a duplicate mobile-only
 * status-change implementation. Both original versions independently reached
 * this same conclusion.
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
  const hasNote = Boolean(order.customer_note || order.notes);

  const fields: MobileDataCardField[] = [
    {
      // PRIMARY commercial figure (grand_total) + SECONDARY financial figure
      // (remaining_balance), always both shown, explicitly labeled — never one
      // standing in for the other (CTO decision, TASK-002 §8).
      label: t($ => $.columns.total),
      align: 'end',
      value: (
        <div className="flex flex-col items-end leading-tight">
          <span>{formatTotal(order.grand_total)}</span>
          <span className="text-[11px] font-normal text-muted-foreground">
            {t($ => $.columns.totalDue, { amount: formatTotal(remaining) })}
          </span>
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
    {
      // Reservation/stock-execution status — the desktop "Inventory Execution"
      // column's own signal (distinct from the confirmation-call result below).
      // Self-handles the no-decision-yet case; never a second interpretation of
      // reservation_status.
      label: t($ => $.columns.inventoryExecution),
      value: (
        <OrderInventoryExecutionCell
          reservationStatus={order.reservation_status}
          failureReason={order.reservation_failure_reason}
        />
      ),
    },
    {
      label: t($ => $.confirmation.result),
      value: <OrderConfirmationBadge order={order} />,
    },
    {
      label: t($ => $.quickActions.notes),
      value: hasNote ? t($ => $.mobileCard.hasNote) : <span className="text-muted-foreground">—</span>,
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
