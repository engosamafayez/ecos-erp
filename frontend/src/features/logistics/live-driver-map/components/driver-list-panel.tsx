import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Search } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { formatRelative } from '@/lib/format';

import type { LiveMapTrip } from '../types/live-map';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §4 — the trackable-trip list beside the
 * map. Search is client-side over the already-bounded trackable set (§8's
 * own docblock explains why a server-side filter API is unneeded at this
 * scale) — never a second source of truth for what counts as "live".
 */

function FreshnessBadge({ trip }: { trip: LiveMapTrip }) {
  const { t, i18n } = useTranslation('live-driver-map');

  if (trip.location === null) {
    return (
      <Badge variant="outline" className="text-muted-foreground">
        {t(($) => $.freshness.unknown)}
      </Badge>
    );
  }

  return (
    <div className="flex items-center gap-1.5">
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
      <span className="text-xs text-muted-foreground">
        {formatRelative(trip.location.recorded_at, i18n.language)}
      </span>
    </div>
  );
}

export function DriverListPanel({
  trips,
  selectedTripId,
  onSelectTrip,
}: {
  trips: LiveMapTrip[];
  selectedTripId: string | null;
  onSelectTrip: (tripId: string) => void;
}) {
  const { t } = useTranslation('live-driver-map');
  const [search, setSearch] = useState('');

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (q === '') return trips;
    return trips.filter((trip) => {
      const haystack = [
        trip.trip_number,
        trip.driver?.full_name ?? '',
        trip.vehicle?.plate_number ?? '',
        trip.vehicle?.name ?? '',
      ]
        .join(' ')
        .toLowerCase();
      return haystack.includes(q);
    });
  }, [trips, search]);

  return (
    <div className="flex h-full flex-col gap-3">
      <div className="flex flex-col gap-2 px-1">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold">{t(($) => $.list.title)}</h3>
          <span className="text-xs text-muted-foreground">
            {t(($) => $.list.count, { count: trips.length })}
          </span>
        </div>
        <div className="relative">
          <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t(($) => $.search.placeholder)}
            className="h-8 ps-8 text-sm"
            data-testid="live-map-search"
          />
        </div>
      </div>

      <ScrollArea className="flex-1">
        <div className="flex flex-col gap-1.5 px-1 pb-2">
          {filtered.map((trip) => (
            <button
              key={trip.trip_id}
              type="button"
              onClick={() => onSelectTrip(trip.trip_id)}
              data-testid={`live-map-row-${trip.trip_id}`}
              className={`flex flex-col gap-1.5 rounded-lg border p-2.5 text-start transition-colors ${
                trip.trip_id === selectedTripId
                  ? 'border-primary bg-primary/5'
                  : 'hover:bg-muted/50'
              }`}
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium">{trip.trip_number}</span>
                {trip.has_exception && (
                  <AlertTriangle className="size-3.5 shrink-0 text-destructive" aria-label={t(($) => $.hasException)} />
                )}
              </div>
              <span className="text-xs text-muted-foreground">
                {trip.driver?.full_name ?? '—'}
                {trip.vehicle ? ` · ${trip.vehicle.plate_number}` : ''}
              </span>
              <div className="flex items-center justify-between gap-2">
                <FreshnessBadge trip={trip} />
                <span className="text-xs text-muted-foreground">
                  {t(($) => $.stopProgress, { completed: trip.stops_completed, total: trip.stops_total })}
                </span>
              </div>
            </button>
          ))}
        </div>
      </ScrollArea>
    </div>
  );
}
