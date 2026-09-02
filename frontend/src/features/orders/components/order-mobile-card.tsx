import { FileText, MapPin } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { MobileDataCard, type MobileDataCardField } from '@/components/mobile';

import { OrderConfirmationBadge } from './order-confirmation-badge';
import { OrderInventoryExecutionCell } from './order-inventory-execution-cell';
import { OrderPaymentCell } from './order-payment-cell';
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
 * TASK-ECOS-MOBILE-COMMERCE-SCREENS-UX-REFINEMENT-001 — density/hierarchy pass
 * requested after the User's DEV review of the integrated card
 * (TASK-ECOS-MOBILE-INTEGRATION-CONFLICT-RESOLUTION-002). No capability from
 * that merge was dropped — every field below still surfaces the same data,
 * just regrouped for a shorter card and a clearer scan order:
 *
 *   Header hierarchy (order number → brand → customer → status): the order
 *   number keeps its own line with the owning Brand named right next to it
 *   (`channel.brand`, resolved server-side — TASK-022 gap, see OrderResource),
 *   replacing the lower-value Channel name that used to sit there. The
 *   customer's name moves off the small muted subtitle style onto a
 *   foreground/semibold treatment so it scans as clearly as the order number
 *   above it. The status badge still sits at the top-end corner via
 *   MobileDataCard's own layout — never a second, competing status control.
 *
 *   Field consolidation (10 fields/5 rows → 8 fields/4 rows, zero data loss):
 *   delivery Zone is now a `· ` suffix on the Address value instead of its own
 *   row (they describe the same delivery point), and the has-note indicator is
 *   a small icon appended to the Confirmation Result value instead of its own
 *   row (it was always a supplementary flag, never a primary field). Warehouse
 *   keeps its own row, now rendered `font-medium` — the prior default weight
 *   read too close to its own muted label to scan as a value.
 *
 *   Payment Method now reuses the canonical `OrderPaymentCell` (the exact
 *   component the desktop column already renders) instead of a raw,
 *   under-scored `payment_method_manual` string — "mobile_wallet" is genuinely
 *   unreadable; `OrderPaymentCell`'s own method map already turns it into a
 *   short, human label ("Wallet"/"COD"/"Instapay"/...), with permission-gated
 *   inline editing coming along for free from the same shared component.
 *
 *   Money hierarchy — unchanged from the CTO-authoritative decision
 *   (TASK-002 §8): `grand_total` stays the PRIMARY figure, `remaining_balance`
 *   the always-shown, explicitly-labeled secondary figure. What changed is the
 *   RTL rendering only — `MobileDataCard`'s label now shares the value's own
 *   'end' alignment (see mobile-data-card.tsx) so the two no longer visually
 *   detach from each other in RTL.
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
  // Zone folded in as a suffix rather than its own field/row — same delivery point.
  const addressWithZone = [addressSummary || null, order.delivery_zone].filter(Boolean).join(' · ');
  const scheduledDate = formatDate(order.requested_delivery_date);
  const mapHref = order.location ? `https://www.google.com/maps?q=${order.location.lat},${order.location.lng}` : null;
  const hasNote = Boolean(order.customer_note || order.notes);
  const brandName = order.channel?.brand?.name;

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
      // Canonical payment-method label — the same OrderPaymentCell the desktop
      // column renders, not a raw backend value (§9: never "mobile_wallet").
      label: t($ => $.mobileCard.payment),
      value: <OrderPaymentCell order={order} />,
    },
    {
      label: t($ => $.columns.address),
      value: addressWithZone || <span className="text-muted-foreground">—</span>,
    },
    {
      label: t($ => $.mobileCard.delivery),
      value: scheduledDate ?? <span className="text-muted-foreground">—</span>,
    },
    {
      // font-medium: the prior default weight sat too close to the muted
      // uppercase label above it to read as a distinct value at a glance (§8).
      label: t($ => $.mobileCard.warehouse),
      value: order.assigned_warehouse?.name
        ? <span className="font-medium text-foreground">{order.assigned_warehouse.name}</span>
        : <span className="text-muted-foreground">—</span>,
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
      // Has-note folded in as a small trailing icon rather than its own
      // field/row — it was always a supplementary flag, not a primary datum.
      value: (
        <div className="flex items-center gap-1.5">
          <OrderConfirmationBadge order={order} />
          {hasNote ? (
            <span title={t($ => $.mobileCard.hasNote)} className="text-amber-600 dark:text-amber-400">
              <FileText className="size-3" aria-hidden="true" />
              <span className="sr-only">{t($ => $.mobileCard.hasNote)}</span>
            </span>
          ) : null}
        </div>
      ),
    },
  ];

  return (
    <MobileDataCard
      className="mb-1.5 p-3"
      title={
        <span className="flex items-baseline gap-1.5">
          <span className="font-mono">{order.order_number}</span>
          {brandName ? (
            <span className="truncate text-xs font-normal text-muted-foreground">· {brandName}</span>
          ) : null}
        </span>
      }
      subtitle={
        <span className="text-sm font-semibold text-foreground">{order.customer?.name ?? '—'}</span>
      }
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
