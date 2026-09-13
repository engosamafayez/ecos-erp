import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, ArrowUpRight, Truck } from 'lucide-react';

import { EmptyState, ErrorState } from '@/components/crud';
import type { DataGridColumnDef } from '@/components/data-grid';
import { UniversalDataGrid } from '@/components/data-grid';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TripStatusBadge } from '@/features/logistics/trips/components/trip-status-badge';
import { useTripStats } from '@/features/logistics/trips/hooks/use-trips';
import type { Trip } from '@/features/logistics/trips/types/trip';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { ROUTES } from '@/router/routes';

import { useOnTheRoadTrips } from '../hooks/use-on-the-road-trips';
import { OpenWorkspaceLink } from './open-workspace-link';

const MAX_ROWS = 8;

/**
 * Trips currently on the road (dispatched / out_for_delivery / in_progress) — see
 * `useOnTheRoadTrips` for why three real, existing `useTrips({status})` calls are
 * merged instead of one invented "on the road" filter.
 *
 * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §10 — stop progress uses the SAME real
 * `stops_completed_count`/`stops_count` fields Task 002 added to the Trip list
 * endpoint (and already shows on Control Tower's own Active Execution section)
 * — not a separately-invented progress computation. The exception marker
 * reuses the Trip's own already-fetched `exceptions_count` (TripResource's
 * existing `withCount('exceptions')`) rather than a new frontend-derived
 * "attention" heuristic. No ETA, no current-stop guess, no driver-presence
 * claim — none of those are canonically available (task §10's own explicit
 * exclusion list), so none are shown.
 */
export function ActiveTripsTab() {
  const { t } = useTranslation('dispatch-execution');
  const navigate = useNavigate();
  const { activeCompanyId } = useOrganizationContext();
  const { trips, isLoading, isError, refetch } = useOnTheRoadTrips();
  const tripStats = useTripStats(activeCompanyId ?? undefined);
  const rows = trips.slice(0, MAX_ROWS);

  const columns: DataGridColumnDef<Trip>[] = [
    {
      key: 'trip_number',
      label: t($ => $.trips.columns.tripNumber),
      cardRole: 'title',
      cell: (tr) => <span className="font-medium">{tr.trip_number}</span>,
    },
    {
      key: 'driver',
      label: t($ => $.trips.columns.driver),
      cardRole: 'subtitle',
      cell: (tr) => tr.driver?.full_name ?? t($ => $.common.notAssigned),
    },
    {
      key: 'vehicle',
      label: t($ => $.trips.columns.vehicle),
      cell: (tr) => tr.vehicle?.label ?? tr.vehicle?.plate_number ?? t($ => $.common.notAssigned),
    },
    {
      key: 'progress',
      label: t($ => $.trips.columns.stops),
      align: 'end',
      cell: (tr) =>
        typeof tr.stops_completed_count === 'number' && typeof tr.stops_count === 'number'
          ? `${tr.stops_completed_count} / ${tr.stops_count}`
          : (tr.stops_count ?? t($ => $.trips.notAvailable)),
    },
    {
      key: 'status',
      label: t($ => $.trips.columns.status),
      cardRole: 'status',
      cell: (tr) => (
        <div className="flex flex-wrap items-center gap-1.5">
          <TripStatusBadge status={tr.status} />
          {typeof tr.exceptions_count === 'number' && tr.exceptions_count > 0 ? (
            <Badge variant="destructive" className="gap-1 text-[10px]">
              <AlertTriangle className="size-2.5" aria-hidden />
              {t($ => $.trips.exceptions, { count: tr.exceptions_count })}
            </Badge>
          ) : null}
        </div>
      ),
    },
    {
      key: 'actions',
      label: '',
      cell: (tr) => (
        <div className="flex justify-end gap-1">
          <Button
            size="sm"
            variant="ghost"
            className="h-7 gap-1 text-xs"
            onClick={() => navigate(`${ROUTES.logisticsTrips}?tripId=${tr.id}`)}
            data-testid={`active-trip-open-${tr.id}`}
          >
            {t($ => $.trips.openTrip)}
            <ArrowUpRight className="size-3" />
          </Button>
          <Button
            size="sm"
            variant="ghost"
            className="h-7 gap-1 text-xs"
            onClick={() => navigate(`${ROUTES.shippingOrders}?trip_id=${tr.id}`)}
            data-testid={`active-trip-orders-${tr.id}`}
          >
            {t($ => $.trips.viewOrders)}
            <ArrowUpRight className="size-3" />
          </Button>
        </div>
      ),
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

      <UniversalDataGrid<Trip>
        data={rows}
        columns={columns}
        rowId={(tr) => tr.id}
        loading={isLoading}
        error={isError}
        skeletonRows={5}
        emptyState={<EmptyState icon={Truck} title={t($ => $.trips.empty)} />}
        errorState={<ErrorState title={t($ => $.trips.loadError)} onRetry={() => refetch()} />}
      />

      {/* TASK-ECOS-SHIPPING-OS-REDESIGN-003 §17 — execution ends at Settlement,
          which stays the Returns & Settlement workspace's own authority; this is
          a real count (TripStats.settlement_pending, already computed server-side)
          plus a deep link, never a reimplementation of settlement itself. */}
      {typeof tripStats.data?.settlement_pending === 'number' && tripStats.data.settlement_pending > 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-muted/20 px-3 py-2">
          <p className="text-sm text-muted-foreground">
            {t($ => $.trips.settlementPending, { count: tripStats.data.settlement_pending })}
          </p>
          <Button
            size="sm"
            variant="outline"
            className="h-7 gap-1 text-xs"
            onClick={() => navigate(`${ROUTES.shippingReturnsSettlement}?tab=driver-settlement`)}
            data-testid="active-trips-open-settlement"
          >
            {t($ => $.trips.openSettlement)}
            <ArrowUpRight className="size-3" />
          </Button>
        </div>
      ) : null}
    </div>
  );
}
