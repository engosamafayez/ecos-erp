import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, MapPin, Package, Search } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import { useDriverStops, useDriverTrips } from '../hooks/use-driver-mobile';
import { DeliveryStopCard } from '../components/delivery-stop-card';
import { groupStopsByZone } from '../lib/orders-grouping';
import type { DeliveryStop } from '../types/driver-mobile';

type FilterTab = 'all' | 'pending' | 'delivered' | 'failed';

/** Which canonical stop statuses each filter tab matches. */
const TAB_STATUSES: Record<Exclude<FilterTab, 'all'>, DeliveryStop['status'][]> = {
  // "To deliver" folds in_progress (out-for-delivery) with pending — both are unresolved.
  pending: ['pending', 'in_progress'],
  // §5 — the driver sees partial deliveries as Delivered, so the Delivered filter folds
  // in 'partial' and there is no standalone Partial tab. Canonical status is unchanged.
  // TASK-ECOS-DRIVER-ORDERS-LIST-PAGE-CLOSURE-001 confirmed this stays as-is.
  delivered: ['delivered', 'partial'],
  failed: ['failed'],
};

/**
 * Driver Orders — the operational delivery list, resolved from the driver's OWN current
 * shipment (no :tripId in the URL). It reads canonical delivery stops (one stop = one
 * order) and never reconstructs the trip client-side. Each row opens the canonical stop
 * detail. This is a driver navigation destination, so it self-resolves the current trip.
 */
/** Sentinel filter value meaning "All Zones" (no zone selected). */
const ALL_ZONES = '__all__';
/** Sentinel zone key for stops whose canonical Zone did not resolve. */
const UNASSIGNED_ZONE = '__unassigned__';

