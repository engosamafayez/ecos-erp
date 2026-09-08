import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, ArrowUpRight, History } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatRelative } from '@/lib/format';
import { ROUTES } from '@/router/routes';

import type { LiveMapTrip } from '../types/live-map';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §9 — compact detail on marker/row
 * selection, hosted in the page's own Sheet (which already supplies the
 * title and close affordance — see live-driver-map-page.tsx). Deep links
 * reuse the exact Task 003 patterns: the Trips Workspace `?tripId=`
 * drawer-open param, and Shipping Orders' `?trip_id=` filter param — no new
 * URL vocabulary, no duplicate detail page.
 */
export function DriverDetailPanel({ trip }: { trip: LiveMapTrip }) {
  const { t, i18n } = useTranslation('live-driver-map');
  const navigate = useNavigate();

  return (
    <div className="flex h-full flex-col gap-4 overflow-y-auto p-4">
      {trip.has_exception && (
        <div className="flex items-center gap-2 rounded-md bg-destructive/10 px-2.5 py-1.5 text-xs text-destructive">
          <AlertTriangle className="size-3.5 shrink-0" />
          {t(($) => $.hasException)}
        </div>
      )}

      <dl className="flex flex-col gap-2.5 text-sm">
        <div className="flex flex-col gap-0.5">
          <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.detail.driver)}
          </dt>
          <dd>{trip.driver?.full_name ?? '—'}</dd>
        </div>
        <div className="flex flex-col gap-0.5">
          <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.detail.vehicle)}
          </dt>
          <dd>{trip.vehicle ? `${trip.vehicle.plate_number} — ${trip.vehicle.name ?? ''}` : '—'}</dd>
        </div>
        <div className="flex flex-col gap-0.5">
          <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.detail.lastUpdate)}
          </dt>
          <dd className="flex items-center gap-1.5">
            {trip.location === null ? (
              <span className="text-muted-foreground">{t(($) => $.freshness.unknown)}</span>
            ) : (
              <>
                <Badge
                  variant={trip.location.freshness === 'fresh' ? 'default' : 'outline'}
                  className={
                    trip.location.freshness === 'fresh'
                      ? 'bg-emerald-600 hover:bg-emerald-600'
                      : 'text-muted-foreground'
                  }
                >
                  {trip.location.freshness === 'fresh' ? t(($) => $.freshness.fresh) : t(($) => $.freshness.stale)}
                </Badge>
                <span>{formatRelative(trip.location.recorded_at, i18n.language)}</span>
              </>
            )}
          </dd>
        </div>
        <div className="flex flex-col gap-0.5">
          <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.stopProgress, { completed: trip.stops_completed, total: trip.stops_total })}
          </dt>
        </div>
      </dl>

      <div className="mt-auto flex flex-col gap-2">
        <Button
          size="sm"
          variant="outline"
          className="justify-between"
          onClick={() => navigate(`${ROUTES.logisticsTrips}?tripId=${trip.trip_id}`)}
          data-testid="live-map-open-trip"
        >
          {t(($) => $.detail.openTrip)}
          <ArrowUpRight className="size-3.5" />
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="justify-between"
          onClick={() => navigate(`${ROUTES.logisticsTrips}?tripId=${trip.trip_id}&tab=location-history`)}
          data-testid="live-map-route-history"
        >
          {t(($) => $.detail.routeHistory)}
          <History className="size-3.5" />
        </Button>
        <Button
          size="sm"
          variant="ghost"
          className="justify-between"
          onClick={() => navigate(`${ROUTES.shippingOrders}?trip_id=${trip.trip_id}`)}
          data-testid="live-map-open-shipping-orders"
        >
          {t(($) => $.detail.openInShippingOrders)}
          <ArrowUpRight className="size-3.5" />
        </Button>
      </div>
    </div>
  );
}
