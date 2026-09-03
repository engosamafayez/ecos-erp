/**
 * Localized, canonical payment-method label for the Driver Orders card.
 *
 * Reuses the SAME canonical label authority the desktop Orders workspace already
 * uses (`orders.json`'s `workspace.paymentMethodLabels`, the mapping
 * `order-payment-cell.tsx` itself documents as canonical) instead of driver-mobile's
 * own separate, textually-drifted `stop.payment.methods` set (kept as-is here — it
 * belongs to the Stop Detail page, out of scope for this task). Never renders a raw
 * enum value such as `cod`.
 *
 * TASK-ECOS-DRIVER-ORDERS-LIST-PAGE-CLOSURE-001 §6.
 */

import type { useTranslation } from 'react-i18next';

const CANONICAL_METHODS = ['cod', 'instapay', 'mobile_wallet', 'credit_card', 'bank_transfer'] as const;
type CanonicalMethod = (typeof CANONICAL_METHODS)[number];

type OrdersTFn = ReturnType<typeof useTranslation<'orders'>>['t'];

export function resolvePaymentMethodLabel(method: string | null, t: OrdersTFn): string {
  const key = (method ?? '').toLowerCase().trim();

  if ((CANONICAL_METHODS as readonly string[]).includes(key)) {
    const canonicalKey = key as CanonicalMethod;
    return t(($) => $.workspace.paymentMethodLabels[canonicalKey]);
  }

  return method && method.trim() !== '' ? method : '—';
}
