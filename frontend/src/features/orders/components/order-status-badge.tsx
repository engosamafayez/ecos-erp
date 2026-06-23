import { useTranslation } from 'react-i18next';

import type { OrderStatus } from '@/features/orders/types/order';

const STATUS_CLASS: Record<OrderStatus, string> = {
  pending: 'bg-yellow-100 text-yellow-800',
  processing: 'bg-blue-100 text-blue-800',
  completed: 'bg-emerald-100 text-emerald-800',
  cancelled: 'bg-rose-100 text-rose-800',
};

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  const { t } = useTranslation('orders');
  const className = STATUS_CLASS[status] ?? STATUS_CLASS.pending;
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${className}`}>
      {t(`status.${status}`)}
    </span>
  );
}
