import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Package } from 'lucide-react';
import { ROUTES } from '@/router/routes';
import type { DriverTrip } from '../types/driver-mobile';

interface DriverTripCardProps {
  trip: DriverTrip;
}

const STATUS_COLORS: Record<string, string> = {
  out_for_delivery: 'bg-blue-100 text-blue-700',
  in_progress:      'bg-amber-100 text-amber-700',
  completed:        'bg-green-100 text-green-700',
};

export function DriverTripCard({ trip }: DriverTripCardProps) {
  const { t } = useTranslation('driver-mobile');
  const navigate = useNavigate();

  const statusLabel =
    trip.status === 'out_for_delivery' ? t(($) => $.status.out_for_delivery) :
    trip.status === 'in_progress'      ? t(($) => $.status.in_progress) :
    trip.status === 'completed'        ? t(($) => $.status.completed) :
    trip.status === 'closed'           ? t(($) => $.status.closed) :
    trip.status;

  return (
    <div className="rounded-xl border bg-card shadow-sm p-4 space-y-3">
      {/* Header */}
      <div className="flex items-start justify-between gap-2">
        <p className="font-semibold text-sm">{trip.trip_number}</p>
        <Badge className={STATUS_COLORS[trip.status] ?? 'bg-gray-100 text-gray-700'}>
          {statusLabel}
        </Badge>
      </div>

      {/* Meta — assigned orders (stops_count). Money totals, zone and driver/vehicle
          identity are intentionally not shown here (frozen / forbidden on the Home). */}
      <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
        <span className="flex items-center gap-1">
          <Package className="h-3.5 w-3.5" />
          {t(($) => $.card.orders, { count: trip.stops_count })}
        </span>
      </div>

      {/* CTA */}
      <Button
        size="sm"
        className="w-full"
        onClick={() => navigate(ROUTES.driverTrip.replace(':tripId', trip.id))}
      >
        {t(($) => $.card.startLoading)}
      </Button>
    </div>
  );
}
