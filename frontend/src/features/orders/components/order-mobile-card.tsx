import { Calendar, Eye, FileText, MapPin, Phone, Wallet } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { OrderConfirmationBadge } from './order-confirmation-badge';
import { OrderInventoryExecutionCell } from './order-inventory-execution-cell';
import { OrderStatusBadge } from './order-status-badge';
import type { Order } from '../types/order';

type OrderMobileCardProps = {
  order: Order;
  isSelected?: boolean;
  isFocused?: boolean;
  onView: (order: Order) => void;
  onSelect?: (id: string, checked: boolean) => void;
};

function formatMoney(n: number): string {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Mobile-optimised card for a single order (TASK-ECOS-MOBILE-UX-COMPLETION-003).
 *
 * Money field fix (design report §10 / research Part A): the amount previously
 * shown was `grand_total` (the full order value), diverging from the desktop
 * list's "Total" column, which reads `remaining_balance` (grand_total minus
 * deposit paid — OrderResource.php). Both are canonical and useful, so both are
 * now shown, each explicitly labeled with the SAME labels the Order Detail
 * Page's own KPI row uses (`orderDetail.kpiRemaining` / `kpiGrandTotal`) —
 * never a fabricated label, never one number silently standing in for the other.
 *
 * Status is display-only here (`OrderStatusBadge` with no `onClick`) — the
 * previous tap-to-change handler was wired to a literal no-op
 * (`onStatusChange={() => {}}` in order-table.tsx) and has been removed rather
 * than fixed in place: a real status change needs the canonical
 * `allowed_status_transitions` contract and `useOrderWorkflowTransition` (plus,
 * for some transitions, a reason and the 422-refusal contract) — production
 * behaviour a card-sized control cannot host without duplicating
 * `QuickActionsPanel`. Status changes happen on the canonical Order Detail
 * Page, reached via `onView`/`Eye`, which already implements that logic.
 *
 * Secondary tier (design report §9 — no silently-dropped operational context):
 * zone, payment method, reservation/confirmation status, delivery window.
 *
 * TASK-ECOS-MOBILE-DATA-COMPLETENESS-FINAL-CLOSURE-005: desktop's single
 * "Inventory Execution" column actually stacks TWO distinct signals —
 * `OrderConfirmationBadge` (customer-confirmation call result) AND
 * `OrderInventoryExecutionCell` (`reservation_status`/`reservation_failure_reason`
 * — whether the order's stock is actually reserved, partially reserved, or
 * awaiting/failed). Task 3 added only the first; the reservation-execution
 * signal was silently missing on mobile even though desktop always shows it.
 * Reusing the same exported, read-only cell closes that gap without a second
 * status-mapping. A compact "has notes" indicator was added for the same
 * reason — desktop always shows the Customer Notes column; mobile had no
 * presence signal for it at all, only the full text buried in the detail page.
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
  const remaining = order.remaining_balance ?? order.grand_total - (order.deposit_paid ?? 0);
  const paymentMethod = order.payment_method_manual ?? order.payment_method;
  const deliveryDate = order.requested_delivery_date;
  const hasNote = Boolean(order.customer_note || order.notes);
  const hasSecondary = Boolean(
    order.delivery_zone || order.governorate || paymentMethod || deliveryDate
    || order.confirmation_result || order.reservation_status || hasNote,
  );

  return (
    <div
      role="listitem"
      aria-selected={isSelected}
      data-focused={isFocused || undefined}
      className={cn(
        'relative mb-2 rounded-xl border p-3.5 shadow-sm transition-colors last:mb-0',
        isSelected ? 'bg-primary/5' : 'bg-card',
        isFocused && 'outline outline-1 -outline-offset-1 outline-primary/50',
      )}
    >
      {/* Checkbox */}
      {onSelect ? (
        <div className="absolute start-3.5 top-4">
          <input
            type="checkbox"
            checked={isSelected}
            onChange={(e) => onSelect(order.id, e.target.checked)}
            className="size-4 cursor-pointer rounded accent-primary"
            aria-label={`Select ${order.order_number}`}
          />
        </div>
      ) : null}

      {/* Content — shifted right when checkbox present */}
      <button
        type="button"
        className={cn('w-full text-start', onSelect && 'ps-7')}
        onClick={() => onView(order)}
        aria-label={`View order ${order.order_number}`}
      >
        {/* Row 1: Order # + Remaining (primary money — matches desktop list's Total column) */}
        <div className="flex items-start justify-between gap-2 mb-0.5">
          <div className="flex items-center gap-1.5 min-w-0">
            <span className="font-mono text-[15px] font-semibold text-foreground">{order.order_number}</span>
            {order.channel?.name ? (
              <span className="truncate text-xs text-muted-foreground">· {order.channel.name}</span>
            ) : null}
          </div>
          <div className="shrink-0 text-end">
            <div
              className={cn(
                'text-sm font-semibold tabular-nums',
                remaining > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground',
              )}
            >
              {formatMoney(remaining)}
            </div>
            <div className="text-[10px] text-muted-foreground">{t($ => $.orderDetail.kpiRemaining)}</div>
          </div>
        </div>

        {/* Row 1b: Grand Total (secondary money — explicitly labeled, never conflated with Remaining) */}
        {remaining !== order.grand_total ? (
          <div className="mb-1 text-end text-[11px] text-muted-foreground">
            {t($ => $.orderDetail.kpiGrandTotal)}: {formatMoney(order.grand_total)}
          </div>
        ) : null}

        {/* Row 2: Customer name */}
        <p className="text-sm text-foreground/80 truncate mb-2">
          {order.customer?.name ?? '—'}
        </p>

        {/* Row 2b: SECONDARY tier — zone, payment method, reservation status, delivery window */}
        {hasSecondary ? (
          <div className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
            {order.delivery_zone || order.governorate ? (
              <span className="inline-flex items-center gap-1">
                <MapPin className="size-3 shrink-0" aria-hidden />
                <span className="truncate">{order.delivery_zone ?? order.governorate}</span>
              </span>
            ) : null}
            {paymentMethod ? (
              <span className="inline-flex items-center gap-1">
                <Wallet className="size-3 shrink-0" aria-hidden />
                <span className="truncate">{paymentMethod}</span>
              </span>
            ) : null}
            {deliveryDate ? (
              <span className="inline-flex items-center gap-1">
                <Calendar className="size-3 shrink-0" aria-hidden />
                <span className="truncate">{new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(deliveryDate))}</span>
              </span>
            ) : null}
            {order.confirmation_result ? <OrderConfirmationBadge order={order} /> : null}
            {order.reservation_status ? (
              <OrderInventoryExecutionCell
                reservationStatus={order.reservation_status}
                failureReason={order.reservation_failure_reason}
              />
            ) : null}
            {hasNote ? (
              <span className="inline-flex items-center gap-1" title={t($ => $.mobileCard.hasNote)}>
                <FileText className="size-3 shrink-0" aria-hidden />
                <span>{t($ => $.mobileCard.hasNote)}</span>
              </span>
            ) : null}
          </div>
        ) : null}
      </button>

      {/* Row 3: Status (display-only) + Items + Actions */}
      <div className={cn('flex items-center justify-between gap-2', onSelect && 'ps-7')}>
        <div className="flex items-center gap-2">
          <OrderStatusBadge status={order.status} />
          <span className="text-xs text-muted-foreground">
            {order.lines.length} {order.lines.length === 1 ? t($ => $.mobileCard.item) : t($ => $.mobileCard.items)}
          </span>
        </div>

        {/* Quick actions */}
        <div className="flex items-center gap-0.5">
          {phone ? (
            <Button variant="ghost" size="icon" className="size-7" asChild>
              <a
                href={`tel:${phone}`}
                aria-label={t($ => $.phone.call)}
                onClick={(e) => e.stopPropagation()}
              >
                <Phone className="size-3.5" />
              </a>
            </Button>
          ) : null}
          <Button
            variant="ghost"
            size="icon"
            className="size-7"
            onClick={(e) => { e.stopPropagation(); onView(order); }}
            aria-label={t($ => $.actions.view)}
          >
            <Eye className="size-3.5" />
          </Button>
        </div>
      </div>
    </div>
  );
}
