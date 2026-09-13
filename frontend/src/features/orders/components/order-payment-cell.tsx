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
type TFn = ReturnType<typeof useTranslation<'orders'>>['t'];

/**
 * TASK-ECOS-MOBILE-POST-DEV-UX-REVIEW-001 (§7) — the canonical manual payment
 * methods (`order-payment-section.tsx`'s own picker, `workspace.
 * paymentMethodLabels`) already have real, translated labels ("Cash on
 * Delivery" / "الدفع عند الاستلام", not a bare "COD"). This badge used to carry
 * its OWN separate, hardcoded-English, untranslated short-label map for the
 * exact same 5 values — the raw "COD" the User flagged on the Mobile card was
 * a symptom of that duplication, not a Mobile-only bug (the desktop Payment
 * column rendered it identically). Fixed at the canonical authority itself, so
 * both desktop and Mobile — every consumer of this component — read correctly
 * in whichever language is active, rather than patching Mobile in isolation.
 */
const CANONICAL_METHODS = ['cod', 'instapay', 'mobile_wallet', 'credit_card', 'bank_transfer'] as const;

const CANONICAL_METHOD_CLASS: Record<(typeof CANONICAL_METHODS)[number], string> = {
  cod:           'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
  instapay:      'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400',
  mobile_wallet: 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400',
  bank_transfer: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400',
  credit_card:   'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
};

// Legacy/WooCommerce-origin values outside the 5 canonical manual methods
// above (e.g. a raw WC gateway id) — kept as the short, non-localized
// heuristic this badge always used, since no canonical translated label
// exists for these; never applied to a canonical method.
const LEGACY_METHOD_MAP: Record<string, BadgeVariant> = {
  cash:        { label: 'Cash',   className: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' },
  visa:        { label: 'Visa',   className: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' },
  bank:        { label: 'Bank',   className: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400' },
  instalment:  { label: 'Inst.', className: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' },
  wallet:      { label: 'Wallet', className: 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400' },
};

function resolveMethod(method: string | null, methodTitle: string | null, t: TFn): BadgeVariant {
  const key   = (method ?? '').toLowerCase();
  const title = (methodTitle ?? method ?? '').toLowerCase();

  if ((CANONICAL_METHODS as readonly string[]).includes(key)) {
    const canonicalKey = key as (typeof CANONICAL_METHODS)[number];
    return {
      label: t($ => $.workspace.paymentMethodLabels[canonicalKey]),
      className: CANONICAL_METHOD_CLASS[canonicalKey],
    };
  }

  for (const [k, v] of Object.entries(LEGACY_METHOD_MAP)) {
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


// ── OrderPaymentCell — method badge with inline edit (A3) ─────────────────────
// (StoreManualOrderRequest / PatchOrderRequest — the same 5 values as
// CANONICAL_METHODS above; kept as one shared constant, not two.)

type CellProps = { order: Order; onVerifyPayment?: () => void };

export function OrderPaymentCell({ order, onVerifyPayment: _onVerifyPayment }: CellProps) {
  const { t } = useTranslation('orders');
  const method =
    order.payment_method_manual ??
    order.payment_method_title ??
    order.payment_method;

  const badge = resolveMethod(method, order.payment_method_title, t);

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
          {CANONICAL_METHODS.map((m) => (
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
              {resolveMethod(m, null, t).label}
              {m === order.payment_method_manual ? <span className="text-emerald-600">✓</span> : null}
            </button>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  );
}
