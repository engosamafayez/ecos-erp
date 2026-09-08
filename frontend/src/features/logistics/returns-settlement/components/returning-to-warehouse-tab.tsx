import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowUpRight, Route as RouteIcon } from 'lucide-react';

import { EmptyState, EntityTable, ErrorState, Pagination } from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Button } from '@/components/ui/button';
import { useFormatter } from '@/hooks/use-formatter';
import { ROUTES } from '@/router/routes';

import { TripStatusBadge } from '@/features/logistics/trips/components/trip-status-badge';
import { useTrips } from '@/features/logistics/trips/hooks/use-trips';
import type { Trip } from '@/features/logistics/trips/types/trip';

const PER_PAGE = 10;

/**
 * Returning to Warehouse tab — real data, reused directly from the canonical
 * Trip Management list (`useTrips` / `GET /logistics/distribution/trips`).
 *
 * INTERPRETATION (documented per the task instructions, since TripStatus has
 * no single dedicated "returning" status — see TRIP_STATUSES in
 * `@/features/logistics/trips/types/trip`): a trip counts as "returning to
 * warehouse" here when its status is 'completed' — every delivery stop is
 * finished, the driver is past their last stop, and financial settlement has
 * not started. 'settlement_pending' is deliberately EXCLUDED even though it
 * is also "not yet closed": those trips have already moved into financial
 * settlement and are surfaced instead by the Driver Settlement tab, so a trip
 * is never shown as still "returning" once it has entered settlement — this
 * keeps the two tabs disjoint instead of double-counting the same trip.
 *
 * This is a single-field status filter and nothing more: no shortage,
 * liability, or discrepancy figure is computed here, and every column below
 * is a value the Trip resource already returns verbatim.
 */
export function ReturningToWarehouseTab() {
  const { t } = useTranslation('returns-settlement');
  const fmt = useFormatter();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);

  const { data, isLoading, isError, refetch } = useTrips({
    status: 'completed',
    per_page: PER_PAGE,
    page,
  });

  const trips = data?.data ?? [];
  const meta = data?.meta;

  const columns = useMemo<ColumnDef<Trip>[]>(
    () => [
      {
        key: 'trip_number',
        header: t(($) => $.returningToWarehouse.columns.trip),
        cell: (trip) => (
          <div className="min-w-0">
            <span className="block truncate text-sm font-medium">{trip.trip_number}</span>
            <span className="block truncate text-xs text-muted-foreground">{trip.name}</span>
          </div>
        ),
      },
      {
        key: 'driver',
        header: t(($) => $.returningToWarehouse.columns.driver),
        cell: (trip) =>
          trip.driver?.full_name ?? <span className="text-muted-foreground">&mdash;</span>,
      },
      {
        key: 'vehicle',
        header: t(($) => $.returningToWarehouse.columns.vehicle),
        cell: (trip) =>
          trip.vehicle?.plate_number ?? <span className="text-muted-foreground">&mdash;</span>,
      },
      {
        key: 'orders_count',
        header: t(($) => $.returningToWarehouse.columns.orders),
        align: 'right',
        cell: (trip) => <span className="tabular-nums">{trip.orders_count}</span>,
      },
      {
        key: 'trip_finished_at',
        header: t(($) => $.returningToWarehouse.columns.finishedAt),
        cell: (trip) => (trip.trip_finished_at ? fmt.dateTime(trip.trip_finished_at) : '—'),
      },
      {
        key: 'status',
        header: t(($) => $.returningToWarehouse.columns.status),
        cell: (trip) => <TripStatusBadge status={trip.status} />,
      },
    ],
    [t, fmt],
  );

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold">{t(($) => $.returningToWarehouse.heading)}</h3>
          <p className="max-w-2xl text-xs text-muted-foreground">
            {t(($) => $.returningToWarehouse.headingDescription)}
          </p>
        </div>
        <Button size="sm" variant="outline" onClick={() => navigate(ROUTES.logisticsTrips)}>
          {t(($) => $.returningToWarehouse.viewAll)}
          <ArrowUpRight className="ms-1.5 size-3.5" />
        </Button>
      </div>

      <EntityTable<Trip>
        columns={columns}
        data={trips}
        getRowId={(trip) => trip.id}
        isLoading={isLoading}
        isError={isError}
        skeletonRows={5}
        emptyState={
          <EmptyState
            icon={RouteIcon}
            title={t(($) => $.returningToWarehouse.empty.title)}
            description={t(($) => $.returningToWarehouse.empty.description)}
          />
        }
        errorState={
          <ErrorState
            title={t(($) => $.returningToWarehouse.error.title)}
            description={t(($) => $.returningToWarehouse.error.description)}
            onRetry={() => void refetch()}
          />
        }
      />

      {meta && meta.total > 0 && (
        <Pagination
          meta={{ page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page }}
          onPageChange={setPage}
        />
      )}
    </div>
  );
}
