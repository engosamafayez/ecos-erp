import { useTranslation } from 'react-i18next';

import type { ShippingOrderClassification } from '../types/shipping-order';

/**
 * TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-IMPLEMENTATION-002 §31 — renders the ONE
 * canonical classification the backend already resolved (ShippingOrderClassificationService).
 * Never re-derives a classification from raw fields client-side.
 */
const CLASS_STYLES: Record<ShippingOrderClassification, string> = {
  assigned_driver:
    'text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-800/50 ring-slate-500/20',
  out_for_delivery:
    'text-cyan-700 dark:text-cyan-400 bg-cyan-50 dark:bg-cyan-950/30 ring-cyan-600/20',
  delivered:
    'text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/30 ring-emerald-600/20',
  postponed:
    'text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/30 ring-amber-600/20',
  no_answer:
    'text-orange-700 dark:text-orange-400 bg-orange-50 dark:bg-orange-950/30 ring-orange-600/20',
  cancelled:
    'text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-950/30 ring-red-600/20',
};

export function ShippingOrderStatusBadge({
  classification,
}: {
  classification: ShippingOrderClassification | null;
}) {
  const { t } = useTranslation('shipping-orders');

  if (classification === null) {
    return <span className="text-muted-foreground text-xs">—</span>;
  }

  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${CLASS_STYLES[classification]}`}
    >
      {t(($) => $.classifications[classification])}
    </span>
  );
}
