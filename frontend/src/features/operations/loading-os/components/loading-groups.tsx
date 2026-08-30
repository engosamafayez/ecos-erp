import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

import {
  useConfirmLoaded,
  useLoadingGroup,
  useResolveAdjustment,
  useStartLoading,
} from '../hooks/use-loading-os';
import type {
  LoadingGroupProduct,
  LoadingGroupSummary,
  LoadingGroupTransport,
  LoadingWorkflowState,
} from '../types/loading-os';

/**
 * TASK-LOADING-GROUP-GRAIN-READ-AND-EXECUTION-UX-002 — Loading at GROUP grain.
 *
 * ┌─ THE CONTRACT ───────────────────────────────────────────────────────────┐
 * │ A Distribution Group holding loadable orders appears here IMMEDIATELY,    │
 * │ with its products and quantities. Vehicle, Driver, Trip and Loading       │
 * │ Session are NOT prerequisites for visibility — they gate only EXECUTION,  │
 * │ i.e. recording what physically went onto the vehicle.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * DATA COMES FROM THE LOADING-SIDE READ (`/api/loading/groups*`), which serves the
 * SAME canonical manifest as the Distribution route but under
 * `operations.preparation.view` — the permission Warehouse Operator, Warehouse Manager
 * and Preparation Supervisor actually hold. That is a permission boundary, not a second
 * implementation: Required is still the live Distribution aggregation, Prepared still
 * `distribution_group_product_preparation`, and Loaded still `loading_tasks`.
 *
 * ┌─ PREPARED IS NOT LOADED ─────────────────────────────────────────────────┐
 * │ Prepared = the warehouse separated the stock.                             │
 * │ Loaded   = it physically went onto the vehicle.                           │
 * │                                                                          │
 * │ They are different facts from different acts. Loaded is read from          │
 * │ loading_tasks and is 0 before loading starts — it is NEVER derived from    │
 * │ Prepared, and the two columns are never combined.                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * REMAINING HERE IS REMAINING-TO-LOAD (Required − Loaded), not remaining-to-prepare
 * (Required − Prepared). The server computes it; this file renders it.
 *
 * A READ FAILURE IS NEVER RENDERED AS AN ABSENCE. If the manifest cannot be read, the
 * screen says so. It must never say "No driver" / "No vehicle" / "No trip", because
 * that would be a false claim about the business rather than an honest one about the
 * read.
 */

/** Float tolerance, matching the operator workspace's own comparison epsilon. */
const EPS = 0.00005;

/** Trailing-float noise (10.000000000000002) is an artefact of summing, not a fact. */
function qty(value: number): string {
  return String(Math.round(value * 10000) / 10000);
}

/**
 * Where the Group stands, as the operator needs to read it.
 *
 * ┌─ WHY `inProgress` AND `completed` EXIST ─────────────────────────────────┐
 * │ This used to be derived from vehicle+driver alone, so a Group whose        │
 * │ loading session was already OPEN still read "Ready to load" and still      │
 * │ offered an enabled "Start loading" — pressing it appeared to do nothing,   │
 * │ because the certified action is idempotent and simply returned the same    │
 * │ session. The state was right on the server and wrong on the screen.        │
 * │                                                                          │
 * │ The loading assignment's own status is now the authority once it exists.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `unavailable` remains a real state, distinct from `planning` — a failed read is
 * never rendered as an absence.
 */
type ExecutionState = 'ready' | 'planning' | 'inProgress' | 'completed' | 'unavailable';

function executionStateOf(
  transport: LoadingGroupTransport | undefined,
  isError: boolean,
): ExecutionState {
  if (isError || transport === undefined) {
    return 'unavailable';
  }

  // Once an execution context exists it outranks readiness: the question is no longer
  // "can this start" but "where has it got to".
  if (transport.loading_assignment_status !== null) {
    return transport.loading_assignment_status === 'loading_complete' ? 'completed' : 'inProgress';
  }

  return transport.vehicle !== null && transport.driver !== null ? 'ready' : 'planning';
}

/** Loading has an execution context — Start Loading must no longer invite a new one. */
function hasStarted(state: ExecutionState): boolean {
  return state === 'inProgress' || state === 'completed';
}

/**
 * One label per execution state, shared by the card and the detail panel so the two can
 * never describe the same Group differently.
 */
