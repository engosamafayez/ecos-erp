import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { UserCog } from 'lucide-react';

import { EmptyState, EntityTable, ErrorState } from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import {
  useCurrentDistributionWindow,
  useSlotsTransportSummary,
} from '@/features/logistics/distribution-workspace/hooks/use-distribution-workspace';
import type { GroupTrip } from '@/features/logistics/distribution-workspace/types';
import type { SlotSummary } from '@/features/logistics/distribution-workspace/types';
import { ROUTES } from '@/router/routes';

import { OpenWorkspaceLink } from './open-workspace-link';

const MAX_ROWS = 8;

type AssignmentRow = {
  slot: SlotSummary;
  trips: GroupTrip[];
};

/** True when at least one real Trip on this Group is missing a vehicle or a driver. */
function needsAssignment(trips: GroupTrip[]): boolean {
  return trips.length === 0 || trips.some((t) => t.vehicle === null || t.driver === null);
}

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §6 — Assignment is now operationally
 * real: each Group's actual vehicle/driver/Trip state, from
 * `useSlotsTransportSummary` (one bulk, bounded read — see that hook's own
 * docblock for why this replaced the earlier "no bulk source exists" gap
 * card). Vehicle/driver AVAILABILITY itself is never computed here — this
 * tab only displays what is already assigned; the actual assignment action,
 * and its backend-authoritative eligibility checks (busy-elsewhere,
 * loading-busy, dispatch-fitness — all server-enforced, task §7), stay on
 * the Distribution Workspace's own Vehicle & Driver drawer, which this tab
 * deep-links to rather than reimplementing.
 *
 * A Group can own more than one Trip (capacity-forced split) — this table
 * shows the first Trip's vehicle/driver/status plus a "+N" indicator rather
 * than silently picking one and hiding the rest.
 */
export function AssignmentTab() {
  const { t } = useTranslation('dispatch-execution');
  const { activeWarehouseId } = useOrganizationContext();

  const windowQuery = useCurrentDistributionWindow(activeWarehouseId);
  const windowId = windowQuery.data?.window?.id;
  const windowResolved = windowQuery.data?.resolution === 'resolved' && Boolean(windowId);

  const transportQuery = useSlotsTransportSummary(windowId, windowResolved);

  const isLoading = windowQuery.isLoading || (windowResolved && transportQuery.isLoading);
  const isError = windowQuery.isError || transportQuery.isError;

  const rows = useMemo<AssignmentRow[]>(() => {
    const slots = windowQuery.data?.slots ?? [];
    const bySlot = transportQuery.data ?? {};

    return slots
      .map((slot) => ({ slot, trips: bySlot[slot.slot_id] ?? [] }))
      .sort((a, b) => Number(needsAssignment(b.trips)) - Number(needsAssignment(a.trips)))
      .slice(0, MAX_ROWS);
  }, [windowQuery.data, transportQuery.data]);

  const columns: ColumnDef<AssignmentRow>[] = [
    {
      key: 'code',
      header: t($ => $.groups.columns.code),
      cell: ({ slot }) => <span className="font-medium">{slot.code}</span>,
    },
    {
      key: 'zones',
      header: t($ => $.groups.columns.zones),
      align: 'right',
      cell: ({ slot }) => slot.zones_count,
    },
    {
      key: 'orders',
      header: t($ => $.groups.columns.orders),
      align: 'right',
      cell: ({ slot }) => slot.orders_count,
    },
    {
      key: 'vehicle',
      header: t($ => $.loadingGroups.columns.vehicle),
      cell: ({ trips }) => {
        const first = trips[0];
        if (!first?.vehicle) return <span className="text-muted-foreground">{t($ => $.common.notAssigned)}</span>;
        return (
          <span>
            {first.vehicle.plate_number ?? first.vehicle.name}
            {trips.length > 1 ? <span className="ms-1 text-muted-foreground">+{trips.length - 1}</span> : null}
          </span>
        );
      },
    },
    {
      key: 'driver',
      header: t($ => $.loadingGroups.columns.driver),
      cell: ({ trips }) => {
        const first = trips[0];
        if (!first?.driver) return <span className="text-muted-foreground">{t($ => $.common.notAssigned)}</span>;
        return <span>{first.driver.full_name}</span>;
      },
    },
    {
      key: 'trip_status',
      header: t($ => $.assignment.columns.tripStatus),
      cell: ({ trips }) =>
        trips.length === 0 ? (
          <span className="text-muted-foreground">{t($ => $.assignment.noTripYet)}</span>
        ) : (
          <Badge variant="secondary" className="capitalize">
            {trips[0].status.replace(/_/g, ' ')}
          </Badge>
        ),
    },
    {
      key: 'blocker',
      header: t($ => $.assignment.columns.blocker),
      cell: ({ trips }) =>
        needsAssignment(trips) ? (
          <Badge variant="destructive">{t($ => $.assignment.needsAssignment)}</Badge>
        ) : (
          <Badge variant="outline">{t($ => $.assignment.assigned)}</Badge>
        ),
    },
  ];

  const noWindow = !isLoading && !isError && windowQuery.data?.resolution === 'no_planning_window';

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">{t($ => $.assignment.description)}</p>
        <OpenWorkspaceLink
          to={ROUTES.logisticsDistributionWorkspace}
          label={t($ => $.openFullWorkspace)}
        />
      </div>

      {noWindow ? (
        <EmptyState icon={UserCog} title={t($ => $.groups.noWindow)} />
      ) : (
        <EntityTable<AssignmentRow>
          columns={columns}
          data={rows}
          getRowId={(r) => r.slot.slot_id}
          isLoading={isLoading}
          isError={isError}
          skeletonRows={5}
          emptyState={<EmptyState icon={UserCog} title={t($ => $.groups.empty)} />}
          errorState={
            <ErrorState
              title={t($ => $.assignment.loadError)}
              onRetry={() => {
                void windowQuery.refetch();
                void transportQuery.refetch();
              }}
            />
          }
        />
      )}
    </div>
  );
}
