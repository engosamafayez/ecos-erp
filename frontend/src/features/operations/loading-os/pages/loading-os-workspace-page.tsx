import { useState } from 'react';
import { useTranslation } from 'react-i18next';

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
import {
  useAllocations,
  useLoadingGroups,
  useLoadingSessions,
  useOpenReconciliation,
  useReconciliation,
  useRecordDelivery,
  useRecordReturn,
  useVehicleAssignments,
  useVehicleInventory,
} from '../hooks/use-loading-os';
import type { AllocationRecord, ReconciliationLine } from '../types/loading-os';

const EPS = 0.00005;

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

  const [slotId, setSlotId] = useState<string | null>(null);
  const [sessionId, setSessionId] = useState<string | null>(null);
  const [assignmentId, setAssignmentId] = useState<string | null>(null);

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
            { label: navLabel.item('loading-drivers') },
          ]}
          className="mb-2.5"
        />
        <h1 className="text-2xl font-semibold">{t($ => $.loadingOs.title)}</h1>
        <p className="text-muted-foreground text-sm">{t($ => $.loadingOs.subtitle)}</p>
      </header>

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
                  className={`w-full rounded-md border px-3 py-2 text-left text-sm ${
                    sessionId === s.id ? 'border-primary bg-accent' : 'border-border'
                  }`}
                >
                  <span className="font-medium">{s.session_number}</span>
                  <span className="text-muted-foreground ms-2 text-xs">{s.status}</span>
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
                    className={`w-full rounded-md border px-3 py-2 text-left text-sm ${
                      assignmentId === a.id ? 'border-primary bg-accent' : 'border-border'
                    }`}
                  >
                    <span className="font-medium">{a.vehicle_registration_snapshot}</span>
                    <span className="text-muted-foreground ml-2 text-xs">{a.status}</span>
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
              <span className="mr-3">
                {t($ => $.loadingOs.labels.loaded)}: {inventory.data.summary.total_quantity_loaded}
              </span>
              <span className="mr-3">
                {t($ => $.loadingOs.labels.delivered)}: {inventory.data.summary.total_quantity_delivered}
              </span>
              <span className="mr-3">
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
                  <TableHead className="text-right">{t($ => $.loadingOs.allocations.colAllocated)}</TableHead>
                  <TableHead className="text-right">{t($ => $.loadingOs.allocations.colDelivered)}</TableHead>
                  <TableHead className="text-right">{t($ => $.loadingOs.allocations.colRemaining)}</TableHead>
                  <TableHead>{t($ => $.loadingOs.allocations.colStatus)}</TableHead>
                  <TableHead className="text-right">{t($ => $.loadingOs.allocations.colRecordDelivery)}</TableHead>
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
  const [value, setValue] = useState<string>(String(record.quantity_delivered));
  const deliver = useRecordDelivery(sessionId, assignmentId);

  return (
    <TableRow>
      <TableCell>{record.order_number_snapshot ?? record.order_id.slice(0, 8)}</TableCell>
      <TableCell>{record.sku_snapshot ?? '—'}</TableCell>
      <TableCell className="text-right">{record.quantity_allocated}</TableCell>
      <TableCell className="text-right">{record.quantity_delivered}</TableCell>
      <TableCell className="text-right">{record.quantity_remaining}</TableCell>
      <TableCell>
        <Badge variant={record.status === 'delivered' ? 'default' : 'secondary'}>
          {record.status}
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
                <span className="mr-3">
                  {t($ => $.loadingOs.labels.loaded)}: {data.total_quantity_loaded}
                </span>
                <span className="mr-3">
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
                  <TableHead className="text-right">{t($ => $.loadingOs.labels.loaded)}</TableHead>
                  <TableHead className="text-right">{t($ => $.loadingOs.labels.delivered)}</TableHead>
                  <TableHead className="text-right">{t($ => $.loadingOs.reconciliation.colExpectedBack)}</TableHead>
                  <TableHead className="text-right">{t($ => $.loadingOs.reconciliation.colCountedBack)}</TableHead>
                  <TableHead className="text-right">{t($ => $.loadingOs.reconciliation.colVariance)}</TableHead>
                  <TableHead className="text-right">{t($ => $.loadingOs.reconciliation.colRecordReturn)}</TableHead>
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
      <TableCell className="text-right">{line.quantity_loaded}</TableCell>
      <TableCell className="text-right">{line.quantity_delivered}</TableCell>
      <TableCell className="text-right">{line.quantity_returned_expected}</TableCell>
      <TableCell className="text-right">{line.quantity_returned_actual}</TableCell>
      <TableCell className="text-right">
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