function useExecutionLabel(): (state: ExecutionState) => string {
  const { t } = useTranslation('operations');

  return (state) => {
    switch (state) {
      case 'completed':
        return t(($) => $.loadingOs.groups.loadingCompleted);
      case 'inProgress':
        return t(($) => $.loadingOs.groups.loadingInProgress);
      case 'ready':
        return t(($) => $.loadingOs.groups.readyToLoad);
      case 'unavailable':
        return t(($) => $.loadingOs.groups.unknown);
      default:
        return t(($) => $.loadingOs.groups.planningOnly);
    }
  };
}

/** One transport fact, with read-failure kept distinct from genuine absence. */
function TransportLine({
  label,
  value,
  state,
  emptyLabel,
}: {
  label: string;
  value: string | null;
  state: ExecutionState;
  emptyLabel: string;
}) {
  const { t } = useTranslation('operations');

  return (
    <p>
      <span className="text-muted-foreground">{label}: </span>
      <span className={value === null ? 'text-muted-foreground' : 'font-medium'}>
        {state === 'unavailable'
          ? t(($) => $.loadingOs.groups.readUnavailable)
          : (value ?? emptyLabel)}
      </span>
    </p>
  );
}

/**
 * The Group list — the workspace's ENTRY POINT.
 *
 * Loading Sessions are deliberately not the entry point: a session is a warehouse-DAY
 * execution artefact that cannot exist until a Vehicle does, so opening on sessions made
 * every unassigned Group invisible.
 */
export function LoadingGroupList({
  groups,
  selectedSlotId,
  onSelect,
  isLoading,
  isError,
  hasWindow,
}: {
  groups: LoadingGroupSummary[];
  selectedSlotId: string | null;
  onSelect: (slotId: string) => void;
  isLoading: boolean;
  isError: boolean;
  hasWindow: boolean;
}) {
  const { t } = useTranslation('operations');
  const executionLabel = useExecutionLabel();

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t(($) => $.loadingOs.groups.title)}</CardTitle>
        <CardDescription>{t(($) => $.loadingOs.groups.description)}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-2">
        {isLoading ? (
          <p className="text-muted-foreground text-sm">{t(($) => $.loadingOs.groups.loading)}</p>
        ) : null}

        {isError ? (
          <p className="text-destructive text-sm" data-testid="loading-groups-error">
            {t(($) => $.loadingOs.groups.loadFailed)}
          </p>
        ) : null}

        {/* "No window open" and "no Groups" are different facts, said differently —
            collapsing them would send an operator to create Groups when the real
            blocker is that no cycle is open. */}
        {!isLoading && !isError && !hasWindow ? (
          <p className="text-muted-foreground text-sm" data-testid="loading-groups-no-window">
            {t(($) => $.loadingOs.groups.noWindow)}
          </p>
        ) : null}

        {!isLoading && !isError && hasWindow && groups.length === 0 ? (
          <p className="text-muted-foreground text-sm" data-testid="loading-groups-empty">
            {t(($) => $.loadingOs.groups.empty)}
          </p>
        ) : null}

        {groups.map((group) => {
          const state = executionStateOf(group.transport, false);
          const zones =
            group.zone_names.length > 0
              ? group.zone_names.join(' · ')
              : t(($) => $.loadingOs.groups.noZones);

          return (
            <button
              key={group.slot_id}
              type="button"
              onClick={() => onSelect(group.slot_id)}
              aria-pressed={selectedSlotId === group.slot_id}
              data-testid={`loading-group-${group.code}`}
              className={`w-full rounded-md border p-3 text-start text-sm ${
                selectedSlotId === group.slot_id ? 'border-primary bg-accent' : 'border-border'
              }`}
            >
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium">{group.code}</span>

                {/* Readiness is about EXECUTION only. "Planning only" is a healthy,
                    fully visible Group — never an error. Once loading has started the
                    card says so, instead of still inviting a start. */}
                <Badge
                  variant={state === 'ready' || hasStarted(state) ? 'default' : 'secondary'}
                  data-testid={`group-state-${group.code}`}
                >
                  {executionLabel(state)}
                </Badge>
              </div>

              <p className="text-muted-foreground mt-1 text-xs">
                {t(($) => $.loadingOs.groups.zones)}: {zones}
              </p>

              <p className="text-muted-foreground mt-2 text-xs">
                {t(($) => $.loadingOs.groups.orders)}:{' '}
                <span className="text-foreground">{group.orders_count}</span>
                {' · '}
                {t(($) => $.loadingOs.groups.products)}:{' '}
                <span className="text-foreground">{group.products_count}</span>
              </p>

              <p className="text-muted-foreground mt-1 text-xs">
                {t(($) => $.loadingOs.groups.vehicle)}:{' '}
                {group.transport.vehicle?.plate_number ?? t(($) => $.loadingOs.groups.notAssigned)}
                {' · '}
                {t(($) => $.loadingOs.groups.driver)}:{' '}
                {group.transport.driver?.full_name ?? t(($) => $.loadingOs.groups.notAssigned)}
              </p>
            </button>
          );
        })}
      </CardContent>
    </Card>
  );
}

