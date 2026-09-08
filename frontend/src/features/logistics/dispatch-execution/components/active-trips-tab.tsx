import { useTranslation } from 'react-i18next';
import { Truck } from 'lucide-react';

import { EmptyState, EntityTable, ErrorState } from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { TripStatusBadge } from '@/features/logistics/trips/components/trip-status-badge';
import type { Trip } from '@/features/logistics/trips/types/trip';
import { ROUTES } from '@/router/routes';

import { useOnTheRoadTrips } from '../hooks/use-on-the-road-trips';
import { OpenWorkspaceLink } from './open-workspace-link';

const MAX_ROWS = 8;

/**
 * Trips currently on the road (dispatched / out_for_delivery / in_progress) — see
 * `useOnTheRoadTrips` for why three real, existing `useTrips({status})` calls are
 * merged instead of one invented "on the road" filter.
 *
 * The deep link here is deliberately the most prominent one on this page: the
 * Trips Workspace (`ROUTES.logisticsTrips`) has no other navigation entry
 * anywhere in the app today, so this tab is genuinely the first place most users
 * will discover it.
 */
export function ActiveTripsTab() {
  const { t } = useTranslation('dispatch-execution');
  const { trips, isLoading, isError, refetch } = useOnTheRoadTrips();
  const rows = trips.slice(0, MAX_ROWS);

  const columns: ColumnDef<Trip>[] = [
    {
      key: 'trip_number',
      header: t($ => $.trips.columns.tripNumber),
      cell: (tr) => <span className="font-medium">{tr.trip_number}</span>,
    },
    {
      key: 'driver',
      header: t($ => $.trips.columns.driver),
      cell: (tr) => tr.driver?.full_name ?? t($ => $.common.notAssigned),
    },
    {
      key: 'vehicle',
      header: t($ => $.trips.columns.vehicle),
      cell: (tr) => tr.vehicle?.label ?? tr.vehicle?.plate_number ?? t($ => $.common.notAssigned),
    },
    {
      key: 'orders',
      header: t($ => $.trips.columns.orders),
      align: 'right',
      cell: (tr) =>
        typeof tr.stops_count === 'number' ? `${tr.orders_count} · ${tr.stops_count}` : tr.orders_count,
    },
    {
      key: 'status',
      header: t($ => $.trips.columns.status),
      cell: (tr) => <TripStatusBadge status={tr.status} />,
    },
  ];

  return (
    <div className="flex flex-col gap-3">
      {/* Prominent — see file docblock. */}
      <div className="flex flex-col items-start justify-between gap-3 rounded-lg border bg-muted/30 p-3 sm:flex-row sm:items-center">
        <div>
          <p className="text-sm font-medium">{t($ => $.trips.bannerTitle)}</p>
          <p className="text-sm text-muted-foreground">{t($ => $.trips.description)}</p>
        </div>
        <OpenWorkspaceLink to={ROUTES.logisticsTrips} label={t($ => $.trips.openWorkspace)} prominent />
      </div>

      <EntityTable<Trip>
        columns={columns}
        data={rows}
        getRowId={(tr) => tr.id}
        isLoading={isLoading}
        isError={isError}
        skeletonRows={5}
        emptyState={<EmptyState icon={Truck} title={t($ => $.trips.empty)} />}
        errorState={<ErrorState title={t($ => $.trips.loadError)} onRetry={() => refetch()} />}
      />
    </div>
  );
}
