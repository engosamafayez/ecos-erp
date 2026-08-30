import { useTranslation } from 'react-i18next';

import { Link } from 'react-router-dom';

import { PackageCheck, Truck } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import type { DataGridColumnDef } from '@/components/data-grid/types';

import { ROUTES } from '@/router/routes';

import { useGroupTrips, useOpenGroupLoading } from '../hooks/use-distribution-workspace';
import type { GroupLoadingContext, GroupRequiredProduct, SlotSummary } from '../types';

/**
 * Group → Trip → Vehicle/Driver → Loading, as the operator sees it.
 *
 * ┌─ THE ECOS CAPACITY CONTRACT ─────────────────────────────────────────────┐
 * │ Capacity is an ORDER COUNT and nothing else. Weight, Volume and          │
 * │ Refrigeration are NOT ECOS business constraints and are deliberately     │
 * │ absent from this surface — not hidden, absent. They are not fetched, not │
 * │ typed and not rendered, so they cannot quietly become requirements again.│
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Every quantity shown is the server's. Required comes from the canonical Group
 * projection, Prepared from the approved Group+Product Prepared contract, and
 * Remaining is derived server-side. This component computes none of them — a
 * number recomputed here could disagree with the number the write is checked
 * against.
 *
 * Loaded is a SEPARATE column and is never inferred from Prepared. The two mean
 * different things: prepared onto the group's pallet, versus physically on the
 * vehicle.
 */