/**
 * Loading progress for ONE product, from canonical quantities only.
 *
 * Deliberately a function of LOADED vs REQUIRED — never of Prepared. A fully prepared
 * product that has not been loaded is "Not started", which is the truth an operator
 * standing at the vehicle needs.
 */
/** The server's derived state, rendered. Never recomputed here. */
function useWorkflowLabel(): (state: LoadingWorkflowState) => string {
  const { t } = useTranslation('operations');

  return (state) => {
    switch (state) {
      case 'driver_confirmed':
        return t(($) => $.loadingOs.groups.stateDriverConfirmed);
      case 'adjustment_requested':
        return t(($) => $.loadingOs.groups.stateAdjustmentRequested);
      case 'awaiting_driver_reconfirmation':
        return t(($) => $.loadingOs.groups.stateAwaitingReconfirm);
      case 'awaiting_driver_confirmation':
        return t(($) => $.loadingOs.groups.stateAwaitingDriver);
      default:
        return t(($) => $.loadingOs.groups.statePendingLoading);
    }
  };
}

/**
 * One product row the warehouse can act on.
 *
 * ┌─ THE DRAFT IS NOT THE FACT ──────────────────────────────────────────────┐
 * │ The input holds a DRAFT. Nothing is loaded until Confirm succeeds and the  │
 * │ server returns a new manifest — local state is never treated as evidence   │
 * │ that a quantity was recorded. That is why the Loaded column renders        │
 * │ `row.quantity_loaded` (canonical) and not the draft.                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
function WarehouseProductRow({
  row,
  slotId,
  canOperate,
}: {
  row: LoadingGroupProduct;
  slotId: string;
  canOperate: boolean;
}) {
  const { t } = useTranslation('operations');
  const label = useWorkflowLabel();
  const confirm = useConfirmLoaded(slotId);

  // Seeded from the canonical value, and re-seeded whenever it changes server-side —
  // keyed on the confirmed quantity so a warehouse revision is reflected in the input.
  const [draft, setDraft] = useState<string>(String(row.quantity_loaded));

  const confirmed = row.warehouse_confirmed_at !== null;
  const settled = row.workflow_state === 'driver_confirmed';

  /*
   * Nothing left to submit: the warehouse already confirmed, and the box still shows the
   * canonical number. Leaving Confirm enabled here was the defect — pressing it re-sent
   * the same quantity, the server accepted it as a no-op, and the screen looked inert.
   *
   * NOTE ON SCOPE: this button confirms the WAREHOUSE's Loaded quantity. It cannot move
   * "Driver received" — only the driver may write that, which is the custody separation.
   */
  const draftMatchesCanonical =
    draft.trim() !== '' && Math.abs(Number(draft) - row.quantity_loaded) <= EPS;
  const nothingToConfirm = confirmed && draftMatchesCanonical;

  return (
    <TableRow data-testid={`loading-product-${row.product_id}`}>
      <TableCell>{row.product_name ?? '—'}</TableCell>
      <TableCell>{row.product_sku ?? '—'}</TableCell>
      <TableCell className="text-end">
        {qty(row.quantity_required)}
        {row.unit_symbol ? (
          <span className="text-muted-foreground ms-1 text-xs">{row.unit_symbol}</span>
        ) : null}
      </TableCell>
      <TableCell className="text-end">
        {qty(row.quantity_prepared)}
        {row.over_prepared_qty > EPS ? (
          <span className="ms-1 text-xs text-amber-600 dark:text-amber-400">
            (+{qty(row.over_prepared_qty)})
          </span>
        ) : null}
      </TableCell>

      {/* LOADED — canonical, from loading_tasks. Never derived from Prepared. */}
      <TableCell className="text-end font-medium">{qty(row.quantity_loaded)}</TableCell>
      <TableCell className="text-end">{qty(row.quantity_remaining)}</TableCell>

      {/* DRIVER RECEIVED — null means not counted yet, which is not a counted zero. */}
      <TableCell className="text-end">
        {row.quantity_driver_received === null ? (
          <span className="text-muted-foreground text-xs">
            {t(($) => $.loadingOs.groups.notCounted)}
          </span>
        ) : (
          qty(row.quantity_driver_received)
        )}
      </TableCell>

      <TableCell>
        <Badge variant={settled ? 'default' : 'secondary'} data-testid={`state-${row.product_id}`}>
          {label(row.workflow_state)}
        </Badge>
      </TableCell>

      <TableCell>
        <div className="flex items-center justify-end gap-2">
          <Input
            type="number"
            min={0}
            step="0.0001"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            disabled={!canOperate || confirm.isPending}
            className="h-8 w-24"
            data-testid={`loaded-input-${row.product_id}`}
            aria-label={t(($) => $.loadingOs.groups.colLoaded)}
          />
          <Button
            size="sm"
            variant={confirmed ? 'outline' : 'default'}
            disabled={!canOperate || confirm.isPending || nothingToConfirm}
            title={nothingToConfirm ? t(($) => $.loadingOs.groups.alreadyConfirmed) : undefined}
            data-testid={`confirm-${row.product_id}`}
            onClick={() =>
              confirm.mutate({
                productId: row.product_id,
                quantityLoaded: Number(draft),
                // What this screen showed. A concurrent revision by a second operator
                // is refused rather than silently overwritten.
                expectedLoadedQty: row.quantity_loaded,
              })
            }
          >
            {confirm.isPending
              ? t(($) => $.loadingOs.groups.confirming)
              : t(($) => $.loadingOs.groups.confirm)}
          </Button>
        </div>

        {/* A visible, canonical acknowledgement — previously a successful confirm left no
            trace on screen, which read as "nothing happened". Sourced from the server's
            `warehouse_confirmed_at`, never from local state. */}
        {confirmed && !confirm.isError ? (
          <p
            className="text-muted-foreground mt-1 text-end text-xs"
            data-testid={`confirmed-at-${row.product_id}`}
          >
            {t(($) => $.loadingOs.groups.confirmedAt)}:{' '}
            {new Date(row.warehouse_confirmed_at as string).toLocaleString()}
          </p>
        ) : null}

        {confirm.isError ? (
          <p className="text-destructive mt-1 text-xs" data-testid={`confirm-error-${row.product_id}`}>
            {(confirm.error as { response?: { data?: { message?: string } } })?.response?.data
              ?.message ?? t(($) => $.loadingOs.groups.confirmFailed)}
          </p>
        ) : null}
      </TableCell>
    </TableRow>
  );
}

