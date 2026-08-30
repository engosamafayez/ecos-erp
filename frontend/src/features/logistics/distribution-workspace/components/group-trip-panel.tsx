import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Loader2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ds/use-toast';
import { Checkbox } from '@/components/ui/checkbox';

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useRemoveTripOrder } from '@/features/logistics/trips/hooks/use-trip-execution';

import { TripReadinessPanel } from './trip-readiness-panel';

import {
  useFinalizeGroup,
  useGroupReconciliation,
  useGroupTrips,
  useMoveOrdersToSlot,
  useMoveOrderToSlot,
} from '../hooks/use-distribution-workspace';
import type {
  DistributionOrder,
  GroupTrip,
  GroupTripReconciliation,
  SlotSummary,
} from '../types';

/**
 * TRANSPORT — the Group's execution half.
 *
 * ┌─ THE BOUNDARY THIS PANEL DRAWS ──────────────────────────────────────────┐
 * │ Group = PLANNING. It owns warehouse, zones, orders, Required, Prepared.   │
 * │ Trip  = EXECUTION. It owns the vehicle, the driver and the transport      │
 * │         lifecycle.                                                        │
 * │                                                                          │
 * │ Finalize is the handover between them, and nothing else. It writes no     │
 * │ order status and moves no stock — orders move only at Dispatch, which is  │
 * │ also the inventory boundary.                                             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ONE GROUP → 1..N TRIPS. Normally one. More only when the Group's order count
 * exceeds a single Trip's capacity, which is why this renders a LIST and not a
 * single object, and why the split note appears only when a split actually
 * happened rather than being permanently on screen.
 *
 * VEHICLE AND DRIVER ARE READ, NOT OWNED HERE. They come from the canonical
 * `logistics_driver_vehicle_assignments` pairing through the Trip. This panel
 * shows them; it does not assign them, and there is deliberately no control here
 * that would imply the Group owns either one.
 *
 * ┌─ THE DIFFERENCE IS SHOWN, NEVER CLOSED (TASK-1-B) ───────────────────────┐
 * │ The manifest is a snapshot taken at Finalize. Orders that join the Group   │
 * │ afterwards are NOT added to it, and orders that leave the Group are NOT    │
 * │ removed from it — that is the approved contract, and a certified test      │
 * │ enforces that a repeated Finalize re-syncs nothing.                        │
 * │                                                                          │
 * │ So this panel reports both differences and offers no control that would    │
 * │ silently reconcile them. Nothing here mutates membership.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
export function GroupTripPanel({
  windowId,
  group,
  siblings = [],
  open,
}: {
  windowId: string;
  group: SlotSummary;
  /**
   * The window's other Groups, for choosing a move destination. Passed in rather than
   * fetched again: the workspace already holds this list, and a second read could
   * disagree with the board the operator is looking at.
   */
  siblings?: SlotSummary[];
  /** Fetch only while the Group's panel is actually open. */
  open: boolean;
}) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();

  const query = useGroupTrips(windowId, group.slot_id, open);
  const reconciliation = useGroupReconciliation(windowId, group.slot_id, open);
  const finalize = useFinalizeGroup();

  const trips = query.data?.trips ?? [];
  const readiness = query.data?.readiness ?? [];
  const finalized = trips.length > 0;

  function onFinalize() {
    finalize.mutate(
      { windowId, slotId: group.slot_id },
      {
        onError: (error: unknown) => {
          // The server's own refusal — "This group has no eligible orders to
          // finalize", "Loading preparation is inconsistent: 2 product(s)…" — is far
          // more actionable than a generic failure, so it is surfaced verbatim.
          const message =
            (error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
            t(($) => $.distributionWorkspace.trip.finalizeFailed);

          toast({ title: message, variant: 'destructive' });
        },
      },
    );
  }

  return (
    <div className="space-y-3" data-testid={`group-trip-${group.code}`}>
      {query.isError ? (
        <p className="mt-2 text-sm text-destructive">
          {t(($) => $.distributionWorkspace.trip.loadFailed)}
        </p>
      ) : null}

      {!finalized && !query.isLoading && !query.isError ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
          <span className="text-sm text-muted-foreground">
            {t(($) => $.distributionWorkspace.trip.notFinalized)}
          </span>
          <Button
            size="sm"
            onClick={onFinalize}
            disabled={finalize.isPending}
            data-testid={`finalize-group-${group.code}`}
          >
            {finalize.isPending ? (
              <>
                <Loader2 className="me-2 size-3.5 animate-spin" aria-hidden />
                {t(($) => $.distributionWorkspace.trip.finalizing)}
              </>
            ) : (
              t(($) => $.distributionWorkspace.trip.finalize)
            )}
          </Button>
        </div>
      ) : null}

      {finalized ? (
        <div className="space-y-2">
          {trips.map((trip) => (
            <div key={trip.trip_id} className="space-y-2">
              <TripCard trip={trip} groupCode={group.code} />

              {/*
                Readiness sits with the Trip it describes rather than in a separate
                place: the decision an operator makes here is "can THIS trip load",
                and separating the verdict from its subject invites reading the wrong
                one when a Group has split into several.
              */}
              <TripReadinessPanel
                readiness={readiness.find((r) => r.trip_id === trip.trip_id) ?? null}
                windowId={windowId}
                slotId={group.slot_id}
                isLoading={query.isLoading}
                isError={query.isError}
              />
            </div>
          ))}

          {trips.length > 1 ? (
            <p className="text-xs text-amber-600 dark:text-amber-400">
              {t(($) => $.distributionWorkspace.trip.splitNote)}
            </p>
          ) : null}
        </div>
      ) : null}

      {reconciliation.data ? (
        <GroupTripDifference
          data={reconciliation.data}
          group={group}
          siblings={siblings}
          firstTripId={trips[0]?.trip_id ?? null}
          windowId={windowId}
        />
      ) : null}
    </div>
  );
}

