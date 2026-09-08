import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { Route } from 'lucide-react';

import { EmptyState, EntityTable, ErrorState } from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Button } from '@/components/ui/button';
import { TripStatusBadge } from '@/features/logistics/trips/components/trip-status-badge';
import type { Trip } from '@/features/logistics/trips/types/trip';
import { ROUTES } from '@/router/routes';

import { useOnTheRoadTrips } from '@/features/logistics/dispatch-execution/hooks/use-on-the-road-trips';
import type { KpiQueryState } from '../hooks/use-control-tower-kpis';

const MAX_ROWS = 6;

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-002 §8 — "Active Execution".
 *
 * Reuses the EXACT `useOnTheRoadTrips` hook Dispatch & Execution's own Active
 * Trips tab already built (three real `useTrips({status})` reads merged by
 * recency — see that hook's own docblock) rather than re-deriving an "on the
 * road" filter here. `stopsCompleted` is a real, server-computed count added
 * in this task (TripController::index()'s `stops_completed_count` — a
 * conditional withCount alongside the existing `stops_count`, defined as
 * "left the two unsettled DeliveryStopStatus values" — not a new business
 * rule, a read of the existing enum). `tripsActive` (the true, unbounded
 * on-the-road total from TripStats) is shown alongside the bounded real
 * preview rows so the section never implies "this IS all of them" when there
 * are more.
 *
 * Deep-links to Dispatch & Execution's own Active Trips tab (`?tab=trips`) —
 * one layer of depth up from the Trips Workspace it itself links to — per
 * task §14 ("per-trip / per-stop operational execution → Dispatch &
 * Execution").
 */
export function ActiveExecutionSection({ tripsActive }: { tripsActive: KpiQueryState }) {
  const { t } = useTranslation('control-tower');
  const navigate = useNavigate();
  const { trips, isLoading, isError, refetch } = useOnTheRoadTrips();
  const rows = trips.slice(0, MAX_ROWS);

  const columns: ColumnDef<Trip>[] = [
    {
      key: 'trip_number',
      header: t(($) => $.activeExecution.columns.trip),
      cell: (tr) => <span className="font-medium">{tr.trip_number}</span>,
    },
    {
      key: 'driver',
      header: t(($) => $.activeExecution.columns.driver),
      cell: (tr) => tr.driver?.full_name ?? t(($) => $.activeExecution.notAssigned),
    },
    {
      key: 'vehicle',
      header: t(($) => $.activeExecution.columns.vehicle),
      cell: (tr) => tr.vehicle?.label ?? tr.vehicle?.plate_number ?? t(($) => $.activeExecution.notAssigned),
    },
    {
      key: 'stops',
      header: t(($) => $.activeExecution.columns.stops),
      align: 'right',
      cell: (tr) =>
        typeof tr.stops_completed_count === 'number' && typeof tr.stops_count === 'number'
          ? `${tr.stops_completed_count} / ${tr.stops_count}`
          : (tr.stops_count ?? t(($) => $.activeExecution.notAvailable)),
    },
    {
      key: 'status',
      header: t(($) => $.activeExecution.columns.status),
      cell: (tr) => <TripStatusBadge status={tr.status} />,
    },
  ];

  return (
    <section className="space-y-3" data-testid="control-tower-active-execution">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-lg font-semibold">{t(($) => $.activeExecution.title)}</h2>
          <p className="text-sm text-muted-foreground">
            {tripsActive.value !== null
              ? t(($) => $.activeExecution.subtitleWithCount, { count: tripsActive.value })
              : t(($) => $.activeExecution.subtitle)}
          </p>
        </div>
        <Button
          size="sm"
          variant="outline"
          onClick={() => navigate(`${ROUTES.shippingDispatchExecution}?tab=trips`)}
          data-testid="control-tower-active-execution-open"
        >
          {t(($) => $.activeExecution.openDispatchExecution)}
        </Button>
      </div>

      <EntityTable<Trip>
        columns={columns}
        data={rows}
        getRowId={(tr) => tr.id}
        isLoading={isLoading}
        isError={isError}
        skeletonRows={4}
        emptyState={<EmptyState icon={Route} title={t(($) => $.activeExecution.empty)} />}
        errorState={<ErrorState title={t(($) => $.activeExecution.loadError)} onRetry={() => refetch()} />}
      />
    </section>
  );
}
