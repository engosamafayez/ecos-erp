import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { EmptyState, ErrorState } from '@/components/crud';
import { PagePagination } from '@/components/page/pagination/page-pagination';

import { useLoadingSessionsOverview } from '../hooks/use-loading-os';
import type {
  LoadingSessionOverviewChild,
  LoadingSessionOverviewOrderRef,
  LoadingSessionOverviewRow,
  LoadingWorkspaceBucket,
} from '../types/loading-os';

import { bucketBadgeVariant, useBucketLabel, useReasonLabel } from './loading-groups';

/** Float tolerance, matching this module's own comparison epsilon (loading-groups.tsx). */
const EPS = 0.00005;

/** Orders collapse behind a count + expand toggle once there are more than this many. */
const ORDERS_INLINE_LIMIT = 2;

/**
 * Trailing-float noise (10.000000000000002) is an artefact of summing, not a fact —
 * same rounding loading-groups.tsx's own `qty()` applies to its quantities. Kept local
 * rather than imported: that helper is private to its file, and this module already
 * tolerates the same kind of small duplication between loading-groups.tsx and
 * loading-os-workspace-page.tsx (both define their own identical local `EPS`).
 */
function qty(value: number): string {
  return String(Math.round(value * 10000) / 10000);
}

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
                    <SessionOverviewTableRow key={row.session_id} row={row} bucket={bucket} />
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

