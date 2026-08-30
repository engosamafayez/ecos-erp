import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, MapPin, Package, Search } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import { useDriverStops, useDriverTrips } from '../hooks/use-driver-mobile';
import { DeliveryStopCard } from '../components/delivery-stop-card';
import { groupStopsByArea } from '../lib/orders-grouping';
import type { DeliveryStop } from '../types/driver-mobile';

type FilterTab = 'all' | 'pending' | 'delivered' | 'partial' | 'failed';

/** Which canonical stop statuses each filter tab matches. */
const TAB_STATUSES: Record<Exclude<FilterTab, 'all'>, DeliveryStop['status'][]> = {
  // "To deliver" folds in_progress (out-for-delivery) with pending — both are unresolved.
  pending: ['pending', 'in_progress'],
  delivered: ['delivered'],
  partial: ['partial'],
  failed: ['failed'],
};

/**
 * Driver Orders — the operational delivery list, resolved from the driver's OWN current
 * shipment (no :tripId in the URL). It reads canonical delivery stops (one stop = one
 * order) and never reconstructs the trip client-side. Each row opens the canonical stop
 * detail. This is a driver navigation destination, so it self-resolves the current trip.
 */
/** Sentinel area key for stops whose canonical area is absent (or redacted pre-departure). */
const UNZONED = '__unzoned__';

