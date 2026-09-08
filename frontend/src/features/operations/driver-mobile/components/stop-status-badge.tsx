import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import type { DeliveryStopStatus } from '../types/driver-mobile';
import { STOP_STATUS_COLORS } from '../types/driver-mobile';

interface StopStatusBadgeProps {
  status: DeliveryStopStatus;
  className?: string;
}

// Same 'partial' -> delivered collapse the previous STOP_STATUS_LABELS made (a driver is
// never shown a "Partial" state) — display-only, the canonical stop.status stays 'partial'.
const STATUS_LABEL_KEY: Record<
  DeliveryStopStatus,
  'pending' | 'inProgress' | 'delivered' | 'failedDelivery' | 'returned' | 'skipped'
> = {
  pending:     'pending',
  in_progress: 'inProgress',
  delivered:   'delivered',
  partial:     'delivered',
  failed:      'failedDelivery',
  returned:    'returned',
  skipped:     'skipped',
};

export function StopStatusBadge({ status, className }: StopStatusBadgeProps) {
  const { t } = useTranslation('driver-mobile');

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
        STOP_STATUS_COLORS[status],
        className,
      )}
    >
      {t(($) => $.labels[STATUS_LABEL_KEY[status]])}
    </span>
  );
}