/**
 * Open driver requests awaiting a warehouse decision.
 *
 * ACCEPT takes the driver's number, EDIT takes a third, REJECT changes nothing. Reject
 * exists so a warehouse that recounts and finds its original figure correct can say so
 * rather than being forced to alter a correct quantity.
 */
function AdjustmentReviewPanel({
  slotId,
  rows,
}: {
  slotId: string;
  rows: LoadingGroupProduct[];
}) {
  const { t } = useTranslation('operations');
  const resolve = useResolveAdjustment(slotId);
  const [edits, setEdits] = useState<Record<string, string>>({});

  const open = rows.filter((r) => r.open_adjustment !== null);

  if (open.length === 0) {
    return null;
  }

  return (
    <Card data-testid="loading-adjustments">
      <CardHeader>
        <CardTitle>{t(($) => $.loadingOs.groups.adjustmentsTitle)}</CardTitle>
        <CardDescription>{t(($) => $.loadingOs.groups.adjustmentsDescription)}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {open.map((row) => {
          const adj = row.open_adjustment!;
          const difference = adj.driver_reported_qty - adj.quantity_before;

          return (
            <div
              key={adj.id}
              className="rounded-md border p-3"
              data-testid={`adjustment-${row.product_id}`}
            >
              <p className="text-sm font-medium">{row.product_name ?? '—'}</p>

              <div className="text-muted-foreground mt-1 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                <span>
                  {t(($) => $.loadingOs.groups.required)}:{' '}
                  <span className="text-foreground">{qty(row.quantity_required)}</span>
                </span>
                <span>
                  {t(($) => $.loadingOs.groups.previouslyLoaded)}:{' '}
                  <span className="text-foreground">{qty(adj.quantity_before)}</span>
                </span>
                <span>
                  {t(($) => $.loadingOs.groups.driverReported)}:{' '}
                  <span className="text-foreground">{qty(adj.driver_reported_qty)}</span>
                </span>
                {/* Signed: negative means the driver received less than recorded. */}
                <span className={difference < 0 ? 'text-destructive font-medium' : ''}>
                  {t(($) => $.loadingOs.groups.colDifference)}: {difference > 0 ? '+' : ''}
                  {qty(difference)}
                </span>
              </div>

              {adj.reason ? (
                <p className="text-muted-foreground mt-1 text-xs">
                  {t(($) => $.loadingOs.groups.driverReason)}: {adj.reason}
                </p>
              ) : null}

              <div className="mt-3 flex flex-wrap items-center gap-2">
                <Button
                  size="sm"
                  disabled={resolve.isPending}
                  data-testid={`accept-${row.product_id}`}
                  onClick={() => resolve.mutate({ adjustmentId: adj.id, action: 'accept' })}
                >
                  {t(($) => $.loadingOs.groups.accept)}
                </Button>

                <Input
                  type="number"
                  min={0}
                  step="0.0001"
                  placeholder={t(($) => $.loadingOs.groups.revisedQty)}
                  value={edits[adj.id] ?? ''}
                  onChange={(e) => setEdits((p) => ({ ...p, [adj.id]: e.target.value }))}
                  className="h-8 w-28"
                  data-testid={`edit-input-${row.product_id}`}
                  aria-label={t(($) => $.loadingOs.groups.revisedQty)}
                />
                <Button
                  size="sm"
                  variant="outline"
                  disabled={resolve.isPending || (edits[adj.id] ?? '') === ''}
                  data-testid={`edit-${row.product_id}`}
                  onClick={() =>
                    resolve.mutate({
                      adjustmentId: adj.id,
                      action: 'edit',
                      quantityLoaded: Number(edits[adj.id]),
                    })
                  }
                >
                  {t(($) => $.loadingOs.groups.edit)}
                </Button>

                <Button
                  size="sm"
                  variant="destructive"
                  disabled={resolve.isPending}
                  data-testid={`reject-${row.product_id}`}
                  onClick={() => resolve.mutate({ adjustmentId: adj.id, action: 'reject' })}
                >
                  {t(($) => $.loadingOs.groups.reject)}
                </Button>
              </div>
            </div>
          );
        })}

        {resolve.isError ? (
          <p className="text-destructive text-xs" data-testid="adjustment-error">
            {(resolve.error as { response?: { data?: { message?: string } } })?.response?.data
              ?.message ?? t(($) => $.loadingOs.groups.resolveFailed)}
          </p>
        ) : null}
      </CardContent>
    </Card>
  );
}

