import { useTranslation } from 'react-i18next';

import type { OrderStatus } from '@/features/orders/types/order';
import { cn } from '@/lib/utils';

const STATUS_CLASS: Record<OrderStatus, string> = {
  pending:              'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
  processing:           'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
  waiting_for_payment:  'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
  review_confirmation:  'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
  review:               'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
  confirmed:            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
  preparing:            'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400',
  shipping:             'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
  delivered:            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  delivery_delayed:     'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
  rejected:             'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  waiting_for_stock:    'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
  postponed:            'bg-slate-100 text-slate-700 dark:bg-slate-800/60 dark:text-slate-400',
  completed:            'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-300',
  cancelled:            'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-400',
};

type OrderStatusBadgeProps = {
  status: OrderStatus;
  /** If provided, renders as a clickable button for quick status change (DD-013). */
  onClick?: () => void;
};

export function OrderStatusBadge({ status, onClick }: OrderStatusBadgeProps) {
  const { t } = useTranslation('orders');
  const cls = STATUS_CLASS[status] ?? STATUS_CLASS.pending;

  const label = t(`status.${status}`, { defaultValue: status });

  if (onClick) {
    return (
      <button
        type="button"
        onClick={onClick}
        className={cn(
          'inline-flex cursor-pointer items-center rounded-full px-2 py-0.5 text-xs font-medium',
          'ring-1 ring-inset ring-current/20 transition-opacity hover:opacity-80',
          cls,
        )}
      >
        {label}
      </button>
    );
  }

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        'ring-1 ring-inset ring-current/20',
        cls,
      )}
    >
      {label}
    </span>
  );
}