/**
 * The Group ↔ Trip lifecycle panel.
 *
 * ┌─ WHY THIS IS STATE-DRIVEN AND NOT A COUNT ───────────────────────────────┐
 * │ The previous version rendered one label — "Orders not assigned to a       │
 * │ trip" — which presented a diagnostic difference as though it were a       │
 * │ normal operational destination. It is not: after Finalize, an accepted    │
 * │ Group order outside the Trip is an action item, not a resting state.      │
 * │                                                                          │
 * │ `state` is decided SERVER-SIDE from membership, capacity and the Trip     │
 * │ list, so this component renders a situation rather than inferring one.    │
 * │ The four states each ask the operator for something different.           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NOTHING HERE SYNCHRONISES. Every action is an explicit operator mutation through an
 * endpoint that already existed: move an order between Groups
 * (`PATCH /assignments/{id}/slot`) or remove a stray from a Trip
 * (`DELETE /trips/{id}/orders/{orderId}`). No order is moved, deferred or added
 * automatically, and no destination is ever chosen for the operator.
 */
function GroupTripDifference({
  data,
  group,
  siblings,
  firstTripId,
  windowId,
}: {
  data: GroupTripReconciliation;
  group: SlotSummary;
  siblings: SlotSummary[];
  firstTripId: string | null;
  windowId: string;
}) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();
  const finalize = useFinalizeGroup();
  const [confirming, setConfirming] = useState(false);
  // Assignment ids the operator ticked. Assignment, not order: the batch endpoint moves
  // assignments, and that is the id the server validates.
  const [selected, setSelected] = useState<string[]>([]);

  const { capacity, summary, unassigned_orders: unassigned, exceptions, state } = data;

  const overflow = capacity.overflow > 0;
  const decisionRequired = state === 'capacity_decision_required';

  function onApprove() {
    finalize.mutate(
      { windowId, slotId: group.slot_id, approveOverflow: true },
      {
        onError: (error: unknown) => {
          // The server's own refusal is more actionable than a generic failure.
          toast({
            variant: 'destructive',
            title: t(($) => $.distributionWorkspace.trip.finalizeFailed),
            description: error instanceof Error ? error.message : undefined,
          });
        },
        onSettled: () => setConfirming(false),
      },
    );
  }

  // Every string is a real t() call — a map keyed by state would satisfy the i18n lint
  // rule and still ship English.
  const stateLabel = () => {
    switch (state) {
      case 'capacity_decision_required':
        return t(($) => $.distributionWorkspace.difference.stateCapacityDecision);
      case 'overflow_approved':
        return t(($) => $.distributionWorkspace.difference.stateOverflowApproved);
      case 'awaiting_finalization':
        return t(($) => $.distributionWorkspace.difference.stateAwaitingFinalization);
      case 'added_after_finalization':
        return t(($) => $.distributionWorkspace.difference.stateAddedAfterFinalization);
      default:
        return t(($) => $.distributionWorkspace.difference.stateResolved);
    }
  };

  const listTitle = () =>
    state === 'capacity_decision_required'
      ? t(($) => $.distributionWorkspace.difference.overflowOrdersTitle, {
          count: capacity.overflow,
        })
      : state === 'awaiting_finalization'
        ? t(($) => $.distributionWorkspace.difference.awaitingFinalizationTitle, {
            count: summary.unassigned_orders,
          })
        : t(($) => $.distributionWorkspace.difference.addedAfterFinalizationTitle, {
            count: summary.unassigned_orders,
          });

  return (
    <div className="mt-4 space-y-3" data-testid={`group-trip-difference-${group.code}`}>
      {/* CAPACITY — Current / Maximum / Overflow-or-Remaining, all server-supplied. */}
      <div className="rounded-md border bg-muted/30 p-3">
        <dl className="grid grid-cols-3 gap-3">
          <Field
            label={t(($) => $.distributionWorkspace.capacity.current)}
            value={capacity.current}
          />
          <Field
            label={t(($) => $.distributionWorkspace.capacity.maximum)}
            value={capacity.maximum ?? t(($) => $.distributionWorkspace.capacity.noMaximum)}
          />
          {overflow ? (
            <Field
              label={t(($) => $.distributionWorkspace.difference.overflow)}
              value={`+${capacity.overflow}`}
            />
          ) : (
            <Field
              label={t(($) => $.distributionWorkspace.capacity.remaining)}
              value={capacity.remaining ?? t(($) => $.distributionWorkspace.capacity.unbounded)}
            />
          )}
        </dl>

        <p
          className={
            decisionRequired
              ? 'mt-2 text-xs font-medium text-destructive'
              : 'mt-2 text-xs text-muted-foreground'
          }
          data-testid={`group-state-${group.code}`}
        >
          {stateLabel()}
        </p>

        {/*
          THE CAPACITY DECISION. Two explicit choices and no default: approving is the
          operator accepting the Group as it stands, and moving is handled per order in
          the list below. Nothing is moved, deferred or approved automatically.

          The confirmation step is deliberate — approving is a business decision that
          persists, so it should not be one stray click.
        */}
        {decisionRequired ? (
          <div className="mt-3 space-y-2" data-testid={`capacity-decision-${group.code}`}>
            {confirming ? (
              <>
                <p className="text-xs font-medium">
                  {t(($) => $.distributionWorkspace.difference.approveConfirm, {
                    count: capacity.overflow,
                  })}
                </p>
                <div className="flex flex-wrap gap-2">
                  <Button
                    size="sm"
                    onClick={onApprove}
                    disabled={finalize.isPending}
                    data-testid={`approve-overflow-confirm-${group.code}`}
                  >
                    {finalize.isPending ? (
                      <>
                        <Loader2 className="me-2 size-3.5 animate-spin" aria-hidden />
                        {t(($) => $.distributionWorkspace.trip.finalizing)}
                      </>
                    ) : (
                      t(($) => $.distributionWorkspace.difference.approveAndFinalize)
                    )}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setConfirming(false)}
                    disabled={finalize.isPending}
                  >
                    {t(($) => $.common.cancel)}
                  </Button>
                </div>
              </>
            ) : (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setConfirming(true)}
                data-testid={`approve-overflow-${group.code}`}
              >
                {t(($) => $.distributionWorkspace.difference.approveOverflowAndFinalize)}
              </Button>
            )}
            <p className="text-xs text-muted-foreground">
              {t(($) => $.distributionWorkspace.difference.approveHint)}
            </p>
          </div>
        ) : null}

        {/* Approved: the overflow is still shown, and the maximum is still the plan. */}
        {state === 'overflow_approved' && capacity.overflow_approved_orders !== null ? (
          <p className="mt-2 text-xs text-muted-foreground">
            {t(($) => $.distributionWorkspace.difference.approvedFor, {
              count: capacity.overflow_approved_orders,
            })}
          </p>
        ) : null}
      </div>

      {/* The orders needing a decision, under a heading that names the situation. */}
      {unassigned.length > 0 ? (
        <section data-testid={`group-unassigned-${group.code}`}>
          <h4 className="text-sm font-semibold">{listTitle()}</h4>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {overflow
              ? t(($) => $.distributionWorkspace.difference.overflowHint)
              : t(($) => $.distributionWorkspace.difference.addedAfterFinalizationHint)}
          </p>

          {/* Cards, not a table: this sits two levels inside a nested panel, where a
              multi-column grid is unreadable at mobile width. */}
          <ul className="mt-2 space-y-1.5">
            {unassigned.map((order) => (
              <OrderNeedingDecision
                key={order.order_id}
                order={order}
                group={group}
                siblings={siblings}
                tripId={state === 'added_after_finalization' ? firstTripId : null}
                checked={selected.includes(order.assignment_id)}
                onToggle={() =>
                  setSelected((current) =>
                    current.includes(order.assignment_id)
                      ? current.filter((id) => id !== order.assignment_id)
                      : [...current, order.assignment_id],
                  )
                }
              />
            ))}
          </ul>

          <BatchMoveBar
            group={group}
            siblings={siblings}
            selected={selected}
            onClear={() => setSelected([])}
          />
        </section>
      ) : null}

      {/* TRIP INTEGRITY — a Trip carrying an order that left its Group. */}
      {exceptions.length > 0 ? (
        <section
          className="rounded-md border border-amber-500/40 bg-amber-50/50 p-3 dark:bg-amber-950/20"
          data-testid={`group-trip-exceptions-${group.code}`}
        >
          <h4 className="flex items-center gap-1.5 text-sm font-semibold text-amber-700 dark:text-amber-400">
            <AlertTriangle className="size-4" aria-hidden />
            {t(($) => $.distributionWorkspace.difference.integrityTitle, {
              count: summary.exception_orders,
            })}
          </h4>

          <ul className="mt-2 space-y-2">
            {exceptions.map((exception) => (
              <TripIntegrityException
                key={exception.order_id}
                exception={exception}
                tripId={firstTripId}
              />
            ))}
          </ul>

          <p className="mt-2 text-xs text-muted-foreground">
            {t(($) => $.distributionWorkspace.difference.integrityHint)}
          </p>
        </section>
      ) : null}
    </div>
  );
}

