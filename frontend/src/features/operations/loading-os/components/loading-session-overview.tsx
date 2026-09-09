import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { EmptyState, ErrorState } from '@/components/crud';
import { PagePagination } from '@/components/page/pagination/page-pagination';

import { useLoadingSessionsOverview } from '../hooks/use-loading-os';
import type { LoadingSessionOverviewChild, LoadingSessionOverviewRow, LoadingWorkspaceBucket } from '../types/loading-os';

import {
  bucketBadgeVariant,
  useBucketLabel,
  useLoadingSessionStatusLabel,
  useLoadingTripStatusLabel,
  useReasonLabel,
} from './loading-groups';

/**
 * The LoadingSession-grain read model's presentation — TASK-...-WORKSPACE-READ-MODEL-004.
 *
 * ┌─ WHY THIS EXISTS ALONGSIDE THE GROUP PICKER ──────────────────────────────┐
 * │ `LoadingGroupList`/`LoadingGroupDetail` are bounded to the current planning │
 * │ window — a LoadingSession with no live Group there (a historical Draft, or  │
 * │ one the window has moved past) is otherwise invisible (Task 001 §20's proven│
 * │ gap). This panel is the operator's window into THAT set, always server-      │
 * │ classified and server-paginated — it never fetches "everything" and filters │
 * │ in React (task §20).                                                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
export function LoadingSessionOverviewPanel({
  bucket,
  warehouseId,
}: {
  bucket: LoadingWorkspaceBucket;
  warehouseId: string | null;
}) {
  const { t } = useTranslation('operations');
  const bucketLabel = useBucketLabel();
  const [page, setPage] = useState(1);
  const query = useLoadingSessionsOverview(warehouseId, bucket, page);

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{bucketLabel(bucket)}</CardTitle>
        <CardDescription>{t(($) => $.loadingOs.workspace.overviewDescription)}</CardDescription>
      </CardHeader>
      <CardContent>
        {query.isLoading ? (
          <p className="text-muted-foreground text-sm" data-testid="session-overview-loading">
            {t(($) => $.loadingOs.groups.loading)}
          </p>
        ) : query.isError ? (
          <ErrorState onRetry={() => query.refetch()} />
        ) : rows.length === 0 ? (
          <EmptyState title={t(($) => $.loadingOs.workspace.overviewEmpty)} />
        ) : (
          <div className="space-y-4">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t(($) => $.loadingOs.workspace.columns.session)}</TableHead>
                    <TableHead>{t(($) => $.loadingOs.workspace.columns.date)}</TableHead>
                    <TableHead>{t(($) => $.loadingOs.workspace.columns.status)}</TableHead>
                    <TableHead>{t(($) => $.loadingOs.workspace.columns.assignments)}</TableHead>
                    <TableHead>{t(($) => $.loadingOs.workspace.columns.reasons)}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => (
                    <SessionOverviewTableRow key={row.session_id} row={row} />
                  ))}
                </TableBody>
              </Table>
            </div>

            {meta ? (
              <PagePagination
                page={meta.current_page}
                perPage={meta.per_page}
                total={meta.total}
                lastPage={meta.last_page}
                onPageChange={setPage}
                isLoading={query.isFetching}
              />
            ) : null}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function SessionOverviewTableRow({ row }: { row: LoadingSessionOverviewRow }) {
  const { t } = useTranslation('operations');
  const reasonLabel = useReasonLabel();
  const bucketLabel = useBucketLabel();
  const sessionStatusLabel = useLoadingSessionStatusLabel();

  return (
    <TableRow data-testid={`session-overview-row-${row.session_id}`}>
      <TableCell>
        <div className="font-medium">{row.session_number}</div>
        <div className="text-muted-foreground text-xs">{sessionStatusLabel(row.status)}</div>
      </TableCell>
      <TableCell>{row.operational_date ?? '—'}</TableCell>
      <TableCell>
        <Badge variant={bucketBadgeVariant(row.bucket)}>{bucketLabel(row.bucket)}</Badge>
      </TableCell>
      <TableCell>
        {row.assignments.length === 0 ? (
          <span className="text-muted-foreground text-xs">
            {t(($) => $.loadingOs.workspace.noAssignments)}
          </span>
        ) : (
          <ul className="space-y-1">
            {row.assignments.map((child) => (
              <AssignmentEvidenceLine key={child.vehicle_assignment_id} child={child} />
            ))}
          </ul>
        )}
      </TableCell>
      <TableCell>
        <div className="flex flex-wrap gap-1">
          {row.reasons.map((reason) => (
            <Badge key={reason} variant="outline" className="text-xs font-normal">
              {reasonLabel(reason)}
            </Badge>
          ))}
        </div>
      </TableCell>
    </TableRow>
  );
}

/**
 * One child assignment's evidence — Trip/Vehicle/Driver where available, task/custody
 * counts, and its own reasons — per the task's "Manual Review Evidence Presentation" ask.
 * Read-only: this row has no action, deliberately (task §23 — no repair path here).
 */
function AssignmentEvidenceLine({ child }: { child: LoadingSessionOverviewChild }) {
  const { t } = useTranslation('operations');
  const reasonLabel = useReasonLabel();
  const tripStatusLabel = useLoadingTripStatusLabel();

  const trip = child.transport.trip;
  const vehicle = child.transport.vehicle;
  const driver = child.transport.driver;

  return (
    <li className="text-xs" data-testid={`assignment-evidence-${child.vehicle_assignment_id}`}>
      <span className="font-medium">
        {trip ? `${trip.trip_number} · ${tripStatusLabel(trip.status)}` : t(($) => $.loadingOs.groups.tripNotCreated)}
      </span>
      {' — '}
      <span className="text-muted-foreground">
        {vehicle?.plate_number ?? t(($) => $.loadingOs.groups.notAssigned)}
        {' · '}
        {driver?.full_name ?? t(($) => $.loadingOs.groups.notAssigned)}
        {' · '}
        {child.task_count} {t(($) => $.loadingOs.workspace.tasksLabel)}
        {child.unresolved_task_count > 0
          ? ` · ${child.unresolved_task_count} ${t(($) => $.loadingOs.workspace.unresolvedLabel)}`
          : ''}
      </span>
      {child.reasons.length > 0 ? (
        <span className="text-muted-foreground">
          {' ('}
          {child.reasons.map((r) => reasonLabel(r)).join(', ')}
          {')'}
        </span>
      ) : null}
    </li>
  );
}
