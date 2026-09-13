import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Boxes } from 'lucide-react';

import { EmptyState, ErrorState } from '@/components/crud';
import type { DataGridColumnDef } from '@/components/data-grid';
import { UniversalDataGrid } from '@/components/data-grid';
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

  const columns: DataGridColumnDef<SlotSummary>[] = [
    {
      key: 'code',
      label: t($ => $.groups.columns.code),
      cardRole: 'title',
      cell: (g) => <span className="font-medium">{g.code}</span>,
    },
    {
      key: 'name',
      label: t($ => $.groups.columns.name),
      cardRole: 'subtitle',
      cell: (g) => g.name ?? '—',
    },
    {
      key: 'zones',
      label: t($ => $.groups.columns.zones),
      align: 'end',
      cell: (g) => g.zones_count,
    },
    {
      key: 'orders',
      label: t($ => $.groups.columns.orders),
      align: 'end',
      cell: (g) => g.orders_count,
    },
    {
      key: 'status',
      label: t($ => $.groups.columns.status),
      cardRole: 'status',
      cell: (g) => (
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge variant="secondary" className="capitalize">
            {t($ => $.groups.statusDraft)}
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
        <UniversalDataGrid<SlotSummary>
          data={groups}
          columns={columns}
          rowId={(g) => g.slot_id}
          loading={isLoading}
          error={isError}
          skeletonRows={5}
          emptyState={<EmptyState icon={Boxes} title={t($ => $.groups.empty)} />}
          errorState={<ErrorState title={t($ => $.groups.loadError)} onRetry={() => refetch()} />}
        />
      )}
    </div>
  );
}
