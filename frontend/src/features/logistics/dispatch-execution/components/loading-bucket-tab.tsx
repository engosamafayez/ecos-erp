import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PackageCheck } from 'lucide-react';

import { EmptyState, EntityTable, ErrorState } from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { useLoadingGroups } from '@/features/operations/loading-os/hooks/use-loading-os';
import { bucketBadgeVariant, useBucketLabel } from '@/features/operations/loading-os/components/loading-groups';
import type {
  LoadingGroupSummary,
  LoadingWorkspaceBucket,
} from '@/features/operations/loading-os/types/loading-os';
import { ROUTES } from '@/router/routes';

import { OpenWorkspaceLink } from './open-workspace-link';

const MAX_ROWS = 8;

/**
 * Shared by the Loading tab (`bucket="current_actionable"`) and the Ready for
 * Handover tab (`bucket="waiting_driver_confirmation"`). Both read the SAME
 * source — `useLoadingGroups` → `GET /loading/groups` — and filter client-side on
 * `classification.bucket`, the backend-computed presentation enum
 * (`LoadingWorkspaceClassification`, TASK-...-WORKSPACE-READ-MODEL-004). Nothing
 * here re-derives "ready" from `loading_assignment_status` or raw quantities —
 * see `executionStateOf()` in `loading-groups.tsx`, which is the canonical
 * derivation this tab intentionally does NOT duplicate.
 *
 * `bucketBadgeVariant` / `useBucketLabel` are imported straight from that same
 * file so a bucket reads identically here and in the Loading OS workspace itself.
 */
export function LoadingBucketTab({
  bucket,
  description,
  emptyLabel,
}: {
  bucket: LoadingWorkspaceBucket;
  description: string;
  emptyLabel: string;
}) {
  const { t } = useTranslation('dispatch-execution');
  const { activeWarehouseId } = useOrganizationContext();
  const { data, isLoading, isError, refetch } = useLoadingGroups(activeWarehouseId);
  const bucketLabel = useBucketLabel();

  const groups = useMemo(() => {
    const all = data?.groups ?? [];
    return all
      .filter((g) => g.classification?.bucket === bucket)
      .sort(
        (a, b) =>
          (b.classification?.unresolved_task_count ?? 0) - (a.classification?.unresolved_task_count ?? 0),
      )
      .slice(0, MAX_ROWS);
  }, [data, bucket]);

  const columns: ColumnDef<LoadingGroupSummary>[] = [
    {
      key: 'code',
      header: t($ => $.loadingGroups.columns.code),
      cell: (g) => <span className="font-medium">{g.code}</span>,
    },
    {
      key: 'zones',
      header: t($ => $.loadingGroups.columns.zones),
      cell: (g) => (g.zone_names.length > 0 ? g.zone_names.join(' · ') : '—'),
    },
    {
      key: 'orders',
      header: t($ => $.loadingGroups.columns.orders),
      align: 'right',
      cell: (g) => g.orders_count,
    },
    {
      key: 'vehicle',
      header: t($ => $.loadingGroups.columns.vehicle),
      cell: (g) => g.transport.vehicle?.plate_number ?? t($ => $.common.notAssigned),
    },
    {
      key: 'driver',
      header: t($ => $.loadingGroups.columns.driver),
      cell: (g) => g.transport.driver?.full_name ?? t($ => $.common.notAssigned),
    },
    {
      key: 'state',
      header: t($ => $.loadingGroups.columns.state),
      cell: (g) =>
        g.classification ? (
          <Badge variant={bucketBadgeVariant(g.classification.bucket)}>
            {bucketLabel(g.classification.bucket)}
          </Badge>
        ) : (
          '—'
        ),
    },
  ];

  const noWindow = !isLoading && !isError && data?.resolution === 'no_planning_window';

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">{description}</p>
        {/* TASK-ECOS-SHIPPING-OS-REDESIGN-003 §5 — land on the exact bucket, not
            the workspace's own default tab. Loading Workspace's tab key for
            'current_actionable' is the bare 'current' (see its own WorkspaceTab
            type); every other bucket value is used as-is. */}
        <OpenWorkspaceLink
          to={`${ROUTES.loadingOsWorkspace}?tab=${bucket === 'current_actionable' ? 'current' : bucket}`}
          label={t($ => $.openFullWorkspace)}
        />
      </div>

      {noWindow ? (
        <EmptyState icon={PackageCheck} title={t($ => $.loadingGroups.noWindow)} />
      ) : (
        <EntityTable<LoadingGroupSummary>
          columns={columns}
          data={groups}
          getRowId={(g) => g.slot_id}
          isLoading={isLoading}
          isError={isError}
          skeletonRows={5}
          emptyState={<EmptyState icon={PackageCheck} title={emptyLabel} />}
          errorState={
            <ErrorState title={t($ => $.loadingGroups.loadError)} onRetry={() => refetch()} />
          }
        />
      )}
    </div>
  );
}
