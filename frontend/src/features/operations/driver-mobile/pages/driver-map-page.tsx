import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Navigation, ExternalLink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { ROUTES } from '@/router/routes';
import { useDriverStops } from '../hooks/use-driver-mobile';
import type { DeliveryStop } from '../types/driver-mobile';
import { STOP_STATUS_LABELS } from '../types/driver-mobile';

interface GeoCoords {
  lat: number;
  lng: number;
}

export function DriverMapPage() {
  const { t } = useTranslation('driver-mobile');
  const { tripId = '' } = useParams<{ tripId: string }>();
  const navigate = useNavigate();
  const [currentPos, setCurrentPos] = useState<GeoCoords | null>(null);

  const { data: stops, isLoading } = useDriverStops(tripId);

  useEffect(() => {
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        (pos) => setCurrentPos({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
        () => {},
      );
    }
  }, []);

  // Prefer exact GPS coordinates when the order carries them; fall back to the
  // free-text address only when it does not.
  function destinationQuery(stop: DeliveryStop): string {
    const gps = stop.order?.gps;
    if (gps) {
      return `${gps.lat},${gps.lng}`;
    }
    return [stop.order?.address, stop.order?.city, stop.order?.governorate]
      .filter(Boolean)
      .join(', ');
  }

  function openGoogleMaps(stop: DeliveryStop) {
    window.open(`https://maps.google.com/?q=${encodeURIComponent(destinationQuery(stop))}`, '_blank');
  }

  function openWaze(stop: DeliveryStop) {
    const gps = stop.order?.gps;
    const url = gps
      ? `https://waze.com/ul?ll=${gps.lat},${gps.lng}&navigate=yes`
      : `https://waze.com/ul?q=${encodeURIComponent(destinationQuery(stop))}`;
    window.open(url, '_blank');
  }

  return (
    <div className="min-h-screen bg-background pb-6">
      {/* Header */}
      <div className="sticky top-0 z-10 bg-background border-b px-4 py-3 flex items-center gap-3">
        <Button
          variant="ghost"
          size="icon"
          onClick={() => navigate(ROUTES.driverTrip.replace(':tripId', tripId))}
        >
          <ArrowLeft className="h-5 w-5" />
        </Button>
        <h1 className="font-semibold text-base">{t(($) => $.map.title)}</h1>
      </div>

      <div className="p-4 space-y-4">
        {/* Current position */}
        {currentPos && (
          <div className="rounded-lg border bg-blue-50 dark:bg-blue-950/30 p-3 flex items-center gap-2">
            <Navigation className="h-4 w-4 text-blue-600" />
            <p className="text-sm text-blue-700 dark:text-blue-300">
              {t(($) => $.map.yourLocation, {
                lat: currentPos.lat.toFixed(5),
                lng: currentPos.lng.toFixed(5),
              })}
            </p>
          </div>
        )}

        {/* Map placeholder */}
        <div className="rounded-xl border bg-muted/30 h-40 flex items-center justify-center">
          <p className="text-muted-foreground text-sm">
            {t(($) => $.map.placeholder)}
          </p>
        </div>

        {/* Stop list */}
        <p className="font-semibold text-sm">{t(($) => $.map.stops)}</p>

        {isLoading ? (
          Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-20 w-full rounded-lg" />
          ))
        ) : (stops ?? []).length > 0 ? (
          (stops ?? []).map((stop: DeliveryStop) => (
            <div key={stop.id} className="rounded-lg border p-3 space-y-2">
              <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-xs font-bold shrink-0">
                    {stop.sequence}
                  </span>
                  <div>
                    <p className="text-sm font-medium">{stop.order?.customer_name ?? '—'}</p>
                    <p className="text-xs text-muted-foreground line-clamp-1">
                      {stop.order?.address}
                    </p>
                  </div>
                </div>
                <Badge variant="secondary" className="text-xs shrink-0">
                  {STOP_STATUS_LABELS[stop.status]}
                </Badge>
              </div>

              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  className="flex-1 text-xs gap-1"
                  onClick={() => openGoogleMaps(stop)}
                >
                  <ExternalLink className="h-3 w-3" />
                  {t(($) => $.map.googleMaps)}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  className="flex-1 text-xs gap-1"
                  onClick={() => openWaze(stop)}
                >
                  <Navigation className="h-3 w-3" />
                  {t(($) => $.map.waze)}
                </Button>
              </div>
            </div>
          ))
        ) : (
          <p className="text-center text-sm text-muted-foreground py-8">
            {t(($) => $.map.empty)}
          </p>
        )}
      </div>
    </div>
  );
}
