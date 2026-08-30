import { useTranslation } from 'react-i18next';
import { AlertTriangle, Check, Loader2, X } from 'lucide-react';

import { useToast } from '@/components/ds/use-toast';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

import { useOpenGroupLoading } from '../hooks/use-distribution-workspace';
import type { TripReadiness } from '../types';

/**
 * TASK-1-C-UI — TRIP READINESS.
 *
 * ┌─ THIS PANEL DECIDES NOTHING ─────────────────────────────────────────────┐
 * │ Every tick, every cross and the Ready/Blocked verdict come from the        │
 * │ server's `readiness` payload, which is produced by running the very guards │
 * │ `open()` runs. Nothing here recomputes a rule — not "is the group          │
 * │ finalized", not "is a driver assigned". A screen that decided readiness    │
 * │ for itself would eventually show READY on a Trip that then refuses.       │
 * │                                                                          │
 * │ So the checklist is a map over `checks`, in the server's order. Adding a   │
 * │ check server-side makes it appear here with no frontend change; the only   │
 * │ thing this file owns is the label.                                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE BUTTON IS NOT THE PROTECTION. Disabling it is a courtesy that saves a doomed
 * request; the server refuses regardless, and that refusal is the actual guarantee.
 */

/**
 * Backend check key → its label.
 *
 * A switch rather than a lookup table because the i18n layer is typed by selector: a
 * dynamic index would erase the key type and lose the compile-time guarantee that every
 * label exists. An UNKNOWN key falls through to the raw key, so a check added server-side
 * appears here — unpolished but visible — instead of rendering blank.
 */
function useCheckLabel(): (key: string) => string {
  const { t } = useTranslation('logistics');

  return (key: string): string => {
    switch (key) {
      case 'trip_belongs_to_group':
        return t(($) => $.distributionWorkspace.readiness.checks.groupFinalized);
      case 'manifest_membership':
        return t(($) => $.distributionWorkspace.readiness.checks.manifestValid);
      case 'manifest_complete':
        return t(($) => $.distributionWorkspace.readiness.checks.ordersComplete);
      case 'warehouse_resolved':
        return t(($) => $.distributionWorkspace.readiness.checks.warehouseResolved);
      case 'vehicle_assigned':
        return t(($) => $.distributionWorkspace.readiness.checks.vehicleAssigned);
      case 'driver_assigned':
        return t(($) => $.distributionWorkspace.readiness.checks.driverAssigned);
      default:
        return key;
    }
  };
}

export function TripReadinessPanel({
  readiness,
  windowId,
  slotId,
  isLoading,
  isError,
}: {
  /** The server's decision for this trip, or null when it reported none. */
  readiness: TripReadiness | null;
  windowId: string;
  slotId: string;
  isLoading: boolean;
  isError: boolean;
}) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();
  const openLoading = useOpenGroupLoading();
  const checkLabel = useCheckLabel();

  if (isLoading) {
    return (
      <Card className="mt-3 p-3" data-testid="trip-readiness-loading">
        <Skeleton className="h-4 w-40" />
        <Skeleton className="mt-2 h-20 w-full" />
      </Card>
    );
  }

  if (isError) {
    return (
      <Card className="mt-3 border-destructive/40 p-3" data-testid="trip-readiness-error">
        <p className="text-sm text-destructive">
          {t(($) => $.distributionWorkspace.readiness.loadFailed)}
        </p>
      </Card>
    );
  }

  // The server reported no decision for this trip — said plainly rather than rendering
  // an empty checklist that would read as "everything passed".
  if (readiness === null) {
    return (
      <Card className="mt-3 p-3" data-testid="trip-readiness-empty">
        <p className="text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.readiness.unavailable)}
        </p>
      </Card>
    );
  }

  const failed = readiness.checks.filter((check) => !check.ok);

  function start() {
    if (!readiness?.ready || openLoading.isPending) {
      return;
    }

    openLoading.mutate(
      { windowId, slotId, tripId: readiness.trip_id },
      {
        onError: (error: unknown) => {
          // The server's own refusal, which is the authority even when this panel
          // believed the trip was ready.
          toast({
            variant: 'destructive',
            title: t(($) => $.distributionWorkspace.readiness.startFailed),
            description:
              (error as { response?: { data?: { message?: string } } })?.response?.data
                ?.message ?? undefined,
          });
        },
      },
    );
  }

  return (
    <Card className="mt-3 p-3" data-testid={`trip-readiness-${readiness.trip_id}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h4 className="text-sm font-semibold">
          {t(($) => $.distributionWorkspace.readiness.title)}
        </h4>

        {/* State carries an icon AND a word — never colour alone. */}
        <Badge
          variant={readiness.ready ? 'secondary' : 'destructive'}
          data-testid="trip-readiness-state"
        >
          {readiness.ready ? (
            <Check className="me-1 size-3.5" aria-hidden />
          ) : (
            <AlertTriangle className="me-1 size-3.5" aria-hidden />
          )}
          {readiness.ready
            ? t(($) => $.distributionWorkspace.readiness.ready)
            : t(($) => $.distributionWorkspace.readiness.blocked)}
        </Badge>
      </div>

      <ul className="mt-2 space-y-1">
        {readiness.checks.map((check) => {
          return (
            <li
              key={check.key}
              className="flex items-center gap-2 text-xs"
              data-testid={`trip-readiness-check-${check.key}`}
            >
              {check.ok ? (
                <Check className="size-3.5 text-emerald-600" aria-hidden />
              ) : (
                <X className="size-3.5 text-destructive" aria-hidden />
              )}
              <span className={check.ok ? '' : 'font-medium text-destructive'}>
                {checkLabel(check.key)}
              </span>
              {/* Not colour alone: the state is also stated for assistive tech. */}
              <span className="sr-only">
                {check.ok
                  ? t(($) => $.distributionWorkspace.readiness.passed)
                  : t(($) => $.distributionWorkspace.readiness.failed)}
              </span>
            </li>
          );
        })}
      </ul>

      {/*
        EVERY failing check is listed, not just the first. The server names the first in
        `reason`; hiding the rest would send an operator to fix one blocker and meet the
        next one unannounced.
      */}
      {!readiness.ready ? (
        <div className="mt-2 rounded-md border border-destructive/40 p-2" data-testid="trip-readiness-reasons">
          <p className="text-xs font-medium text-destructive">
            {t(($) => $.distributionWorkspace.readiness.reasonsTitle, { count: failed.length })}
          </p>
          {readiness.reason !== null ? (
            <p className="mt-0.5 text-xs text-muted-foreground">{readiness.reason}</p>
          ) : null}
        </div>
      ) : null}

      <div className="mt-3 flex justify-end">
        <Button
          size="sm"
          onClick={start}
          disabled={!readiness.ready || openLoading.isPending}
          title={
            readiness.ready
              ? undefined
              : t(($) => $.distributionWorkspace.readiness.startBlockedHint)
          }
          data-testid="trip-readiness-start"
        >
          {openLoading.isPending ? (
            <Loader2 className="me-1.5 size-3.5 animate-spin" aria-hidden />
          ) : null}
          {t(($) => $.distributionWorkspace.readiness.start)}
        </Button>
      </div>
    </Card>
  );
}
