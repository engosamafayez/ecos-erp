import { useTranslation } from 'react-i18next';
import { AlertTriangle, MapPinOff } from 'lucide-react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type enLogistics from '@/i18n/locales/en/logistics.json';

import { useRouteReplay } from '../hooks/use-route-replay';
import { useRouteHistory } from '../hooks/use-live-map';
import { RouteHistoryMap } from './route-history-map';
import { RouteReplayControls } from './route-replay-controls';

type LogisticsLabel = ($: typeof enLogistics) => string;

const STOP_STATUS_LABEL: Record<string, LogisticsLabel> = {
  pending: ($) => $.trips.execution.status.pending,
  in_progress: ($) => $.trips.execution.status.in_progress,
  delivered: ($) => $.trips.execution.status.delivered,
  partial: ($) => $.trips.execution.status.partial,
  failed: ($) => $.trips.execution.status.failed,
  returned: ($) => $.trips.execution.status.returned,
  skipped: ($) => $.trips.execution.status.skipped,
};

/**
 * `exception_type` is backend-validated only as a free string (max:100), not a
 * closed enum — the driver's exception-report form just curates its input to
 * these known values today. Known values get a real Arabic label; anything
 * else (legacy data, a future value) still renders as the raw string rather
 * than silently disappearing.
 */
const EXCEPTION_TYPE_LABEL: Record<string, LogisticsLabel> = {
  damaged: ($) => $.trips.locationHistory.exceptions.type.damaged,
  missing: ($) => $.trips.locationHistory.exceptions.type.missing,
  wrong_product: ($) => $.trips.locationHistory.exceptions.type.wrong_product,
  complaint: ($) => $.trips.locationHistory.exceptions.type.complaint,
  packaging: ($) => $.trips.locationHistory.exceptions.type.packaging,
  other: ($) => $.trips.locationHistory.exceptions.type.other,
};

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §17-§20 — Route History + Replay, as a
 * TripDrawer tab (§20: "no competing history pages" — this is the ONE
 * surface; Live Map and Shipping Orders deep-link into it via
 * `?tripId=&tab=location-history` rather than each growing their own copy).
 */
export function TripLocationHistoryTab({ tripId }: { tripId: string }) {
  const { t, i18n } = useTranslation('logistics');
  const { data, isLoading, isError, refetch } = useRouteHistory(tripId);
  const replay = useRouteReplay(data?.samples.length ?? 0);

  const dateTime = (value: string | null) =>
    value ? new Date(value).toLocaleString(i18n.language) : t(($) => $.trips.drawer.timeline.none);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-3">
        <Skeleton className="h-64 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <Alert variant="destructive">
        <AlertDescription className="flex items-center justify-between gap-3">
          <span>{t(($) => $.trips.locationHistory.error)}</span>
          <Button size="sm" variant="outline" onClick={() => void refetch()}>
            {t(($) => $.trips.locationHistory.retry)}
          </Button>
        </AlertDescription>
      </Alert>
    );
  }

  const { samples, stops, exceptions, samples_truncated: truncated } = data;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold">{t(($) => $.trips.locationHistory.title)}</h3>
          <p className="text-xs text-muted-foreground">{t(($) => $.trips.locationHistory.subtitle)}</p>
        </div>
        <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
          <span>
            {t(($) => $.trips.locationHistory.startedAt)}: {dateTime(data.trip.trip_started_at)}
          </span>
          <span>
            {t(($) => $.trips.locationHistory.finishedAt)}: {dateTime(data.trip.trip_finished_at)}
          </span>
        </div>
      </div>

      {samples.length === 0 ? (
        <div
          className="flex h-48 flex-col items-center justify-center gap-2 rounded-lg border bg-muted/30 p-6 text-center"
          data-testid="location-history-empty"
        >
          <MapPinOff className="size-6 text-muted-foreground" aria-hidden />
          <p className="text-sm font-medium">{t(($) => $.trips.locationHistory.noSamples)}</p>
          <p className="max-w-md text-sm text-muted-foreground">
            {t(($) => $.trips.locationHistory.noSamplesHint)}
          </p>
        </div>
      ) : (
        <>
          <div className="relative isolate h-[45vh] min-h-[320px] overflow-hidden rounded-lg border">
            <RouteHistoryMap
              samples={samples}
              stops={stops}
              replayIndex={replay.index}
              stopLabel={(stop) => `#${stop.sequence} — ${t(STOP_STATUS_LABEL[stop.status] ?? (($) => $.trips.execution.status.pending))}`}
            />
          </div>

          <RouteReplayControls
            samples={samples}
            index={replay.index}
            isPlaying={replay.isPlaying}
            speed={replay.speed}
            onPlay={replay.play}
            onPause={replay.pause}
            onRestart={replay.restart}
            onSeek={replay.seek}
            onSpeedChange={replay.setSpeed}
          />

          <p className="text-xs text-muted-foreground">
            {t(($) => $.trips.locationHistory.sampleCount, { count: samples.length })}
            {truncated ? ` — ${t(($) => $.trips.locationHistory.truncatedNote, { count: samples.length })}` : ''}
          </p>
          <p className="text-xs text-muted-foreground">{t(($) => $.trips.locationHistory.gapNote)}</p>
        </>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <h4 className="text-sm font-medium">{t(($) => $.trips.locationHistory.stops.title)}</h4>
          <ul className="flex flex-col gap-1.5">
            {stops.map((stop) => (
              <li
                key={stop.id}
                className="flex items-center justify-between gap-2 rounded-md border px-2.5 py-1.5 text-xs"
              >
                <span className="font-medium">#{stop.sequence}</span>
                <Badge variant="outline">{t(STOP_STATUS_LABEL[stop.status] ?? (($) => $.trips.execution.status.pending))}</Badge>
                <span className="text-muted-foreground">
                  {stop.location === null
                    ? t(($) => $.trips.locationHistory.stops.noLocation)
                    : dateTime(stop.completed_at)}
                </span>
              </li>
            ))}
          </ul>
        </div>

        {exceptions.length > 0 && (
          <div className="flex flex-col gap-2">
            <h4 className="text-sm font-medium">{t(($) => $.trips.locationHistory.exceptions.title)}</h4>
            <ul className="flex flex-col gap-1.5">
              {exceptions.map((exception) => (
                <li
                  key={exception.id}
                  className="flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs"
                >
                  <AlertTriangle className="size-3.5 shrink-0 text-destructive" aria-hidden />
                  <span className="flex-1">
                    {EXCEPTION_TYPE_LABEL[exception.exception_type]
                      ? t(EXCEPTION_TYPE_LABEL[exception.exception_type])
                      : exception.exception_type}
                  </span>
                  <span className="text-muted-foreground">{dateTime(exception.reported_at)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </div>
  );
}
