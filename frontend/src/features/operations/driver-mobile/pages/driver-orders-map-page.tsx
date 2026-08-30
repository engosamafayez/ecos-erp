import { useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import {
  AlertTriangle,
  ExternalLink,
  List,
  LocateFixed,
  Map as MapIcon,
  MapPin,
  Maximize2,
  Navigation,
  Package,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

import { useDriverStops, useDriverTrips } from '../hooks/use-driver-mobile';
import { DriverStopsMap } from '../components/driver-stops-map';
import { StopStatusBadge } from '../components/stop-status-badge';
import { groupStopsByArea } from '../lib/orders-grouping';
import { nextStop } from '../lib/home-command-center';
import { acceptsDeliveryExecution } from '../lib/trip-lifecycle';
import type { DeliveryStop } from '../types/driver-mobile';

/**
 * TASK-DRIVER-APP-ORDERS-MAP-001 — Driver Orders Map.
 *
 * A driver-level page (self-resolves the current trip; no :tripId in the URL) that plots the
 * authenticated driver's CURRENT-trip delivery stops geographically. It is MAP VISUALISATION
 * ONLY — it reuses the canonical driver runtime read (`useDriverStops`, whose `order.gps` is
 * PII-gated server-side by `DriverRuntimeController::orderPayload`), the canonical stop
 * `sequence`, and the canonical next-stop rule (`nextStop`). It invents no coordinate, never
 * reorders stops, and performs no route optimization.
 *
 * PII / DATA-QUALITY SEPARATION (the load-bearing distinction):
 *   - Pre-departure (Stage A — `!acceptsDeliveryExecution`), the backend redacts `gps` for
 *     EVERY stop. That is a privacy gate, NOT a data gap, so this page shows a "locations
 *     available once out for delivery" state and does NOT report any stop as "missing".
 *   - On the road (Stage B/C), `gps` is exposed for stops that have a real coordinate; a null
 *     `gps` then genuinely means "no coordinate", which is what the Missing-Location count and
 *     the separate list report — never a fabricated pin.
 */
export function DriverOrdersMapPage() {
  const { t } = useTranslation('driver-mobile');
  const navigate = useNavigate();

  const [view, setView] = useState<'map' | 'list'>('map');
  const [selectedStopId, setSelectedStopId] = useState<string | null>(null);
  const [fitToken, setFitToken] = useState(0);
  const [driverPos, setDriverPos] = useState<{ lat: number; lng: number } | null>(null);

  const { data: trips, isLoading: tripsLoading, isError: tripsError, refetch } = useDriverTrips();

  // The current shipment: prefer the active trip that already has stops, else the most recent.
  const currentTrip = useMemo(() => {
    const list = trips ?? [];
    return list.find((tr) => (tr.stops_count ?? 0) > 0) ?? list[0] ?? null;
  }, [trips]);

  const { data: stops, isLoading: stopsLoading } = useDriverStops(currentTrip?.id ?? '');

  // Stage A vs B/C — the exact mirror of the backend gate that governs whether `gps` is emitted.
  const deliveryActive = acceptsDeliveryExecution(currentTrip?.status);

  // Canonical current/next stop (lowest-sequence pending/in_progress) — reused, not re-derived.
  const current = useMemo(() => (stops ? nextStop(stops) : null), [stops]);

  const located = useMemo(() => (stops ?? []).filter((s) => s.order?.gps != null), [stops]);
  const missing = useMemo(() => (stops ?? []).filter((s) => s.order?.gps == null), [stops]);

  // The pin emphasised by default is the current/next stop, until the driver taps another.
  const emphasisId = selectedStopId ?? current?.id ?? null;
  const selectedStop = useMemo(
    () => (stops ?? []).find((s) => s.id === selectedStopId) ?? null,
    [stops, selectedStopId],
  );

  const tripId = currentTrip?.id ?? '';

  // ── canonical destination coordinate → external navigation apps (point-to-point only) ──
  function destinationQuery(stop: DeliveryStop): string {
    const gps = stop.order?.gps;
    if (gps) {
      return `${gps.lat},${gps.lng}`;
    }
    return [stop.order?.address, stop.order?.city, stop.order?.governorate].filter(Boolean).join(', ');
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
  function openOrder(stop: DeliveryStop) {
    navigate(ROUTES.driverTripStop.replace(':tripId', tripId).replace(':stopId', stop.id));
  }
  function pinLabel(stop: DeliveryStop): string {
    return t(($) => $.ordersMap.pin, {
      sequence: stop.sequence,
      order: stop.order?.order_number ?? String(stop.sequence),
    });
  }
  function locateMe() {
    if (!navigator.geolocation) {
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setDriverPos({ lat: pos.coords.latitude, lng: pos.coords.longitude });
        setFitToken((n) => n + 1);
      },
      () => {},
    );
  }

  const stopsSettled = stops !== undefined;
  const noShipment = trips !== undefined && (trips.length === 0 || currentTrip === null);
  const isEmpty = currentTrip !== null && stopsSettled && (stops?.length ?? 0) === 0;

  // ── State: read failure ──────────────────────────────────────────────────────
  if (tripsError) {
    return (
      <StateBlock
        icon={<AlertTriangle className="h-10 w-10 text-destructive/70" />}
        title={t(($) => $.ordersMap.states.error.title)}
        subtitle={t(($) => $.ordersMap.states.error.subtitle)}
        action={
          <Button variant="outline" size="sm" onClick={() => void refetch()}>
            {t(($) => $.ordersMap.states.error.retry)}
          </Button>
        }
      />
    );
  }

  // ── State: loading ───────────────────────────────────────────────────────────
  if (tripsLoading || (currentTrip && stopsLoading && !stopsSettled)) {
    return (
      <div className="space-y-3">
        <Skeleton className="h-9 w-full rounded-lg" />
        <Skeleton className="h-[60svh] min-h-[320px] w-full rounded-xl" />
      </div>
    );
  }

  // ── State: no current trip ───────────────────────────────────────────────────
  if (noShipment) {
    return (
      <StateBlock
        icon={<Package className="h-12 w-12 opacity-30" />}
        title={t(($) => $.ordersMap.states.noTrip.title)}
        subtitle={t(($) => $.ordersMap.states.noTrip.subtitle)}
      />
    );
  }

  // ── State: trip has no stops ─────────────────────────────────────────────────
  if (isEmpty) {
    return (
      <StateBlock
        icon={<Package className="h-12 w-12 opacity-30" />}
        title={t(($) => $.ordersMap.states.noStops.title)}
        subtitle={t(($) => $.ordersMap.states.noStops.subtitle)}
      />
    );
  }

  const allStops = stops ?? [];

  // ── State: pre-departure (Stage A) — coordinates are redacted, NOT missing ───
  if (!deliveryActive) {
    return (
      <div className="space-y-3">
        <PageHeader view={view} onView={setView} showToggle={false} quality={null} />
        <div className="rounded-xl border border-dashed bg-muted/30 p-5 text-center">
          <MapPin className="mx-auto mb-2 h-8 w-8 text-muted-foreground/60" aria-hidden="true" />
          <p className="text-sm font-medium">{t(($) => $.ordersMap.states.preDeparture.title)}</p>
          <p className="mt-1 text-xs text-muted-foreground">
            {t(($) => $.ordersMap.states.preDeparture.subtitle)}
          </p>
        </div>
        <StopList
          groups={groupStopsByArea(allStops)}
          currentId={current?.id ?? null}
          missing={[]}
          onOpen={openOrder}
        />
      </div>
    );
  }

  const quality = { mapped: located.length, missing: missing.length };

  // ── State: on the road but NO stop has a coordinate ──────────────────────────
  if (located.length === 0) {
    return (
      <div className="space-y-3">
        <PageHeader view="list" onView={setView} showToggle={false} quality={quality} />
        <div className="rounded-xl border border-dashed bg-muted/30 p-5 text-center">
          <MapPin className="mx-auto mb-2 h-8 w-8 text-muted-foreground/60" aria-hidden="true" />
          <p className="text-sm font-medium">{t(($) => $.ordersMap.states.noCoordinates.title)}</p>
          <p className="mt-1 text-xs text-muted-foreground">
            {t(($) => $.ordersMap.states.noCoordinates.subtitle)}
          </p>
        </div>
        <StopList
          groups={[]}
          currentId={current?.id ?? null}
          missing={missing}
          onOpen={openOrder}
        />
      </div>
    );
  }

  // ── Loaded map (Stage B/C, at least one located stop) ────────────────────────
  return (
    <div className="space-y-3">
      <PageHeader view={view} onView={setView} showToggle quality={quality} />

      {view === 'map' ? (
        <div className="relative isolate h-[calc(100svh-15rem)] min-h-[340px] w-full overflow-hidden rounded-xl border">
          <DriverStopsMap
            stops={located}
            selectedStopId={emphasisId}
            onSelectStop={setSelectedStopId}
            fitToken={fitToken}
            driverPosition={driverPos}
            pinLabel={pinLabel}
          />

          {/* Map controls — top-end, clear of Leaflet's top-start zoom control */}
          <div className="absolute inset-inline-end-2 top-2 z-[1000] flex flex-col gap-2">
            <Button
              variant="secondary"
              size="icon"
              className="h-9 w-9 shadow-md"
              onClick={() => setFitToken((n) => n + 1)}
              aria-label={t(($) => $.ordersMap.recenter)}
            >
              <Maximize2 className="h-4 w-4" aria-hidden="true" />
            </Button>
            <Button
              variant="secondary"
              size="icon"
              className="h-9 w-9 shadow-md"
              onClick={locateMe}
              aria-label={t(($) => $.ordersMap.locate)}
            >
              <LocateFixed className="h-4 w-4" aria-hidden="true" />
            </Button>
          </div>

          {/* Missing-location pill — jumps to the list where they remain accessible */}
          {missing.length > 0 && (
            <button
              type="button"
              onClick={() => setView('list')}
              className="absolute inset-inline-start-2 bottom-2 z-[1000] flex items-center gap-1.5 rounded-full border bg-background/95 px-3 py-1.5 text-xs font-medium shadow-md backdrop-blur"
            >
              <AlertTriangle className="h-3.5 w-3.5 text-amber-500" aria-hidden="true" />
              {t(($) => $.ordersMap.missingSection.title, { count: missing.length })}
            </button>
          )}
        </div>
      ) : (
        <StopList
          groups={groupStopsByArea(located)}
          currentId={current?.id ?? null}
          missing={missing}
          onOpen={openOrder}
        />
      )}

      {/* Pin preview — compact stop card + Open Order + external navigation */}
      <Sheet open={selectedStopId !== null} onOpenChange={(open) => !open && setSelectedStopId(null)}>
        <SheetContent side="bottom" className="max-h-[80svh] space-y-3 overflow-y-auto rounded-t-2xl">
          <SheetTitle className="sr-only">{t(($) => $.ordersMap.preview.title)}</SheetTitle>
          {selectedStop && (
            <div className="space-y-3">
              <div className="flex items-start justify-between gap-2">
                <div className="flex items-center gap-2">
                  <span className="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-sm font-bold">
                    {selectedStop.sequence}
                  </span>
                  <div>
                    <p className="text-sm font-semibold">
                      {selectedStop.order?.order_number ?? t(($) => $.ordersMap.pin, {
                        sequence: selectedStop.sequence,
                        order: selectedStop.sequence,
                      })}
                    </p>
                    {selectedStop.order?.customer_name && (
                      <p className="text-xs text-muted-foreground">{selectedStop.order.customer_name}</p>
                    )}
                  </div>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <StopStatusBadge status={selectedStop.status} />
                  {current?.id === selectedStop.id && (
                    <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary">
                      {t(($) => $.ordersMap.current)}
                    </span>
                  )}
                </div>
              </div>

              {(selectedStop.order?.area || selectedStop.order?.governorate) && (
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                  <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
                  {[selectedStop.order?.area, selectedStop.order?.governorate].filter(Boolean).join(' · ')}
                </p>
              )}
              {selectedStop.order?.address && (
                <p className="text-xs text-muted-foreground">{selectedStop.order.address}</p>
              )}
              {(selectedStop.order?.remaining_balance ?? 0) > 0 && (
                <p className="text-xs">
                  <span className="text-muted-foreground">{t(($) => $.ordersMap.preview.amountToCollect)}: </span>
                  <span className="font-semibold tabular-nums">
                    {(selectedStop.order?.remaining_balance ?? 0).toLocaleString(undefined, {
                      minimumFractionDigits: 2,
                      maximumFractionDigits: 2,
                    })}
                  </span>
                </p>
              )}

              <Button className="w-full" onClick={() => openOrder(selectedStop)}>
                {t(($) => $.ordersMap.preview.openOrder)}
              </Button>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  className="flex-1 gap-1 text-xs"
                  onClick={() => openGoogleMaps(selectedStop)}
                >
                  <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                  {t(($) => $.ordersMap.preview.googleMaps)}
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  className="flex-1 gap-1 text-xs"
                  onClick={() => openWaze(selectedStop)}
                >
                  <Navigation className="h-3.5 w-3.5" aria-hidden="true" />
                  {t(($) => $.ordersMap.preview.waze)}
                </Button>
              </div>
            </div>
          )}
        </SheetContent>
      </Sheet>
    </div>
  );
}

// ── Sub-components (module-private; not exported → no fast-refresh mixed-export issue) ──

function PageHeader({
  view,
  onView,
  showToggle,
  quality,
}: {
  view: 'map' | 'list';
  onView: (v: 'map' | 'list') => void;
  showToggle: boolean;
  quality: { mapped: number; missing: number } | null;
}) {
  const { t } = useTranslation('driver-mobile');
  return (
    <div className="flex items-center justify-between gap-2">
      <div className="min-w-0">
        <h1 className="truncate text-base font-semibold">{t(($) => $.ordersMap.title)}</h1>
        {quality && (
          <p className="text-xs text-muted-foreground tabular-nums">
            {t(($) => $.ordersMap.quality.summary, { mapped: quality.mapped, missing: quality.missing })}
          </p>
        )}
      </div>
      {showToggle && (
        <div className="flex shrink-0 rounded-lg border p-0.5" role="tablist">
          <button
            type="button"
            role="tab"
            aria-selected={view === 'map'}
            onClick={() => onView('map')}
            className={cn(
              'flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
              view === 'map' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground',
            )}
          >
            <MapIcon className="h-3.5 w-3.5" aria-hidden="true" />
            {t(($) => $.ordersMap.view.map)}
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={view === 'list'}
            onClick={() => onView('list')}
            className={cn(
              'flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
              view === 'list' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground',
            )}
          >
            <List className="h-3.5 w-3.5" aria-hidden="true" />
            {t(($) => $.ordersMap.view.list)}
          </button>
        </div>
      )}
    </div>
  );
}

function StopList({
  groups,
  currentId,
  missing,
  onOpen,
}: {
  groups: { area: string | null; stops: DeliveryStop[] }[];
  currentId: string | null;
  missing: DeliveryStop[];
  onOpen: (stop: DeliveryStop) => void;
}) {
  const { t } = useTranslation('driver-mobile');
  return (
    <div className="space-y-4">
      {groups.map((group) => (
        <div key={group.area ?? '__unzoned'} className="space-y-2">
          <div className="flex items-center justify-between px-1">
            <p className="flex items-center gap-1.5 text-sm font-semibold">
              <MapPin className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
              {group.area ?? t(($) => $.ordersMap.unzoned)}
            </p>
            <span className="text-xs text-muted-foreground tabular-nums">{group.stops.length}</span>
          </div>
          {group.stops.map((stop) => (
            <StopRow key={stop.id} stop={stop} isCurrent={stop.id === currentId} onOpen={onOpen} />
          ))}
        </div>
      ))}

      {missing.length > 0 && (
        <div className="space-y-2">
          <p className="flex items-center gap-1.5 px-1 text-sm font-semibold text-amber-600">
            <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
            {t(($) => $.ordersMap.missingSection.title, { count: missing.length })}
          </p>
          <p className="px-1 text-xs text-muted-foreground">{t(($) => $.ordersMap.missingSection.hint)}</p>
          {missing.map((stop) => (
            <StopRow key={stop.id} stop={stop} isCurrent={stop.id === currentId} onOpen={onOpen} />
          ))}
        </div>
      )}
    </div>
  );
}

function StopRow({
  stop,
  isCurrent,
  onOpen,
}: {
  stop: DeliveryStop;
  isCurrent: boolean;
  onOpen: (stop: DeliveryStop) => void;
}) {
  const { t } = useTranslation('driver-mobile');
  return (
    <div
      role="button"
      tabIndex={0}
      onClick={() => onOpen(stop)}
      onKeyDown={(e) => e.key === 'Enter' && onOpen(stop)}
      className={cn(
        'cursor-pointer rounded-lg border bg-card p-3 shadow-sm transition-shadow hover:shadow-md',
        isCurrent && 'border-primary/50 ring-1 ring-primary/30',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="flex h-7 w-7 items-center justify-center rounded-full bg-muted text-xs font-bold">
            {stop.sequence}
          </span>
          <div className="min-w-0">
            <p className="truncate text-sm font-medium">{stop.order?.order_number ?? '—'}</p>
            <p className="truncate text-xs text-muted-foreground">{stop.order?.customer_name ?? '—'}</p>
          </div>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          <StopStatusBadge status={stop.status} />
          {isCurrent && (
            <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary">
              {t(($) => $.ordersMap.current)}
            </span>
          )}
        </div>
      </div>
      {(stop.order?.area || stop.order?.governorate) && (
        <p className="mt-1.5 truncate text-xs text-muted-foreground/80">
          {[stop.order?.area, stop.order?.governorate].filter(Boolean).join(' · ')}
        </p>
      )}
    </div>
  );
}

function StateBlock({
  icon,
  title,
  subtitle,
  action,
}: {
  icon: ReactNode;
  title: string;
  subtitle: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-20 text-center text-muted-foreground">
      {icon}
      <p className="text-base font-medium text-foreground">{title}</p>
      <p className="max-w-xs text-sm">{subtitle}</p>
      {action}
    </div>
  );
}