export function GroupLoadingExecution({
  windowId,
  group,
  canPlan,
}: {
  windowId: string | undefined;
  group: SlotSummary;
  canPlan: boolean;
}) {
  const { t } = useTranslation('logistics');
  const open = useOpenGroupLoading();

  // The Trip is read from the SAME canonical source the Trip panel uses, rather
  // than being threaded down as a prop — so the two can never disagree about
  // which trip a group is executing.
  const { data: tripsResult } = useGroupTrips(windowId, group.slot_id);
  const trips = tripsResult?.trips ?? [];
  const tripId = trips.length > 0 ? trips[0].trip_id : null;

  const context: GroupLoadingContext | undefined = open.data;

  const columns: DataGridColumnDef<GroupRequiredProduct>[] = [
    {
      key: 'product',
      label: t(($) => $.distributionWorkspace.loadingExecution.columns.product),
      alwaysVisible: true,
      cell: (r) => (
        <div className="flex flex-col">
          <span className="font-medium">{r.product_name ?? r.product_id}</span>
          <span className="text-xs text-muted-foreground">{r.product_sku ?? '—'}</span>
        </div>
      ),
    },
    {
      key: 'required',
      label: t(($) => $.distributionWorkspace.loadingExecution.columns.required),
      align: 'end',
      cell: (r) => <span className="tabular-nums">{r.total_quantity}</span>,
    },
    {
      key: 'prepared',
      label: t(($) => $.distributionWorkspace.loadingExecution.columns.prepared),
      align: 'end',
      cell: (r) => <span className="tabular-nums">{r.prepared_qty ?? 0}</span>,
    },
    {
      key: 'remaining',
      label: t(($) => $.distributionWorkspace.loadingExecution.columns.remaining),
      align: 'end',
      // Derived by the server. Rendered, never recalculated.
      cell: (r) => <span className="tabular-nums">{r.remaining_qty ?? 0}</span>,
    },
    {
      key: 'loaded',
      label: t(($) => $.distributionWorkspace.loadingExecution.columns.loaded),
      align: 'end',
      // Loading has not recorded anything for this product until a task exists.
      // Shown as an explicit dash rather than 0, so "nothing loaded yet" is not
      // mistaken for "loaded zero".
      cell: () => <span className="tabular-nums text-muted-foreground">—</span>,
    },
    {
      key: 'unit',
      label: t(($) => $.distributionWorkspace.loadingExecution.columns.unit),
      cell: (r) => r.unit_symbol ?? r.unit_code ?? '—',
    },
  ];

  return (
    <div className="space-y-3" data-testid="group-loading-execution">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <PackageCheck className="size-4 text-muted-foreground" aria-hidden />
          <h3 className="font-medium">
            {t(($) => $.distributionWorkspace.loadingExecution.title)}
          </h3>
        </div>

        <Button
          size="sm"
          variant="outline"
          data-testid="group-open-loading"
          disabled={!canPlan || !windowId || tripId === null || open.isPending}
          onClick={() => {
            if (!windowId || tripId === null) return;
            open.mutate({ windowId, slotId: group.slot_id, tripId });
          }}
        >
          <Truck className="me-1 size-3.5" aria-hidden />
          {t(($) => $.distributionWorkspace.loadingExecution.open)}
        </Button>
      </div>

      {/* A Group with no Trip has not been given a vehicle yet — said plainly,
          because an empty panel would read as "nothing to load". */}
      {tripId === null ? (
        <p className="text-sm text-muted-foreground" data-testid="group-loading-no-trip">
          {t(($) => $.distributionWorkspace.loadingExecution.noTrip)}
        </p>
      ) : null}

      {open.isError ? (
        <div className="space-y-2" data-testid="group-loading-error">
          <p className="text-sm text-destructive">
            {(open.error as { response?: { data?: { message?: string } } })?.response?.data
              ?.message ?? t(($) => $.distributionWorkspace.loadingExecution.openFailed)}
          </p>

          {/* The server-side Loading guard stays exactly as it is — a group with
              no vehicle and driver must not open Loading. What was missing is the
              way OUT of the refusal: the operator was told a prerequisite was
              unmet with no route to satisfying it. These link to the EXISTING
              Fleet pages and bypass nothing. */}
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline" size="sm" data-testid="group-loading-manage-vehicles">
              <Link to={ROUTES.logisticsVehicles}>
                {t(($) => $.distributionWorkspace.fleet.manageVehicles)}
              </Link>
            </Button>
            <Button asChild variant="outline" size="sm" data-testid="group-loading-manage-drivers">
              <Link to={ROUTES.logisticsDrivers}>
                {t(($) => $.distributionWorkspace.fleet.manageDrivers)}
              </Link>
            </Button>
          </div>
        </div>
      ) : null}

      {context ? (
        <>
          {/* ── Header: the canonical execution context ─────────────────── */}
          <dl
            className="grid grid-cols-2 gap-3 rounded-lg border bg-muted/40 p-3 text-sm sm:grid-cols-3 lg:grid-cols-6"
            data-testid="group-loading-header"
          >
            <Field
              label={t(($) => $.distributionWorkspace.loadingExecution.group)}
              value={context.group.code}
            />
            <Field
              label={t(($) => $.distributionWorkspace.loadingExecution.trip)}
              value={context.trip.trip_number ?? context.trip.id}
            />
            <Field
              label={t(($) => $.distributionWorkspace.loadingExecution.vehicle)}
              value={context.vehicle?.plate_number ?? '—'}
            />
            <Field
              label={t(($) => $.distributionWorkspace.loadingExecution.driver)}
              value={context.driver?.full_name ?? '—'}
            />
            <Field
              label={t(($) => $.distributionWorkspace.loadingExecution.session)}
              value={context.loading.session_number ?? '—'}
            />
            <div>
              <dt className="text-xs uppercase text-muted-foreground">
                {t(($) => $.distributionWorkspace.loadingExecution.status)}
              </dt>
              <dd>
                <Badge variant="secondary">{context.loading.assignment_status ?? '—'}</Badge>
              </dd>
            </div>
          </dl>

          {/* The ONE capacity dimension in ECOS. A null group capacity means
              unconstrained, which is stated rather than rendered as 0. */}
          <p className="text-xs text-muted-foreground" data-testid="group-loading-capacity">
            {context.group.capacity_orders === null
              ? t(($) => $.distributionWorkspace.loadingExecution.capacityUnconstrained)
              : t(($) => $.distributionWorkspace.loadingExecution.capacityOrders, {
                  group: context.group.capacity_orders,
                  vehicle: context.vehicle?.capacity_orders ?? 0,
                })}
          </p>

          <UniversalDataGrid
            data={context.products}
            columns={columns}
            rowId={(r) => String(r.product_id)}
          />
        </>
      ) : null}
    </div>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs uppercase text-muted-foreground">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  );
}