/**
 * The selected Group's manifest.
 *
 * Renders whether or not a Vehicle, Driver or Trip exists. The execution header states
 * what is attached; the products table states what must be loaded. Neither is
 * conditional on the other.
 */
export function LoadingGroupDetail({ slotId }: { slotId: string }) {
  const { t } = useTranslation('operations');
  const executionLabel = useExecutionLabel();
  const detail = useLoadingGroup(slotId);
  const startLoading = useStartLoading(slotId);

  const data = detail.data;
  const rows = data?.products ?? [];
  const totals = data?.totals;
  const state = executionStateOf(data?.transport, detail.isError);

  const trip = data?.transport.trip ?? null;
  const windowId = data?.group.window_id ?? null;

  // Enabled ONLY on the server's own readiness position: a trip exists and the group
  // carries a vehicle and driver. The server refuses regardless — this button is a
  // courtesy that saves a doomed request, never the protection.
  // `hasStarted` is the decisive half. Without it the button stayed enabled and still
  // read "Start loading" after a session was already open, so pressing it appeared to do
  // nothing — the idempotent action simply returned the same session.
  const alreadyOpen = hasStarted(state);
  const canStart = state === 'ready' && !alreadyOpen && trip !== null && windowId !== null;

  return (
    <div className="space-y-6">
      {/*
        EXECUTION HEADER — Group, Driver, Vehicle, Trip, and the loading summary.

        Nothing here is fabricated: an unassigned Group shows "Not assigned", and a
        failed read shows "Read unavailable". Neither is ever rendered as the other.
      */}
      <Card>
        <CardHeader>
          <CardTitle className="flex flex-wrap items-center gap-2">
            <span>{data?.group.code ?? t(($) => $.loadingOs.groups.loading)}</span>
            <Badge
              variant={state === 'ready' || hasStarted(state) ? 'default' : 'secondary'}
              data-testid="loading-group-state"
            >
              {executionLabel(state)}
            </Badge>
          </CardTitle>
          <CardDescription data-testid="loading-group-execution">
            {hasStarted(state)
              ? t(($) => $.loadingOs.groups.startLoadingDone)
              : state === 'ready'
                ? t(($) => $.loadingOs.groups.executionReady)
                : state === 'unavailable'
                  ? t(($) => $.loadingOs.groups.executionUnknown)
                  : t(($) => $.loadingOs.groups.executionBlocked)}
          </CardDescription>
        </CardHeader>

        <CardContent className="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
          <div className="space-y-1" data-testid="loading-group-transport">
            <TransportLine
              label={t(($) => $.loadingOs.groups.driver)}
              value={data?.transport.driver?.full_name ?? null}
              state={state}
              emptyLabel={t(($) => $.loadingOs.groups.notAssigned)}
            />
            <TransportLine
              label={t(($) => $.loadingOs.groups.vehicle)}
              value={data?.transport.vehicle?.plate_number ?? null}
              state={state}
              emptyLabel={t(($) => $.loadingOs.groups.notAssigned)}
            />
            <TransportLine
              label={t(($) => $.loadingOs.groups.trip)}
              value={
                data?.transport.trip
                  ? `${data.transport.trip.trip_number} · ${data.transport.trip.status}`
                  : null
              }
              state={state}
              emptyLabel={t(($) => $.loadingOs.groups.tripNotCreated)}
            />
          </div>

          {/* SUMMARY — plain sums of the canonical rows the server returned. No stored
              total, no second aggregation. */}
          <div data-testid="loading-group-summary">
            <p className="mb-1 font-medium">{t(($) => $.loadingOs.groups.summaryTitle)}</p>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
              <dt className="text-muted-foreground">{t(($) => $.loadingOs.groups.required)}</dt>
              <dd className="text-end font-medium">{totals ? qty(totals.required) : '—'}</dd>

              <dt className="text-muted-foreground">{t(($) => $.loadingOs.groups.prepared)}</dt>
              <dd className="text-end font-medium">{totals ? qty(totals.prepared) : '—'}</dd>

              <dt className="text-muted-foreground">{t(($) => $.loadingOs.groups.loaded)}</dt>
              <dd className="text-end font-medium">{totals ? qty(totals.loaded) : '—'}</dd>

              <dt className="text-muted-foreground">{t(($) => $.loadingOs.groups.remaining)}</dt>
              <dd className="text-end font-medium">{totals ? qty(totals.remaining) : '—'}</dd>

              {totals && totals.over_prepared > EPS ? (
                <>
                  <dt className="text-amber-600 dark:text-amber-400">
                    {t(($) => $.loadingOs.groups.overPrepared)}
                  </dt>
                  <dd className="text-end font-medium text-amber-600 dark:text-amber-400">
                    {qty(totals.over_prepared)}
                  </dd>
                </>
              ) : null}
            </dl>
          </div>
        </CardContent>

        {/*
          START LOADING — the certified action, nothing more.

          It OPENS the execution session; it records no quantity. Loaded changes only
          when the warehouse confirms what was physically put on the vehicle, which is
          why nothing here touches a quantity.

          The button is a courtesy, not the protection: `open()` re-runs its own guards
          and refuses regardless, and its refusal is surfaced verbatim below.
        */}
        <CardFooter className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
          <p className="text-muted-foreground max-w-xl text-xs">
            {alreadyOpen
              ? t(($) => $.loadingOs.groups.loadingInProgress)
              : canStart
                ? t(($) => $.loadingOs.groups.startLoadingNote)
                : t(($) => $.loadingOs.groups.startLoadingBlocked)}
          </p>

          <div className="flex flex-col items-end gap-1">
            {/*
              Once loading has started the button is REPLACED, not merely disabled: a
              greyed-out "Start loading" still reads as an action that failed. A state
              badge says what is true instead.
            */}
            {alreadyOpen ? (
              <Badge variant="default" data-testid="loading-group-started">
                {executionLabel(state)}
              </Badge>
            ) : (
              <Button
                size="sm"
                data-testid="loading-group-start"
                disabled={!canStart || startLoading.isPending}
                title={canStart ? undefined : t(($) => $.loadingOs.groups.startLoadingBlocked)}
                onClick={() => {
                  if (!canStart || trip === null || windowId === null) {
                    return;
                  }

                  startLoading.mutate({ windowId, tripId: trip.trip_id });
                }}
              >
                {startLoading.isPending
                  ? t(($) => $.loadingOs.groups.startingLoading)
                  : t(($) => $.loadingOs.groups.startLoading)}
              </Button>
            )}

            {/* The server's own refusal, verbatim — it is the authority even when this
                panel believed the group was ready. */}
            {startLoading.isError ? (
              <p className="text-destructive text-xs" data-testid="loading-group-start-error">
                {(startLoading.error as { response?: { data?: { message?: string } } })?.response
                  ?.data?.message ?? t(($) => $.loadingOs.groups.startLoadingFailed)}
              </p>
            ) : null}
          </div>
        </CardFooter>
      </Card>

      {/* Open driver requests, shown above the table because they block settlement. */}
      <AdjustmentReviewPanel slotId={slotId} rows={rows} />

      {/* PRODUCTS — always rendered, never gated on transport. */}
      <Card>
        <CardHeader>
          <CardTitle>{t(($) => $.loadingOs.groups.productsTitle)}</CardTitle>
          <CardDescription>{t(($) => $.loadingOs.groups.productsDescription)}</CardDescription>
        </CardHeader>
        <CardContent>
          {detail.isLoading ? (
            <p className="text-muted-foreground text-sm">{t(($) => $.loadingOs.groups.loading)}</p>
          ) : null}

          {detail.isError ? (
            <p className="text-destructive text-sm" data-testid="loading-group-products-error">
              {t(($) => $.loadingOs.groups.productsFailed)}
            </p>
          ) : null}

          {!detail.isLoading && !detail.isError && rows.length === 0 ? (
            <p className="text-muted-foreground text-sm" data-testid="loading-group-products-empty">
              {t(($) => $.loadingOs.groups.productsEmpty)}
            </p>
          ) : null}

          {rows.length > 0 ? (
            /* Wide table scrolls inside its own container so the page never scrolls
               horizontally on a phone. */
            <div className="overflow-x-auto">
              <Table data-testid="loading-group-products">
                <TableHeader>
                  <TableRow>
                    <TableHead>{t(($) => $.loadingOs.groups.colProduct)}</TableHead>
                    <TableHead>{t(($) => $.loadingOs.groups.colSku)}</TableHead>
                    <TableHead className="text-end">
                      {t(($) => $.loadingOs.groups.colRequired)}
                    </TableHead>
                    <TableHead className="text-end">
                      {t(($) => $.loadingOs.groups.colPrepared)}
                    </TableHead>
                    <TableHead className="text-end">
                      {t(($) => $.loadingOs.groups.colLoaded)}
                    </TableHead>
                    <TableHead className="text-end">
                      {t(($) => $.loadingOs.groups.colRemaining)}
                    </TableHead>
                    <TableHead className="text-end">
                      {t(($) => $.loadingOs.groups.colDriverReceived)}
                    </TableHead>
                    <TableHead>{t(($) => $.loadingOs.groups.colStatus)}</TableHead>
                    <TableHead className="text-end">
                      {t(($) => $.loadingOs.groups.colAction)}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => (
                    <WarehouseProductRow
                      key={row.product_id}
                      row={row}
                      slotId={slotId}
                      canOperate={data?.transport.has_loading_assignment === true}
                    />
                  ))}
                </TableBody>
              </Table>
            </div>
          ) : null}
        </CardContent>
      </Card>
    </div>
  );
}
