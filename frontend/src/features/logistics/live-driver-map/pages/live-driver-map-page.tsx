import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Maximize2, MapPinOff, RefreshCw } from 'lucide-react';

import { PageHeader } from '@/components/crud';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';

import { DriverDetailPanel } from '../components/driver-detail-panel';
import { DriverListPanel } from '../components/driver-list-panel';
import { LiveMapCanvas } from '../components/live-map-canvas';
import { useLiveMap } from '../hooks/use-live-map';

/**
 * Live Driver Map workspace — TASK-ECOS-SHIPPING-OS-REDESIGN-004 §4/§5.
 *
 * Real data only: `useLiveMap()` reads the backend's bounded, tenant-scoped
 * live-map read model, which already excludes anything not inside the
 * canonical custody+execution boundary (Trip::isTrackable()). This page
 * never decides trackability itself — it only presents what the backend
 * already filtered.
 *
 * Layout mirrors the sibling Distribution Map tab (distribution-map-tab.tsx):
 * a fixed-width side list, a `relative isolate` map container so Leaflet's
 * own panes/tooltips (z-index up to 1000) never paint above a Sheet, and a
 * side Sheet for selection detail — the same solution that tab already uses
 * for "click a pin, see detail".
 */
export function LiveDriverMapPage() {
  const { t } = useTranslation('live-driver-map');
  const { data, isLoading, isError, refetch, isFetching } = useLiveMap();
  const [selectedTripId, setSelectedTripId] = useState<string | null>(null);
  const [fitToken, setFitToken] = useState(0);

  const trips = data ?? [];
  const selectedTrip = trips.find((trip) => trip.trip_id === selectedTripId) ?? null;

  return (
    <div className="flex flex-col gap-4 p-4">
      <PageHeader title={t(($) => $.pageTitle)} subtitle={t(($) => $.pageSubtitle)} />

      {isError && (
        <Alert variant="destructive">
          <AlertDescription className="flex items-center justify-between gap-3">
            <span>{t(($) => $.error)}</span>
            <Button size="sm" variant="outline" onClick={() => void refetch()}>
              {t(($) => $.retry)}
            </Button>
          </AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <Skeleton className="h-[70vh] w-full" data-testid="live-map-loading" />
      ) : trips.length === 0 && !isError ? (
        <div
          className="flex h-64 flex-col items-center justify-center gap-2 rounded-lg border bg-muted/30 p-6 text-center"
          data-testid="live-map-empty"
        >
          <MapPinOff className="size-6 text-muted-foreground" aria-hidden />
          <p className="text-sm font-medium">{t(($) => $.empty.heading)}</p>
          <p className="max-w-md text-sm text-muted-foreground">{t(($) => $.empty.body)}</p>
        </div>
      ) : (
        <div className="grid gap-4 lg:grid-cols-[18rem_1fr]">
          <div className="hidden max-h-[70vh] lg:block">
            <DriverListPanel
              trips={trips}
              selectedTripId={selectedTripId}
              onSelectTrip={setSelectedTripId}
            />
          </div>

          <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-2">
              <div className="lg:hidden">
                {/* Compact list stays inline (not a sheet) on small screens — it is
                    already just as tall as the map, so a modal would add a step
                    for no space saved. */}
              </div>
              <div className="ms-auto flex items-center gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  className="gap-1.5"
                  onClick={() => void refetch()}
                  data-testid="live-map-refresh"
                >
                  <RefreshCw className={`size-3.5 ${isFetching ? 'animate-spin' : ''}`} aria-hidden />
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  className="gap-1.5"
                  onClick={() => setFitToken((n) => n + 1)}
                  data-testid="live-map-fit-all"
                >
                  <Maximize2 className="size-3.5" aria-hidden />
                  {t(($) => $.map.fitAll)}
                </Button>
              </div>
            </div>

            <div className="lg:hidden">
              <DriverListPanel
                trips={trips}
                selectedTripId={selectedTripId}
                onSelectTrip={setSelectedTripId}
              />
            </div>

            <div className="relative isolate h-[60vh] min-h-[420px] overflow-hidden rounded-lg border">
              <LiveMapCanvas
                trips={trips}
                selectedTripId={selectedTripId}
                onSelectTrip={setSelectedTripId}
                fitToken={fitToken}
                markerLabel={(trip) => `${trip.trip_number} — ${trip.driver?.full_name ?? ''}`}
              />
            </div>
          </div>
        </div>
      )}

      <Sheet open={selectedTrip !== null} onOpenChange={(open) => !open && setSelectedTripId(null)}>
        <SheetContent
          side="right"
          className="flex flex-col gap-0 p-0 sm:w-[26vw] sm:min-w-[320px] sm:max-w-[420px]"
        >
          <SheetHeader className="border-b px-4 py-3 pe-10">
            <SheetTitle className="truncate">{selectedTrip?.trip_number ?? t(($) => $.detail.title)}</SheetTitle>
          </SheetHeader>
          {selectedTrip && <DriverDetailPanel trip={selectedTrip} />}
        </SheetContent>
      </Sheet>
    </div>
  );
}