function SessionOverviewTableRow({
  row,
  bucket,
}: {
  row: LoadingSessionOverviewRow;
  bucket: LoadingWorkspaceBucket;
}) {
  const { t } = useTranslation('operations');
  const reasonLabel = useReasonLabel();
  const bucketLabel = useBucketLabel();

  return (
    <TableRow data-testid={`session-overview-row-${row.session_id}`}>
      <TableCell>
        <div className="font-medium">{row.session_number}</div>
        <div className="text-muted-foreground text-xs">{row.status}</div>
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
              <AssignmentEvidenceLine key={child.vehicle_assignment_id} child={child} bucket={bucket} />
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
function AssignmentEvidenceLine({
  child,
  bucket,
}: {
  child: LoadingSessionOverviewChild;
  bucket: LoadingWorkspaceBucket;
}) {
  const { t } = useTranslation('operations');
  const reasonLabel = useReasonLabel();

  const trip = child.transport.trip;
  const vehicle = child.transport.vehicle;
  const driver = child.transport.driver;

  return (
    <li className="text-xs" data-testid={`assignment-evidence-${child.vehicle_assignment_id}`}>
      <span className="font-medium">
        {trip ? `${trip.trip_number} · ${trip.status}` : t(($) => $.loadingOs.groups.tripNotCreated)}
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

      {/*
        AUDIT DETAIL — Completed/History only.

        The fields below (Wave, Group, Orders, the three loaded/accepted/returned
        quantities, closure time, terminal reason) exist on every bucket's rows, but this
        is a read-only audit enhancement scoped to the tab whose whole job is answering
        "what happened here" — surfacing it on the other three tabs would clutter screens
        about today's actionable work instead. Gated on the panel's own `bucket` prop
        rather than `child.bucket`/`child.status`, so it tracks exactly which tab this
        row is rendered in.
      */}
      {bucket === 'completed_history' ? <AssignmentAuditDetail child={child} /> : null}
    </li>
  );
}

/**
 * Lightly humanizes a free-form backend reason code for display — underscores to
 * spaces, first letter capitalized. Deliberately NOT a translation map: new
 * cancellation-reason strings can be introduced by other flows over time (see the
 * field's own docs on `LoadingSessionOverviewChild.cancellation_reason`), and a map
 * would silently fall behind them instead of failing loudly.
 */
function humanizeReason(reason: string): string {
  const spaced = reason.replace(/_/g, ' ');
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/**
 * The Completed/History tab's audit detail for one execution: Wave, Group, the three
 * loaded/accepted/returned quantities, closure time, the terminal reason when
 * cancelled, and the Orders behind it. Everything here is evidence of what already
 * happened — read-only, no action is ever offered from this block.
 */
function AssignmentAuditDetail({ child }: { child: LoadingSessionOverviewChild }) {
  const { t } = useTranslation('operations');

  const wave = child.wave;
  const group = child.group;
  const notAssigned = t(($) => $.loadingOs.groups.notAssigned);

  return (
    <div
      className="mt-1 space-y-1 border-t border-dashed pt-1 text-muted-foreground"
      data-testid={`assignment-audit-${child.vehicle_assignment_id}`}
    >
      <div className="flex flex-wrap gap-x-3 gap-y-0.5">
        <span>
          {t(($) => $.loadingOs.workspace.audit.wave)}:{' '}
          <span className="text-foreground">
            {wave
              ? `${wave.wave_number}${
                  wave.planning_date
                    ? ` · ${new Date(wave.planning_date).toLocaleDateString(undefined, {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                      })}`
                    : ''
                }`
              : notAssigned}
          </span>
        </span>
        <span>
          {t(($) => $.loadingOs.workspace.audit.group)}:{' '}
          <span className="text-foreground">
            {group ? `${group.code}${group.name ? ` — ${group.name}` : ''}` : notAssigned}
          </span>
        </span>
      </div>

      <div className="flex flex-wrap gap-x-3 gap-y-0.5">
        <span>
          {t(($) => $.loadingOs.labels.loaded)}:{' '}
          <span className="text-foreground">{qty(child.loaded_quantity)}</span>
        </span>
        <span>
          {t(($) => $.loadingOs.workspace.audit.acceptedQty)}:{' '}
          <span className="text-foreground">{qty(child.accepted_quantity)}</span>
        </span>
        <span>
          {t(($) => $.loadingOs.workspace.audit.unacceptedReturnedQty)}:{' '}
          <span
            className={
              child.unaccepted_returned_quantity > EPS
                ? 'font-medium text-amber-600 dark:text-amber-400'
                : 'text-foreground'
            }
          >
            {qty(child.unaccepted_returned_quantity)}
          </span>
        </span>
      </div>

      <div>
        {t(($) => $.loadingOs.workspace.audit.closedAt)}:{' '}
        <span className="text-foreground">
          {child.closed_at ? new Date(child.closed_at).toLocaleString() : '—'}
        </span>
      </div>

      {child.status === 'cancelled' && child.cancellation_reason ? (
        <p
          className="text-destructive"
          data-testid={`assignment-cancellation-${child.vehicle_assignment_id}`}
        >
          {t(($) => $.loadingOs.workspace.audit.cancellationReason)}:{' '}
          {humanizeReason(child.cancellation_reason)}
        </p>
      ) : null}

      <div>
        {t(($) => $.loadingOs.workspace.audit.orders)}: <AssignmentOrdersList orders={child.orders} />
      </div>
    </div>
  );
}

/**
 * The orders behind one execution. More than a couple collapse behind a count + expand
 * toggle — the pattern this codebase already uses for a small-list-with-overflow (see
 * the Distribution board's trip card) — rather than dumping a long inline list into a
 * table cell. A `released: true` order (freed back to the pool before the driver took
 * custody — e.g. an automatic Wave-closure sweep) is marked distinctly: it is the
 * order-level fact behind this execution's "unaccepted/returned" quantity.
 */
function AssignmentOrdersList({ orders }: { orders: LoadingSessionOverviewOrderRef[] }) {
  const { t } = useTranslation('operations');
  const [expanded, setExpanded] = useState(false);

  if (orders.length === 0) {
    return <span className="text-foreground">{t(($) => $.loadingOs.workspace.audit.noOrders)}</span>;
  }

  const releasedCount = orders.filter((o) => o.released).length;
  const canCollapse = orders.length > ORDERS_INLINE_LIMIT;

  if (canCollapse && !expanded) {
    return (
      <span>
        <button
          type="button"
          className="text-foreground underline decoration-dotted underline-offset-2"
          onClick={() => setExpanded(true)}
          data-testid="assignment-orders-expand"
        >
          {t(($) => $.loadingOs.workspace.audit.ordersCount, { count: orders.length })}
        </button>
        {releasedCount > 0 ? (
          <Badge variant="outline" className="ms-1.5 text-xs font-normal">
            {t(($) => $.loadingOs.workspace.audit.releasedCount, { count: releasedCount })}
          </Badge>
        ) : null}
      </span>
    );
  }

  return (
    <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1 align-middle">
      {orders.map((order) => (
        <span key={order.order_id} className="inline-flex items-center gap-1">
          <span className="text-foreground">{order.order_number ?? order.order_id.slice(0, 8)}</span>
          {order.released ? (
            <Badge variant="outline" className="text-xs font-normal">
              {t(($) => $.loadingOs.workspace.audit.returnedToPool)}
            </Badge>
          ) : null}
        </span>
      ))}
      {canCollapse ? (
        <button
          type="button"
          className="underline decoration-dotted underline-offset-2"
          onClick={() => setExpanded(false)}
          data-testid="assignment-orders-collapse"
        >
          {t(($) => $.loadingOs.workspace.audit.showLess)}
        </button>
      ) : null}
    </span>
  );
}
