import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Boxes } from 'lucide-react';

import { EmptyState, EntityTable, ErrorState } from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { useCurrentDistributionWindow } from '@/features/logistics/distribution-workspace/hooks/use-distribution-workspace';
import type { SlotSummary } from '@/features/logistics/distribution-workspace/types';
import { ROUTES } from '@/router/routes';

import { OpenWorkspaceLink } from './open-workspace-link';

const MAX_ROWS = 8;

/** Over-capacity, then near-capacity, then the rest — real fields, no invented status. */
function sortByOperationalPriority(groups: SlotSummary[]): SlotSummary[] {
  const priority = (g: SlotSummary) => (g.is_over_capacity ? 2 : g.is_warning ? 1 : 0);
  return [...groups].sort((a, b) => priority(b) - priority(a));
}

/**
 * Distribution Groups (Virtual Capacity Slots) — same source and same window
 * resolution as the Distribution Workspace's own Groups tab:
 * `useCurrentDistributionWindow` → `GET /logistics/distribution/windows/current`,
 * whose `data.slots` IS `GET .../windows/{window}/slots`. No parallel window
 * resolution is invented here.
 */
export function GroupsTab() {
  const { t } = useTranslation('dispatch-execution');
  const { activeWarehouseId } = useOrganizationContext();
  const { data, isLoading, isError, refetch } = useCurrentDistributionWindow(activeWarehouseId);

  const groups = useMemo(
    () => sortByOperationalPriority(data?.slots ?? []).slice(0, MAX_ROWS),
    [data],
  );

  const columns: ColumnDef<SlotSummary>[] = [
    {
      key: 'code',
      header: t($ => $.groups.columns.code),
      cell: (g) => <span className="font-medium">{g.code}</span>,
    },
    {
      key: 'name',
      header: t($ => $.groups.columns.name),
      cell: (g) => g.name ?? '—',
    },
    {
      key: 'zones',
      header: t($ => $.groups.columns.zones),
      align: 'right',
      cell: (g) => g.zones_count,
    },
    {
      key: 'orders',
      header: t($ => $.groups.columns.orders),
      align: 'right',
      cell: (g) => g.orders_count,
    },
    {
      key: 'status',
      header: t($ => $.groups.columns.status),
      cell: (g) => (
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge variant="secondary" className="capitalize">
            {g.status}
          </Badge>
          {g.is_over_capacity ? (
            <Badge variant="destructive">{t($ => $.groups.overCapacity)}</Badge>
          ) : g.is_warning ? (
            <Badge variant="outline">{t($ => $.groups.nearCapacity)}</Badge>
          ) : null}
        </div>
      ),
    },
  ];

  // "No planning window" is a real, distinct fact from "no groups" — never
  // collapsed into a generic empty table (see CurrentWindowResponse.resolution).
  const noWindow = !isLoading && !isError && data?.resolution === 'no_planning_window';

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">{t($ => $.groups.description)}</p>
        <OpenWorkspaceLink
          to={ROUTES.logisticsDistributionWorkspace}
          label={t($ => $.openFullWorkspace)}
        />
      </div>

      {noWindow ? (
        <EmptyState icon={Boxes} title={t($ => $.groups.noWindow)} />
      ) : (
        <EntityTable<SlotSummary>
          columns={columns}
          data={groups}
          getRowId={(g) => g.slot_id}
          isLoading={isLoading}
          isError={isError}
          skeletonRows={5}
          emptyState={<EmptyState icon={Boxes} title={t($ => $.groups.empty)} />}
          errorState={<ErrorState title={t($ => $.groups.loadError)} onRetry={() => refetch()} />}
        />
      )}
    </div>
  );
}