/**
 * One order awaiting a decision, with the operator's existing options.
 *
 * MOVE is a SINGLE-ORDER call. `PATCH /assignments/{id}/slot` is atomic on its own and
 * enforces the destination's capacity inside a row lock, so one order at a time is
 * always safe. A multi-select batch is deliberately NOT offered: the existing API has
 * no batch or transaction spanning several orders, so a five-order move could half
 * succeed with no way to roll back — reported rather than built.
 *
 * The destination is never chosen automatically. Groups already at their maximum are
 * still listed, with their occupancy shown, so the operator sees why one is unusable
 * instead of finding it missing.
 */
function OrderNeedingDecision({
  order,
  group,
  siblings,
  tripId,
  checked,
  onToggle,
}: {
  order: DistributionOrder;
  group: SlotSummary;
  siblings: SlotSummary[];
  /** Non-null only when adding to the Trip is a legitimate resolution. */
  tripId: string | null;
  checked: boolean;
  onToggle: () => void;
}) {
  const { t } = useTranslation('logistics');
  const move = useMoveOrderToSlot();

  const destinations = siblings.filter((candidate) => candidate.slot_id !== group.slot_id);

  return (
    <li
      className="rounded-md border p-2 text-sm"
      data-testid={`group-unassigned-row-${order.order_number}`}
    >
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        {/* The per-order buttons below still work on their own. This only adds the
            option of deciding several at once, which the server does atomically. */}
        <Checkbox
          checked={checked}
          onCheckedChange={onToggle}
          aria-label={t(($) => $.distributionWorkspace.batchMove.selectOrder, {
            order: order.order_number,
          })}
          data-testid={`batch-select-${order.order_number}`}
        />
        <span className="font-medium" dir="ltr">
          {order.order_number}
        </span>
        <Badge variant="outline">{order.order_status}</Badge>
        <Badge variant={order.payment_state === 'paid' ? 'secondary' : 'outline'}>
          {order.payment_state}
        </Badge>
      </div>

      <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
        {order.customer_name ? <span>{order.customer_name}</span> : null}
        {order.city_name || order.governorate_name ? (
          <span>{[order.city_name, order.governorate_name].filter(Boolean).join(' · ')}</span>
        ) : null}
        <span className="tabular-nums">{order.total}</span>
      </div>

      {destinations.length > 0 ? (
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <span className="text-xs text-muted-foreground">
            {t(($) => $.distributionWorkspace.difference.moveTo)}
          </span>
          {destinations.map((destination) => (
            <Button
              key={destination.slot_id}
              size="sm"
              variant="outline"
              disabled={move.isPending}
              onClick={() =>
                move.mutate({
                  assignmentId: order.assignment_id,
                  slotId: destination.slot_id,
                })
              }
              data-testid={`move-${order.order_number}-to-${destination.code}`}
            >
              {destination.code}
              <span className="ms-1.5 text-xs text-muted-foreground tabular-nums">
                {destination.demand_orders}
                {destination.capacity_orders === null ? '' : `/${destination.capacity_orders}`}
              </span>
            </Button>
          ))}
        </div>
      ) : (
        <p className="mt-2 text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.difference.noDestination)}
        </p>
      )}

      {tripId ? (
        <p className="mt-1 text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.difference.orAddToTrip)}
        </p>
      ) : null}
    </li>
  );
}

