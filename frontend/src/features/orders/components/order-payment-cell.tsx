import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { useToast } from '@/components/ds/use-toast';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { usePermission } from '@/features/authorization/use-authorization';
import { usePatchOrder } from '@/features/orders/hooks/use-orders';
import { cn } from '@/lib/utils';
import type { Order } from '../types/order';

// ── Method badge helpers ──────────────────────────────────────────────────────

type BadgeVariant = { label: string; className: string };

const METHOD_MAP: Record<string, BadgeVariant> = {
  cod:         { label: 'COD',    className: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' },
  cash:        { label: 'Cash',   className: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' },
  visa:        { label: 'Visa',   className: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' },
  credit_card: { label: 'Card',   className: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' },
  bank:        { label: 'Bank',   className: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400' },
  instalment:  { label: 'Inst.', className: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' },
  wallet:      { label: 'Wallet', className: 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400' },
};

function resolveMethod(method: string | null, methodTitle: string | null): BadgeVariant {
  const key   = (method ?? '').toLowerCase();
  const title = (methodTitle ?? method ?? '').toLowerCase();

  for (const [k, v] of Object.entries(METHOD_MAP)) {
    if (key.includes(k) || title.includes(k)) return v;
  }

  const display = methodTitle ?? method;
  if (!display) return { label: '—', className: 'text-muted-foreground' };
  const label = display.length > 8 ? display.slice(0, 7) + '…' : display;
  return {
    label: label.charAt(0).toUpperCase() + label.slice(1),
    className: 'bg-muted text-muted-foreground',
  };
}


// ── OrderPaymentBadge (kept for backward compatibility) ───────────────────────

type BadgeProps = {
  method: string | null;
  methodTitle: string | null;
  datePaid: string | null;
};

export function OrderPaymentBadge({ method, methodTitle, datePaid }: BadgeProps) {
  const badge  = resolveMethod(method, methodTitle);
  const isPaid = Boolean(datePaid);

  return (
    <div className="flex flex-col gap-0.5">
      <span className={cn('inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold leading-none', badge.className)}>
        {badge.label}
      </span>
      <span className={cn('text-[9px] font-medium leading-none', isPaid ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400')}>
        {isPaid ? '✓ Paid' : '○ Unpaid'}
      </span>
    </div>
  );
}

// ── OrderPaymentCell — method badge with inline edit (A3) ─────────────────────

/** The five canonical payment methods (StoreManualOrderRequest / PatchOrderRequest). */
const PAYMENT_METHODS = ['cod', 'instapay', 'mobile_wallet', 'credit_card', 'bank_transfer'] as const;

type CellProps = { order: Order; onVerifyPayment?: () => void };

export function OrderPaymentCell({ order, onVerifyPayment: _onVerifyPayment }: CellProps) {
  const { t } = useTranslation('orders');
  const method =
    order.payment_method_manual ??
    order.payment_method_title ??
    order.payment_method;

  const badge = resolveMethod(method, order.payment_method_title);

  // A3 — inline payment-method edit, written through the canonical quick-update PATCH
  // (payment_method_manual, whitelisted to the 5-value catalogue server-side). This component
  // never mutates Order.status and holds NO policy logic: the server re-evaluates the payment
  // gate on a method change (D1-A) and returns the resulting order, so the status reported
  // below is server-derived truth, not a prediction made here.
  const [open, setOpen] = useState(false);
  const patch = usePatchOrder();
  const { toast } = useToast();
  const { can } = usePermission();
  const canEdit = can('sales.orders.update');

  const badgeEl = (
    <span className={cn('inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold leading-none', badge.className)}>
      {badge.label}
    </span>
  );

  if (!canEdit) return badgeEl;

  const setMethod = (m: string) => {
    if (m === order.payment_method_manual) { setOpen(false); return; }

    const previousStatus = order.status;

    patch.mutate(
      { id: order.id, data: { payment_method_manual: m } },
      {
        // A lifecycle move triggered by a payment-method change would otherwise be silent:
        // the grid re-renders the new status and nothing says why. Report only when the
        // server actually moved the order, and report what IT decided.
        onSuccess: (updated) => {
          if (updated.status === previousStatus) return;

          toast({
            title: updated.status === 'awaiting_payment'
              ? t($ => $.workspace.paymentCell.returnedToAwaitingPayment)
              : t($ => $.workspace.paymentCell.releasedToFulfilment),
          });
        },
        onError: () => toast({
          title: t($ => $.workspace.paymentCell.changeFailed),
          variant: 'destructive',
        }),
        onSettled: () => setOpen(false),
      },
    );
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button type="button" className="cursor-pointer" onClick={(e) => e.stopPropagation()} title={t($ => $.filters.paymentMethod)}>
          {badgeEl}
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-44 p-1" onClick={(e) => e.stopPropagation()}>
        <div className="flex flex-col">
          {PAYMENT_METHODS.map((m) => (
            <button
              key={m}
              type="button"
              disabled={patch.isPending}
              onClick={() => setMethod(m)}
              className={cn(
                'flex items-center justify-between rounded px-2 py-1.5 text-xs hover:bg-muted text-start',
                m === order.payment_method_manual && 'font-semibold',
              )}
            >
              {resolveMethod(m, null).label}
              {m === order.payment_method_manual ? <span className="text-emerald-600">✓</span> : null}
            </button>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  );
}
