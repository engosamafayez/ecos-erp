import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';

import { useNavLabel } from '@/components/layout/use-nav-label';
import { WorkspaceBreadcrumbs } from '@/components/workspace/breadcrumbs/workspace-breadcrumbs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

import { useOrganizationContext } from '@/features/organization/context/organization-context';

import { LoadingGroupDetail, LoadingGroupList } from '../components/loading-groups';
import { LoadingSessionOverviewPanel } from '../components/loading-session-overview';
import { useBucketLabel, useLoadingSessionStatusLabel } from '../lib/loading-labels';
import {
  useAllocations,
  useLoadingGroups,
  useLoadingSessions,
  useLoadingSessionsOverview,
  useOpenReconciliation,
  useReconciliation,
  useRecordDelivery,
  useRecordReturn,
  useVehicleAssignments,
  useVehicleInventory,
} from '../hooks/use-loading-os';
import type { AllocationRecord, LoadingWorkspaceBucket, ReconciliationLine } from '../types/loading-os';

const EPS = 0.00005;

/**
 * One label per vehicle assignment status (canonical `vehicle_assignments.status`,
 * `VehicleAssignmentStatus` backend enum) — same anti-pattern fix as
 * `useLoadingSessionStatusLabel` (lib/loading-labels.ts), never the raw value.
 */
function useVehicleAssignmentStatusLabel(): (status: string) => string {
  const { t } = useTranslation('operations');

  return (status) => {
    switch (status) {
      case 'pending':
        return t(($) => $.loadingOs.assignmentStatus.pending);
      case 'loading':
        return t(($) => $.loadingOs.assignmentStatus.loading);
      case 'loading_complete':
        return t(($) => $.loadingOs.assignmentStatus.loadingComplete);
      case 'dispatched':
        return t(($) => $.loadingOs.assignmentStatus.dispatched);
      case 'returning':
        return t(($) => $.loadingOs.assignmentStatus.returning);
      case 'reconciling':
        return t(($) => $.loadingOs.assignmentStatus.reconciling);
      case 'reconciled':
        return t(($) => $.loadingOs.assignmentStatus.reconciled);
      case 'cancelled':
        return t(($) => $.loadingOs.assignmentStatus.cancelled);
      default:
        return status;
    }
  };
}

/**
 * One label per allocation record status (`AllocationRecordStatus` backend enum) — fixes
 * the same raw-status leak for the per-order allocation table below.
 */
function useAllocationRecordStatusLabel(): (status: string) => string {
  const { t } = useTranslation('operations');

  return (status) => {
    switch (status) {
      case 'allocated':
        return t(($) => $.loadingOs.allocationStatus.allocated);
      case 'confirmed':
        return t(($) => $.loadingOs.allocationStatus.confirmed);
      case 'in_delivery':
        return t(($) => $.loadingOs.allocationStatus.inDelivery);
      case 'delivered':
        return t(($) => $.loadingOs.allocationStatus.delivered);
      case 'partial_delivery':
        return t(($) => $.loadingOs.allocationStatus.partialDelivery);
      case 'failed':
        return t(($) => $.loadingOs.allocationStatus.failed);
      case 'cancelled':
        return t(($) => $.loadingOs.allocationStatus.cancelled);
      default:
        return status;
    }
  };
}

/**
 * The workspace's own top-level tabs (TASK-...-WORKSPACE-READ-MODEL-004).
 *
 * `'current'` is the EXISTING Group picker below, unchanged — it is already bounded to
 * the live planning window, i.e. genuinely actionable work, so it does not need the new
 * session-grain read model to answer "what is current". The other three ARE that read
 * model: sessions with no live Group in today's window (a stale Draft, or one already
 * past the loading phase) are otherwise invisible (Task 001 §20's proven gap).
 */
type WorkspaceTab = 'current' | Exclude<LoadingWorkspaceBucket, 'current_actionable'>;

