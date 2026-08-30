import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Phone } from 'lucide-react';
import { ROUTES } from '@/router/routes';
import type { DeliveryStop } from '../types/driver-mobile';
import { StopStatusBadge } from './stop-status-badge';

interface DeliveryStopCardProps {
  stop: DeliveryStop;
  tripId: string;
}

const STATUS_BORDER: Record<string, string> = {
  pending:     'border-l-gray-300',
  in_progress: 'border-l-blue-500',
  delivered:   'border-l-green-500',
  partial:     'border-l-amber-500',
  failed:      'border-l-red-500',
  returned:    'border-l-purple-500',
  skipped:     'border-l-gray-200',
};

export function DeliveryStopCard({ stop, tripId }: DeliveryStopCardProps) {
  const { t } = useTranslation('driver-mobile');
  const navigate = useNavigate();

  const handleClick = () => {
    navigate(
      ROUTES.driverTripStop
        .replace(':tripId', tripId)
        .replace(':stopId', stop.id),
    );
  };

  // Canonical: the phone lives on the order payload (order.phone). The old code
  // reached for a top-level billing_phone the backend never sent.
  const phone = stop.order?.phone ?? null;

  return (
    <div
      className={`cursor-pointer rounded-lg border border-l-4 bg-card p-3 shadow-sm hover:shadow-md transition-shadow ${STATUS_BORDER[stop.status] ?? 'border-l-gray-300'}`}
      onClick={handleClick}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => e.key === 'Enter' && handleClick()}
    >
      <div className="flex items-start justify-between gap-2">
        {/* Sequence + order number */}
        <div className="flex items-center gap-2">
          <span className="flex h-7 w-7 items-center justify-center rounded-full bg-muted text-xs font-bold">
            {stop.sequence}
          </span>
          <div>
            <p className="text-sm font-medium">
              {stop.order?.order_number ?? t(($) => $.stop.sequence, { sequence: stop.sequence })}
            </p>
            <p className="text-xs text-muted-foreground">
              {stop.order?.customer_name ?? '—'}
            </p>
          </div>
        </div>
        <StopStatusBadge status={stop.status} />
      </div>

      {/* Address */}
      {stop.order?.address && (
        <p className="mt-2 text-xs text-muted-foreground line-clamp-1">
          {stop.order.address}
        </p>
      )}

      {/* Zone / Area (data, not UI copy) */}
      {(stop.order?.area || stop.order?.governorate) && (
        <p className="mt-0.5 text-xs text-muted-foreground/80 line-clamp-1">
          {[stop.order?.area, stop.order?.governorate].filter(Boolean).join(' · ')}
        </p>
      )}

      {/* Footer — phone only. Money collection is frozen on the driver runtime, so the
          former "collected amount" chip (always 0) was removed (TASK-DRIVER-04 §20). */}
      {phone && (
        <div className="mt-2 flex items-center justify-end text-xs">
          <a
            href={`tel:${phone}`}
            onClick={(e) => e.stopPropagation()}
            className="flex items-center gap-1 text-blue-600 hover:underline"
          >
            <Phone className="h-3 w-3" />
            {phone}
          </a>
        </div>
      )}
    </div>
  );
}