/**
 * BATCH MOVE — several orders, one destination, one server transaction.
 *
 * WHY THIS IS NOT A LOOP OVER THE PER-ORDER BUTTONS
 * Five per-order calls are five transactions. A destination with three free places would
 * take three orders and refuse two, and there would be nothing to roll back. The batch
 * endpoint decides capacity ONCE for the whole selection, so the outcome is all or none.
 *
 * AVAILABLE CAPACITY IS THE SERVER'S NUMBER. `remaining_orders` is derived server-side
 * from the same figures the write-path guard enforces; subtracting the two capacity
 * fields here instead is how a screen starts disagreeing with the guard that refuses it.
 * A null maximum means unconstrained, never zero.
 *
 * The button is disabled when the selection exceeds that number, but the SERVER is still
 * the authority — it re-checks under a row lock, so a stale screen is refused rather
 * than half-applied.
 *
 * The destination is never chosen automatically. Groups with no room are still listed
 * with their occupancy visible, so the operator can see why one is unusable.
 */
function BatchMoveBar({
  group,
  siblings,
  selected,
  onClear,
}: {
  group: SlotSummary;
  siblings: SlotSummary[];
  selected: string[];
  onClear: () => void;
}) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();
  const [destinationId, setDestinationId] = useState('');
  const move = useMoveOrdersToSlot();

  const destinations = siblings.filter((candidate) => candidate.slot_id !== group.slot_id);

  if (selected.length === 0 || destinations.length === 0) {
    return null;
  }

  const destination = destinations.find((candidate) => candidate.slot_id === destinationId);
  const remaining = destination?.remaining_orders ?? null;
  const unlimited = destination !== undefined && destination.capacity_orders === null;
  const exceeds = remaining !== null && selected.length > remaining;

  function submit() {
    if (destination === undefined || exceeds || move.isPending) {
      return;
    }

    move.mutate(
      { assignmentIds: selected, slotId: destination.slot_id },
      {
        onSuccess: (result) => {
          toast({
            title: t(($) => $.distributionWorkspace.batchMove.success, {
              count: result.moved,
              group: destination.code,
            }),
          });
          onClear();
          setDestinationId('');
        },
        // ALL OR NONE. The server ran one transaction, so a failure moved nothing —
        // said plainly, and no order is shown as moved.
        onError: (error: unknown) => {
          toast({
            variant: 'destructive',
            title: t(($) => $.distributionWorkspace.batchMove.failed),
            description:
              (error as { response?: { data?: { message?: string } } })?.response?.data
                ?.message ?? undefined,
          });
        },
      },
    );
  }

  return (
    <div
      className="mt-3 rounded-md border bg-muted/30 p-3"
      data-testid={`batch-move-bar-${group.code}`}
    >
      <div className="flex flex-wrap items-end gap-3">
        <div className="space-y-1">
          <span className="block text-xs text-muted-foreground">
            {t(($) => $.distributionWorkspace.batchMove.selectedCount, {
              count: selected.length,
            })}
          </span>
          <Select value={destinationId} onValueChange={setDestinationId}>
            <SelectTrigger
              className="w-56"
              data-testid={`batch-move-destination-${group.code}`}
            >
              <SelectValue
                placeholder={t(($) => $.distributionWorkspace.batchMove.destinationPlaceholder)}
              />
            </SelectTrigger>
            <SelectContent>
              {destinations.map((candidate) => (
                <SelectItem key={candidate.slot_id} value={candidate.slot_id}>
                  {candidate.code}
                  {' · '}
                  {candidate.demand_orders}
                  {candidate.capacity_orders === null ? '' : `/${candidate.capacity_orders}`}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <Button
          size="sm"
          onClick={submit}
          disabled={destination === undefined || exceeds || move.isPending}
          data-testid={`batch-move-submit-${group.code}`}
        >
          {move.isPending
            ? t(($) => $.distributionWorkspace.batchMove.moving)
            : t(($) => $.distributionWorkspace.batchMove.action)}
        </Button>

        <Button size="sm" variant="ghost" onClick={onClear} disabled={move.isPending}>
          {t(($) => $.distributionWorkspace.batchMove.clear)}
        </Button>
      </div>

      {/* Selected / Destination / Available capacity, before anything is committed. */}
      {destination !== undefined ? (
        <p className="mt-2 text-xs text-muted-foreground" data-testid="batch-move-preview">
          {unlimited
            ? t(($) => $.distributionWorkspace.batchMove.previewUnlimited, {
                count: selected.length,
                group: destination.code,
              })
            : t(($) => $.distributionWorkspace.batchMove.preview, {
                count: selected.length,
                group: destination.code,
                available: remaining ?? 0,
              })}
        </p>
      ) : null}

      {exceeds ? (
        <p className="mt-1 text-xs font-medium text-destructive" data-testid="batch-move-exceeds">
          {t(($) => $.distributionWorkspace.batchMove.exceeds)}
        </p>
      ) : null}
    </div>
  );
}

/**
 * A Trip carrying an order that is no longer a member of its Group.
 *
 * The only action offered is the existing removal — no automatic repair, and no new
 * correction endpoint. Removal is refused by the server once the Trip leaves
 * `Loading`, which is the existing editability contract and is not restated here.
 */
function TripIntegrityException({
  exception,
  tripId,
}: {
  exception: GroupTripReconciliation['exceptions'][number];
  tripId: string | null;
}) {
  const { t } = useTranslation('logistics');
  const remove = useRemoveTripOrder(tripId ?? '');

  return (
    <li className="text-sm" data-testid={`group-trip-exception-${exception.order_number}`}>
      <span className="font-medium" dir="ltr">
        {exception.order_number}
      </span>
      <span className="mx-1.5 text-muted-foreground" dir="ltr">
        · {exception.trip_number}
      </span>
      <p className="text-xs text-muted-foreground">
        {t(($) => $.distributionWorkspace.difference.exceptionExplanation)}
      </p>
      {tripId ? (
        <Button
          size="sm"
          variant="outline"
          className="mt-1"
          disabled={remove.isPending}
          onClick={() => remove.mutate(exception.order_id)}
          data-testid={`remove-exception-${exception.order_number}`}
        >
          {t(($) => $.distributionWorkspace.difference.removeFromTrip)}
        </Button>
      ) : null}
    </li>
  );
}

function TripCard({ trip, groupCode }: { trip: GroupTrip; groupCode: string }) {
  const { t } = useTranslation('logistics');
  const notAssigned = t(($) => $.distributionWorkspace.trip.notAssigned);

  return (
    <div
      className="rounded-md border p-3"
      data-testid={`group-trip-row-${groupCode}-${trip.trip_number}`}
    >
      {/* Compact header: Trip · TRP-XXX · status */}
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs uppercase tracking-wide text-muted-foreground">
          {t(($) => $.distributionWorkspace.phase1.tabTrip)}
        </span>
        <span className="font-semibold" dir="ltr">
          {trip.trip_number}
        </span>
        <Badge variant="secondary">{trip.status}</Badge>
      </div>

      {/* Compact KPI row — Orders / Remaining / Vehicle / Driver / Capacity.
          Vehicle + Driver are this trip's own canonical driverVehicleAssignment. */}
      <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        <Metric
          label={t(($) => $.distributionWorkspace.trip.orders)}
          value={`${trip.orders_count} / ${trip.capacity}`}
        />
        <Metric
          label={t(($) => $.distributionWorkspace.trip.remainingCapacity)}
          value={trip.remaining_capacity}
        />
        <Metric
          label={t(($) => $.distributionWorkspace.trip.vehicle)}
          value={trip.vehicle?.plate_number ?? trip.vehicle?.name ?? notAssigned}
        />
        <Metric
          label={t(($) => $.distributionWorkspace.trip.driver)}
          value={trip.driver?.full_name ?? notAssigned}
        />
        <Metric
          label={t(($) => $.distributionWorkspace.trip.capacity)}
          value={trip.capacity}
        />
      </div>
    </div>
  );
}

/** Compact metric tile for the Trip KPI row. */
function Metric({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-md border bg-muted/30 p-2">
      <div className="truncate text-[0.65rem] uppercase tracking-wide text-muted-foreground">
        {label}
      </div>
      <div className="truncate text-sm font-semibold tabular-nums">{value}</div>
    </div>
  );
}

function Field({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="font-medium tabular-nums">{value}</dd>
    </div>
  );
}