export function DriverOrdersPage() {
  const { t } = useTranslation('driver-mobile');
  const [search, setSearch] = useState('');
  const [tab, setTab] = useState<FilterTab>('all');
  const [areaFilter, setAreaFilter] = useState<string | null>(null);

  const { data: trips, isLoading: tripsLoading, isError: tripsError, refetch: refetchTrips } = useDriverTrips();

  // The current delivery shipment: prefer the active trip that already has stops
  // (delivery materialised), else the most recent active trip.
  const currentTrip = useMemo(() => {
    const list = trips ?? [];
    return list.find((trip) => (trip.stops_count ?? 0) > 0) ?? list[0] ?? null;
  }, [trips]);

  const { data: stops, isLoading: stopsLoading } = useDriverStops(currentTrip?.id ?? '');

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return (stops ?? []).filter((stop: DeliveryStop) => {
      const matchesTab = tab === 'all' || TAB_STATUSES[tab].includes(stop.status);
      const matchesSearch =
        !q ||
        (stop.order?.order_number ?? '').toLowerCase().includes(q) ||
        (stop.order?.customer_name ?? '').toLowerCase().includes(q);
      return matchesTab && matchesSearch;
    });
  }, [stops, tab, search]);

  // AREA FILTER (TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §24–§27) — PRESENTATION ONLY. It
  // never reorders the canonical stop sequence (§28); grouping still sorts by sequence. Areas
  // come from the canonical governorate already on the (privacy-gated) stop payload — before
  // departure that field is redacted server-side, so every stop is UNZONED and no chip can leak
  // customer location (§44). Counts are over the current trip's search/status-filtered stops (§26).
  const areaOptions = useMemo(() => {
    const counts = new Map<string, number>();
    for (const s of filtered) {
      const key = s.order?.governorate ?? UNZONED;
      counts.set(key, (counts.get(key) ?? 0) + 1);
    }
    return [...counts.entries()]
      .map(([key, count]) => ({ key, count }))
      .sort((a, b) => (a.key === UNZONED ? 1 : b.key === UNZONED ? -1 : a.key.localeCompare(b.key)));
  }, [filtered]);
  // Offer the filter only when there is more than one distinct area to choose between.
  const showAreaFilter = areaOptions.length > 1;

  const areaFiltered = useMemo(
    () =>
      areaFilter === null
        ? filtered
        : filtered.filter((s) => (s.order?.governorate ?? UNZONED) === areaFilter),
    [filtered, areaFilter],
  );

  // Group the (area-)filtered stops by canonical area, preserving the canonical delivery sequence
  // (§2/§3/§28). Not a route planner — ordering comes only from the sequence the trip assigned.
  const groups = useMemo(() => groupStopsByArea(areaFiltered), [areaFiltered]);

  const noShipmentSettled = trips !== undefined && (trips.length === 0 || currentTrip === null);
  const stopsSettled = stops !== undefined;
  const isEmpty = currentTrip !== null && stopsSettled && (stops?.length ?? 0) === 0;

  const FILTERS: { key: FilterTab; label: string }[] = [
    { key: 'all', label: t(($) => $.orders.filter.all) },
    { key: 'pending', label: t(($) => $.orders.filter.pending) },
    { key: 'delivered', label: t(($) => $.orders.filter.delivered) },
    { key: 'partial', label: t(($) => $.orders.filter.partial) },
    { key: 'failed', label: t(($) => $.orders.filter.failed) },
  ];

  return (
    <div className="min-h-screen bg-background pb-8">
      <div className="sticky top-0 z-10 space-y-3 border-b bg-background px-4 py-3">
        <div className="flex items-center justify-between gap-2">
          <h1 className="text-base font-semibold">{t(($) => $.orders.title)}</h1>
          {currentTrip && (stops?.length ?? 0) > 0 && (
            <span className="text-xs text-muted-foreground tabular-nums">
              {t(($) => $.orders.count, { count: stops?.length ?? 0 })}
            </span>
          )}
        </div>

        {currentTrip && !isEmpty && (
          <>
            <div className="relative">
              <Search className="absolute inset-inline-start-3 top-2.5 h-4 w-4 text-muted-foreground" aria-hidden="true" />
              <Input
                className="ps-9"
                placeholder={t(($) => $.orders.search)}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <div className="flex gap-1">
              {FILTERS.map((f) => (
                <Button
                  key={f.key}
                  variant={tab === f.key ? 'default' : 'outline'}
                  size="sm"
                  className="flex-1 text-xs"
                  onClick={() => {
                    setTab(f.key);
                    setAreaFilter(null); // status change resets the area selection
                  }}
                >
                  {f.label}
                </Button>
              ))}
            </div>

            {/* Area filter — scrollable chips (mobile-first, §29). "All areas" + each canonical
                area with its current-trip count (§25/§26). Presentation only; the route/sequence
                is never changed (§28). Hidden entirely when there is nothing to filter by. */}
            {showAreaFilter && (
              <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-0.5">
                <AreaChip
                  active={areaFilter === null}
                  label={t(($) => $.orders.allAreas)}
                  count={filtered.length}
                  onClick={() => setAreaFilter(null)}
                />
                {areaOptions.map((o) => (
                  <AreaChip
                    key={o.key}
                    active={areaFilter === o.key}
                    label={o.key === UNZONED ? t(($) => $.orders.unzoned) : o.key}
                    count={o.count}
                    onClick={() => setAreaFilter(o.key)}
                  />
                ))}
              </div>
            )}
          </>
        )}
      </div>

      <div className="space-y-3 p-4">
        {tripsError ? (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
            <AlertTriangle className="h-10 w-10 text-destructive/70" aria-hidden="true" />
            <p className="text-sm">{t(($) => $.orders.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetchTrips()}>
              {t(($) => $.orders.retry)}
            </Button>
          </div>
        ) : tripsLoading || (currentTrip && stopsLoading && !stopsSettled) ? (
          Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-24 w-full rounded-lg" />)
        ) : noShipmentSettled ? (
          <div className="flex flex-col items-center justify-center py-20 text-center text-muted-foreground">
            <Package className="mb-3 h-12 w-12 opacity-30" aria-hidden="true" />
            <p className="text-base font-medium">{t(($) => $.orders.noShipment.title)}</p>
            <p className="mt-1 text-sm">{t(($) => $.orders.noShipment.subtitle)}</p>
          </div>
        ) : isEmpty ? (
          <div className="flex flex-col items-center justify-center py-20 text-center text-muted-foreground">
            <Package className="mb-3 h-12 w-12 opacity-30" aria-hidden="true" />
            <p className="text-base font-medium">{t(($) => $.orders.empty.title)}</p>
            <p className="mt-1 text-sm">{t(($) => $.orders.empty.subtitle)}</p>
          </div>
        ) : filtered.length === 0 ? (
          <p className="py-12 text-center text-sm text-muted-foreground">{t(($) => $.orders.noMatch)}</p>
        ) : (
          groups.map((group) => (
            <div key={group.area ?? '__unzoned'} className="space-y-2">
              {/* Area / zone header — canonical governorate, or a neutral "other" label. */}
              <div className="flex items-center justify-between px-1">
                <p className="flex items-center gap-1.5 text-sm font-semibold">
                  <MapPin className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
                  {group.area ?? t(($) => $.orders.unzoned)}
                </p>
                <span className="text-xs text-muted-foreground tabular-nums">
                  {t(($) => $.orders.count, { count: group.stops.length })}
                </span>
              </div>
              {group.stops.map((stop) => (
                <DeliveryStopCard key={stop.id} stop={stop} tripId={currentTrip?.id ?? ''} />
              ))}
            </div>
          ))
        )}
      </div>
    </div>
  );
}

function AreaChip({
  active,
  label,
  count,
  onClick,
}: {
  active: boolean;
  label: string;
  count: number;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
        active
          ? 'border-primary bg-primary text-primary-foreground'
          : 'bg-background text-muted-foreground hover:bg-accent/40',
      )}
    >
      <span className="max-w-[8rem] truncate">{label}</span>
      <span className={cn('tabular-nums', active ? 'text-primary-foreground/80' : 'text-muted-foreground/70')}>
        {count}
      </span>
    </button>
  );
}