export function DriverOrdersPage() {
  const { t, i18n } = useTranslation('driver-mobile');
  const [search, setSearch] = useState('');
  const [tab, setTab] = useState<FilterTab>('all');
  // Single-select, matching the existing filter primitive — TASK-ECOS-DRIVER-ORDERS-
  // LIST-PAGE-CLOSURE-001 §9 explicitly forbids building a new filtering framework
  // solely for multi-select.
  const [zoneFilter, setZoneFilter] = useState<string>(ALL_ZONES);

  const { data: trips, isLoading: tripsLoading, isError: tripsError, refetch: refetchTrips } = useDriverTrips();

  // The current delivery shipment: prefer the active trip that already has stops
  // (delivery materialised), else the most recent active trip.
  const currentTrip = useMemo(() => {
    const list = trips ?? [];
    return list.find((trip) => (trip.stops_count ?? 0) > 0) ?? list[0] ?? null;
  }, [trips]);

  const { data: stops, isLoading: stopsLoading, isError: stopsError, refetch: refetchStops } = useDriverStops(currentTrip?.id ?? '');

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

  // Localized zone name — the caller's locale decision, never made by the grouping fn.
  const zoneDisplayName = (zone: { name_en: string; name_ar: string } | null | undefined): string | null =>
    zone ? (i18n.language.startsWith('ar') ? zone.name_ar : zone.name_en) : null;

  // ZONE FILTER (TASK-ECOS-DRIVER-ORDERS-LIST-PAGE-CLOSURE-001 §9) — PRESENTATION ONLY,
  // re-keyed from free-text governorate to the canonical Distribution Zone
  // (orders.logistics_city_id → logistics_cities.distribution_zone_id →
  // distribution_zones, resolved server-side via the existing OrderZoneResolver). It
  // never reorders the canonical stop sequence — grouping still sorts by sequence.
  // Zone is gated by the same driver PII disclosure stage as governorate/city/area, so
  // every stop is "unassigned" before departure — nothing new is leaked (§44 of the
  // prior area-filter task, still true for Zone).
  const zoneOptions = useMemo(() => {
    const counts = new Map<string, { count: number; name: string | null }>();
    for (const s of filtered) {
      const zone = s.order?.zone ?? null;
      const key = zone ? String(zone.id) : UNASSIGNED_ZONE;
      const existing = counts.get(key);
      counts.set(key, { count: (existing?.count ?? 0) + 1, name: zoneDisplayName(zone) });
    }
    return [...counts.entries()]
      .map(([key, v]) => ({ key, count: v.count, name: v.name }))
      .sort((a, b) => (a.key === UNASSIGNED_ZONE ? 1 : b.key === UNASSIGNED_ZONE ? -1 : (a.name ?? '').localeCompare(b.name ?? '')));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filtered, i18n.language]);
  // Offer the filter only when there is more than one distinct Zone to choose between.
  const showZoneFilter = zoneOptions.length > 1;

  const zoneFiltered = useMemo(
    () =>
      zoneFilter === ALL_ZONES
        ? filtered
        : filtered.filter((s) => (s.order?.zone ? String(s.order.zone.id) : UNASSIGNED_ZONE) === zoneFilter),
    [filtered, zoneFilter],
  );

  // Group the (zone-)filtered stops by canonical Zone, preserving the canonical delivery
  // sequence. Not a route planner — ordering comes only from the sequence the trip
  // assigned.
  const groups = useMemo(
    () => groupStopsByZone(zoneFiltered, (zoneId) => {
      const match = zoneOptions.find((o) => o.key === String(zoneId));
      return match?.name ?? String(zoneId);
    }),
    [zoneFiltered, zoneOptions],
  );

  const noShipmentSettled = trips !== undefined && (trips.length === 0 || currentTrip === null);
  const stopsSettled = stops !== undefined;
  const isEmpty = currentTrip !== null && stopsSettled && (stops?.length ?? 0) === 0;

  const FILTERS: { key: FilterTab; label: string }[] = [
    { key: 'all', label: t(($) => $.orders.filter.all) },
    { key: 'pending', label: t(($) => $.orders.filter.pending) },
    { key: 'delivered', label: t(($) => $.orders.filter.delivered) },
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
                    setZoneFilter(ALL_ZONES); // status change resets the zone selection
                  }}
                >
                  {f.label}
                </Button>
              ))}
            </div>

            {/* Zone filter — scrollable chips (mobile-first). "All Zones" + each canonical
                Zone with its current-trip count. Presentation only; the route/sequence
                is never changed. Hidden entirely when there is nothing to filter by. */}
            {showZoneFilter && (
              <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-0.5">
                <ZoneChip
                  active={zoneFilter === ALL_ZONES}
                  label={t(($) => $.orders.allZones)}
                  count={filtered.length}
                  onClick={() => setZoneFilter(ALL_ZONES)}
                />
                {zoneOptions.map((o) => (
                  <ZoneChip
                    key={o.key}
                    active={zoneFilter === o.key}
                    label={o.key === UNASSIGNED_ZONE ? t(($) => $.orders.unassignedZone) : (o.name ?? o.key)}
                    count={o.count}
                    onClick={() => setZoneFilter(o.key)}
                  />
                ))}
              </div>
            )}
          </>
        )}
      </div>

      <div className="space-y-3 p-4">
        {tripsError || stopsError ? (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
            <AlertTriangle className="h-10 w-10 text-destructive/70" aria-hidden="true" />
            <p className="text-sm">{t(($) => $.orders.error)}</p>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                void refetchTrips();
                void refetchStops();
              }}
            >
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
            <div key={group.zoneId ?? '__unassigned'} className="space-y-2">
              {/* Zone header — canonical Distribution Zone name, or a neutral "unassigned"
                  label. Grouping is presentation only; it does not replace the filter above. */}
              <div className="flex items-center justify-between px-1">
                <p className="flex items-center gap-1.5 text-sm font-semibold">
                  <MapPin className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
                  {group.zoneName ?? t(($) => $.orders.unassignedZone)}
                </p>
                <span className="text-xs text-muted-foreground tabular-nums">
                  {t(($) => $.orders.count, { count: group.stops.length })}
                </span>
              </div>
              {group.stops.map((stop) => (
                <DeliveryStopCard
                  key={stop.id}
                  stop={stop}
                  tripId={currentTrip?.id ?? ''}
                  tripStatus={currentTrip?.status ?? null}
                />
              ))}
            </div>
          ))
        )}
      </div>
    </div>
  );
}

function ZoneChip({
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