/**
 * Operator loading workspace — GROUP grain (TASK-LOADING-GROUP-GRAIN-SYNC-IMPLEMENTATION-001).
 *
 * ┌─ WHY THE ENTRY POINT MOVED ──────────────────────────────────────────────┐
 * │ This page used to open on `GET /loading/sessions`. A Loading Session is a  │
 * │ warehouse-DAY execution artefact that cannot exist until a Vehicle does,   │
 * │ so asking for sessions first made every un-assigned Group invisible and    │
 * │ rendered "No loading sessions" while Groups sat full of orders.           │
 * │                                                                          │
 * │ The entry point is now the DISTRIBUTION GROUP. Sessions remain — they are  │
 * │ where execution is recorded — but they are a consequence, not the door.   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Execution (Session → Vehicle assignment → Allocation → Delivery → Reconciliation)
 * is UNCHANGED and still runs through `/api/loading/*`. The Group read adds the
 * planning half that was missing; it removes nothing.
 */
export function LoadingOsWorkspacePage() {
  const { t } = useTranslation('operations');
  const navLabel = useNavLabel();
  const { activeWarehouseId } = useOrganizationContext();
  const sessionStatusLabel = useLoadingSessionStatusLabel();
  const assignmentStatusLabel = useVehicleAssignmentStatusLabel();

  const [slotId, setSlotId] = useState<string | null>(null);
  const [sessionId, setSessionId] = useState<string | null>(null);
  const [assignmentId, setAssignmentId] = useState<string | null>(null);

  // TASK-ECOS-SHIPPING-OS-REDESIGN-002 §11 — Control Tower and Dispatch &
  // Execution need a precise deep link straight to a bucket (e.g. Needs
  // Review) instead of always landing on 'current'. The tab strip's own
  // click-to-switch behavior is unchanged; this only adds a URL-readable
  // initial value and keeps the URL in sync so the link is shareable/
  // bookmarkable, matching the pattern already used by every other
  // TASK-ECOS-SHIPPING-OS-REDESIGN-001 workspace (`?tab=`).
  const [searchParams, setSearchParams] = useSearchParams();
  const WORKSPACE_TABS = ['current', 'waiting_driver_confirmation', 'needs_review', 'completed_history'] as const;
  function isWorkspaceTab(value: string | null): value is WorkspaceTab {
    return (WORKSPACE_TABS as readonly string[]).includes(value ?? '');
  }
  const [tab, setTabState] = useState<WorkspaceTab>(
    isWorkspaceTab(searchParams.get('tab')) ? (searchParams.get('tab') as WorkspaceTab) : 'current',
  );
  function setTab(next: WorkspaceTab) {
    setTabState(next);
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (next === 'current') {
          params.delete('tab');
        } else {
          params.set('tab', next);
        }
        return params;
      },
      { replace: true },
    );
  }
  const bucketLabel = useBucketLabel();

  // Counts ONLY — page 1 of every bucket, purely to label the tab strip. Each tab's own
  // panel fetches its own page independently; this is not "load everything and filter".
  const overviewCounts = useLoadingSessionsOverview(activeWarehouseId, undefined, 1);

  /*
   * The Groups read goes through the LOADING-side route (`/api/loading/groups`), not
   * the Distribution one. Same canonical data, but gated on
   * `operations.preparation.view` — the permission Warehouse Operator, Warehouse
   * Manager and Preparation Supervisor hold. Reading it through Distribution would
   * have 403'd for exactly the roles this screen exists to serve.
   */
  const groupsQuery = useLoadingGroups(activeWarehouseId);
  const sessions = useLoadingSessions();
  const assignments = useVehicleAssignments(sessionId);

  const groups = groupsQuery.data?.groups ?? [];
  const hasWindow = groupsQuery.data?.resolution === 'resolved';

  return (
    <div className="space-y-6 p-6">
      <header>
        {/* Operations -> Loading Drivers. Loading is a peer of Distributor Orders in the
            approved tree, not a child of it, so the trail names no distribution step
            (TASK-OPERATIONS-DISTRIBUTOR-ORDERS-LOADING-001 §11). */}
        <WorkspaceBreadcrumbs
          crumbs={[
            { label: navLabel.group('operations') },
            { label: navLabel.item('loading-workspace') },
          ]}
          className="mb-2.5"
        />
        <h1 className="text-2xl font-semibold">{t($ => $.loadingOs.title)}</h1>
        <p className="text-muted-foreground text-sm">{t($ => $.loadingOs.subtitle)}</p>
      </header>

      {/*
        WORKSPACE TABS — TASK-...-WORKSPACE-READ-MODEL-004.

        A hand-rolled button strip with per-tab counts, matching the convention this
        codebase already uses for tabs-plus-filters screens (ShippingOrdersPage) rather
        than the Radix Tabs primitive, so an operator answers "what is loading now / what
        is waiting for the driver / what has completed / what needs investigation" from
        one glance without opening anything.
      */}
      <div className="flex flex-wrap gap-2" role="tablist">
        {(['current', 'waiting_driver_confirmation', 'needs_review', 'completed_history'] as const).map(
          (tabKey) => {
            const count =
              tabKey === 'current' ? groups.length : (overviewCounts.data?.counts[tabKey] ?? 0);

            return (
              <button
                key={tabKey}
                type="button"
                role="tab"
                aria-selected={tab === tabKey}
                data-testid={`workspace-tab-${tabKey}`}
                onClick={() => setTab(tabKey)}
                className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                  tab === tabKey
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent'
                }`}
              >
                {tabKey === 'current' ? t($ => $.loadingOs.workspace.tabs.currentActionable) : bucketLabel(tabKey)}
                <span className="ms-1.5 tabular-nums opacity-80">{count}</span>
              </button>
            );
          },
        )}
      </div>

      {tab !== 'current' ? (
        <LoadingSessionOverviewPanel bucket={tab} warehouseId={activeWarehouseId} />
      ) : (
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[320px_1fr]">
        {/* Group picker (entry point) → session + assignment picker (execution) */}
        <div className="space-y-4">
          <LoadingGroupList
            groups={groups}
            selectedSlotId={slotId}
            onSelect={(id) => setSlotId(id)}
            isLoading={groupsQuery.isLoading}
            isError={groupsQuery.isError}
            hasWindow={hasWindow}
          />

          {/*
            Sessions are rendered ONLY when they exist. The old unconditional card
            announced "No loading sessions" as the page's headline fact, which was
            true about the execution table and false about the operator's actual
            question. With Groups as the entry point, an absent session is not news —
            the Group's own execution panel already explains why there is none.
          */}
          {sessions.data && sessions.data.length > 0 ? (
          <Card>
            <CardHeader>
              <CardTitle>{t($ => $.loadingOs.sessions.title)}</CardTitle>
              <CardDescription>{t($ => $.loadingOs.sessions.description)}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-1">
              {sessions.data.map((s) => (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => {
                    setSessionId(s.id);
                    setAssignmentId(null);
                  }}
                  className={`w-full rounded-md border px-3 py-2 text-start text-sm ${
                    sessionId === s.id ? 'border-primary bg-accent' : 'border-border'
                  }`}
                >
                  <span className="font-medium">{s.session_number}</span>
                  <span className="text-muted-foreground ms-2 text-xs">{sessionStatusLabel(s.status)}</span>
                </button>
              ))}
            </CardContent>
          </Card>
          ) : null}

          {sessionId && (
            <Card>
              <CardHeader>
                <CardTitle>{t($ => $.loadingOs.vehicles.title)}</CardTitle>
                <CardDescription>{t($ => $.loadingOs.vehicles.description)}</CardDescription>
              </CardHeader>
              <CardContent className="space-y-1">
                {assignments.isLoading && (
                  <p className="text-muted-foreground text-sm">{t($ => $.loadingOs.sessions.loading)}</p>
                )}
                {assignments.data?.length === 0 && (
                  <p className="text-muted-foreground text-sm">{t($ => $.loadingOs.vehicles.empty)}</p>
                )}
                {assignments.data?.map((a) => (
                  <button
                    key={a.id}
                    type="button"
                    onClick={() => setAssignmentId(a.id)}
                    className={`w-full rounded-md border px-3 py-2 text-start text-sm ${
                      assignmentId === a.id ? 'border-primary bg-accent' : 'border-border'
                    }`}
                  >
                    <span className="font-medium">{a.vehicle_registration_snapshot}</span>
                    <span className="text-muted-foreground ms-2 text-xs">{assignmentStatusLabel(a.status)}</span>
                  </button>
                ))}
              </CardContent>
            </Card>
          )}
        </div>

        {/* Working area — the Group's demand first, execution below it. */}
        <div className="space-y-6">
          {slotId ? (
            <LoadingGroupDetail slotId={slotId} />
          ) : (
            <Card>
              <CardContent className="text-muted-foreground py-12 text-center text-sm">
                {t($ => $.loadingOs.groups.placeholder)}
              </CardContent>
            </Card>
          )}

          {/* Execution stays exactly as it was — reached once a session and vehicle
              assignment exist, never a precondition for the panel above. */}
          {sessionId && assignmentId ? (
            <AssignmentWorkspace sessionId={sessionId} assignmentId={assignmentId} />
          ) : null}
        </div>
      </div>
      )}
    </div>
  );
}

function AssignmentWorkspace({
  sessionId,
  assignmentId,
}: {
  sessionId: string;
  assignmentId: string;
}) {
  const { t } = useTranslation('operations');
  const allocations = useAllocations(sessionId, assignmentId);
  const inventory = useVehicleInventory(sessionId, assignmentId);

  return (
    <>
      {inventory.data && (
        <Card>
          <CardHeader>
            <CardTitle>
              {t($ => $.loadingOs.inventory.title)} — {inventory.data.summary.assignment_number}
            </CardTitle>
            <CardDescription>
              <span className="me-3">
                {t($ => $.loadingOs.labels.loaded)}: {inventory.data.summary.total_quantity_loaded}
              </span>
              <span className="me-3">
                {t($ => $.loadingOs.labels.delivered)}: {inventory.data.summary.total_quantity_delivered}
              </span>
              <span className="me-3">
                {t($ => $.loadingOs.labels.returned)}: {inventory.data.summary.total_quantity_returned}
              </span>
              <span>
                {t($ => $.loadingOs.labels.onHand)}: {inventory.data.summary.total_quantity_on_hand}
              </span>
            </CardDescription>
          </CardHeader>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>{t($ => $.loadingOs.allocations.title)}</CardTitle>
          <CardDescription>{t($ => $.loadingOs.allocations.description)}</CardDescription>
        </CardHeader>
        <CardContent>
          {allocations.isLoading && (
            <p className="text-muted-foreground text-sm">{t($ => $.loadingOs.sessions.loading)}</p>
          )}
          {allocations.data?.length === 0 && (
            <p className="text-muted-foreground text-sm">{t($ => $.loadingOs.allocations.empty)}</p>
          )}
          {allocations.data && allocations.data.length > 0 && (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t($ => $.loadingOs.allocations.colOrder)}</TableHead>
                  <TableHead>{t($ => $.loadingOs.allocations.colSku)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.allocations.colAllocated)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.allocations.colDelivered)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.allocations.colRemaining)}</TableHead>
                  <TableHead>{t($ => $.loadingOs.allocations.colStatus)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.allocations.colRecordDelivery)}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {allocations.data.map((record) => (
                  <AllocationRow
                    key={record.id}
                    sessionId={sessionId}
                    assignmentId={assignmentId}
                    record={record}
                  />
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <ReconciliationPanel sessionId={sessionId} assignmentId={assignmentId} />
    </>
  );
}

function AllocationRow({
  sessionId,
  assignmentId,
  record,
}: {
  sessionId: string;
  assignmentId: string;
  record: AllocationRecord;
}) {
  const { t } = useTranslation('operations');
  const allocationStatusLabel = useAllocationRecordStatusLabel();
  const [value, setValue] = useState<string>(String(record.quantity_delivered));
  const deliver = useRecordDelivery(sessionId, assignmentId);

  return (
    <TableRow>
      <TableCell>{record.order_number_snapshot ?? record.order_id.slice(0, 8)}</TableCell>
      <TableCell>{record.sku_snapshot ?? '—'}</TableCell>
      <TableCell className="text-end">{record.quantity_allocated}</TableCell>
      <TableCell className="text-end">{record.quantity_delivered}</TableCell>
      <TableCell className="text-end">{record.quantity_remaining}</TableCell>
      <TableCell>
        <Badge variant={record.status === 'delivered' ? 'default' : 'secondary'}>
          {allocationStatusLabel(record.status)}
        </Badge>
      </TableCell>
      <TableCell>
        <div className="flex items-center justify-end gap-2">
          <Input
            type="number"
            min={0}
            step="0.001"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            className="h-8 w-24"
          />
          <Button
            size="sm"
            disabled={deliver.isPending}
            onClick={() =>
              deliver.mutate({
                allocation_record_id: record.id,
                quantity_delivered: Number(value),
              })
            }
          >
            {t($ => $.loadingOs.allocations.deliver)}
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
}

function ReconciliationPanel({
  sessionId,
  assignmentId,
}: {
  sessionId: string;
  assignmentId: string;
}) {
  const { t } = useTranslation('operations');
  const reconciliation = useReconciliation(sessionId, assignmentId);
  const open = useOpenReconciliation(sessionId, assignmentId);

  const data = reconciliation.data;

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between">
        <div>
          <CardTitle>{t($ => $.loadingOs.reconciliation.title)}</CardTitle>
          <CardDescription>{t($ => $.loadingOs.reconciliation.description)}</CardDescription>
        </div>
        <Button
          variant="outline"
          size="sm"
          disabled={open.isPending}
          onClick={() => open.mutate()}
        >
          {data ? t($ => $.loadingOs.reconciliation.refresh) : t($ => $.loadingOs.reconciliation.open)}
        </Button>
      </CardHeader>
      <CardContent>
        {!data && (
          <p className="text-muted-foreground text-sm">{t($ => $.loadingOs.reconciliation.notOpened)}</p>
        )}
        {data && (
          <div className="space-y-4">
            <div className="flex items-center gap-3">
              <span className="text-sm">
                <span className="me-3">
                  {t($ => $.loadingOs.labels.loaded)}: {data.total_quantity_loaded}
                </span>
                <span className="me-3">
                  {t($ => $.loadingOs.labels.delivered)}: {data.total_quantity_delivered}
                </span>
                <span>
                  {t($ => $.loadingOs.labels.returned)}: {data.total_quantity_returned}
                </span>
              </span>
              <Badge variant={Math.abs(data.total_variance) <= EPS ? 'default' : 'destructive'}>
                {t($ => $.loadingOs.reconciliation.variance)}: {data.total_variance}
              </Badge>
            </div>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t($ => $.loadingOs.allocations.colSku)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.labels.loaded)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.labels.delivered)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.reconciliation.colExpectedBack)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.reconciliation.colCountedBack)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.reconciliation.colVariance)}</TableHead>
                  <TableHead className="text-end">{t($ => $.loadingOs.reconciliation.colRecordReturn)}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.lines.map((line) => (
                  <ReconciliationRow
                    key={line.id}
                    sessionId={sessionId}
                    assignmentId={assignmentId}
                    line={line}
                  />
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function ReconciliationRow({
  sessionId,
  assignmentId,
  line,
}: {
  sessionId: string;
  assignmentId: string;
  line: ReconciliationLine;
}) {
  const { t } = useTranslation('operations');
  const [value, setValue] = useState<string>(String(line.quantity_returned_actual));
  const recordReturn = useRecordReturn(sessionId, assignmentId);

  return (
    <TableRow>
      <TableCell>{line.sku_snapshot ?? '—'}</TableCell>
      <TableCell className="text-end">{line.quantity_loaded}</TableCell>
      <TableCell className="text-end">{line.quantity_delivered}</TableCell>
      <TableCell className="text-end">{line.quantity_returned_expected}</TableCell>
      <TableCell className="text-end">{line.quantity_returned_actual}</TableCell>
      <TableCell className="text-end">
        <span className={Math.abs(line.variance) <= EPS ? '' : 'text-destructive font-medium'}>
          {line.variance}
        </span>
      </TableCell>
      <TableCell>
        <div className="flex items-center justify-end gap-2">
          <Input
            type="number"
            min={0}
            step="0.001"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            className="h-8 w-24"
          />
          <Button
            size="sm"
            variant="outline"
            disabled={recordReturn.isPending}
            onClick={() => recordReturn.mutate({ lineId: line.id, quantity: Number(value) })}
          >
            {t($ => $.loadingOs.reconciliation.save)}
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
}
