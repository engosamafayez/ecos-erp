import { useTranslation } from 'react-i18next';

import type { LoadingWorkspaceBucket, LoadingWorkspaceReasonCode } from '../types/loading-os';

/**
 * Translated label hooks shared across the Loading OS workspace, its session
 * overview, and the Loading/Control-Tower tabs that embed the same read
 * models. Kept in their own module (not a component file) so Fast Refresh
 * stays intact — a file mixing component and hook exports breaks it.
 */

/**
 * One label per read-model reason code — shared with the session-overview (Needs
 * Review / History) tab so the same code always reads the same way everywhere.
 * Exported: stable, machine-readable codes are never rendered as raw strings in any UI
 * that consumes them (task §19).
 */
export function useReasonLabel(): (reason: LoadingWorkspaceReasonCode) => string {
  const { t } = useTranslation('operations');

  return (reason) => {
    switch (reason) {
      case 'pending_loading':
        return t(($) => $.loadingOs.reasons.pendingLoading);
      case 'loading_in_progress':
        return t(($) => $.loadingOs.reasons.loadingInProgress);
      case 'adjustment_requested':
        return t(($) => $.loadingOs.reasons.adjustmentRequested);
      case 'awaiting_driver_confirmation':
        return t(($) => $.loadingOs.reasons.awaitingDriverConfirmation);
      case 'awaiting_driver_reconfirmation':
        return t(($) => $.loadingOs.reasons.awaitingDriverReconfirmation);
      case 'missing_loading_tasks':
        return t(($) => $.loadingOs.reasons.missingLoadingTasks);
      case 'missing_vehicle_custody':
        return t(($) => $.loadingOs.reasons.missingVehicleCustody);
      case 'quantity_mismatch':
        return t(($) => $.loadingOs.reasons.quantityMismatch);
      case 'inconsistent_child_state':
        return t(($) => $.loadingOs.reasons.inconsistentChildState);
      case 'truthfully_complete':
        return t(($) => $.loadingOs.reasons.truthfullyComplete);
      case 'no_activity':
        return t(($) => $.loadingOs.reasons.noActivity);
      case 'cancelled':
        return t(($) => $.loadingOs.reasons.cancelled);
      default:
        return reason;
    }
  };
}

/**
 * One label per Trip status (`TripStatus`, mirroring `logistics/trips`' own
 * `TRIP_STATUS_LABEL`) — shared by the Group detail's transport line and the
 * session-overview's per-assignment evidence line, so a Trip embedded in either
 * loading read never renders its backend status raw.
 */
export function useLoadingTripStatusLabel(): (status: string) => string {
  const { t } = useTranslation('operations');

  return (status) => {
    switch (status) {
      case 'planning':
        return t(($) => $.loadingOs.tripStatus.planning);
      case 'loading':
        return t(($) => $.loadingOs.tripStatus.loading);
      case 'loading_completed':
        return t(($) => $.loadingOs.tripStatus.loadingCompleted);
      case 'driver_accepted':
        return t(($) => $.loadingOs.tripStatus.driverAccepted);
      case 'dispatch_blocked':
        return t(($) => $.loadingOs.tripStatus.dispatchBlocked);
      case 'ready_for_dispatch':
        return t(($) => $.loadingOs.tripStatus.readyForDispatch);
      case 'dispatched':
        return t(($) => $.loadingOs.tripStatus.dispatched);
      case 'out_for_delivery':
        return t(($) => $.loadingOs.tripStatus.outForDelivery);
      case 'in_progress':
        return t(($) => $.loadingOs.tripStatus.inProgress);
      case 'completed':
        return t(($) => $.loadingOs.tripStatus.completed);
      case 'settlement_pending':
        return t(($) => $.loadingOs.tripStatus.settlementPending);
      case 'closed':
        return t(($) => $.loadingOs.tripStatus.closed);
      case 'cancelled':
        return t(($) => $.loadingOs.tripStatus.cancelled);
      default:
        return status;
    }
  };
}

/**
 * One label per loading session status (`LoadingSessionStatus`) — shared by the workspace
 * page's session picker and the session-overview table, so a `LoadingSession` never renders
 * its backend status raw in either place. `default` is a defensive fallback only, for a value
 * outside the known set; every real status these screens can receive has its own translated
 * case.
 */
export function useLoadingSessionStatusLabel(): (status: string) => string {
  const { t } = useTranslation('operations');

  return (status) => {
    switch (status) {
      case 'draft':
        return t(($) => $.loadingOs.sessionStatus.draft);
      case 'ready':
        return t(($) => $.loadingOs.sessionStatus.ready);
      case 'open':
        return t(($) => $.loadingOs.sessionStatus.open);
      case 'loading':
        return t(($) => $.loadingOs.sessionStatus.loading);
      case 'loading_complete':
        return t(($) => $.loadingOs.sessionStatus.loadingComplete);
      case 'allocating':
        return t(($) => $.loadingOs.sessionStatus.allocating);
      case 'allocated':
        return t(($) => $.loadingOs.sessionStatus.allocated);
      case 'dispatching':
        return t(($) => $.loadingOs.sessionStatus.dispatching);
      case 'dispatched':
        return t(($) => $.loadingOs.sessionStatus.dispatched);
      case 'reconciling':
        return t(($) => $.loadingOs.sessionStatus.reconciling);
      case 'closed':
        return t(($) => $.loadingOs.sessionStatus.closed);
      case 'cancelled':
        return t(($) => $.loadingOs.sessionStatus.cancelled);
      default:
        return status;
    }
  };
}

/** One badge tone per read-model bucket — shared with the session-overview tabs. */
export function bucketBadgeVariant(
  bucket: LoadingWorkspaceBucket,
): 'default' | 'secondary' | 'destructive' | 'outline' {
  switch (bucket) {
    case 'needs_review':
      return 'destructive';
    case 'waiting_driver_confirmation':
      return 'outline';
    case 'completed_history':
      return 'secondary';
    default:
      return 'default';
  }
}

/** One label per read-model bucket — the tab strip and any per-row bucket badge. */
export function useBucketLabel(): (bucket: LoadingWorkspaceBucket) => string {
  const { t } = useTranslation('operations');

  return (bucket) => {
    switch (bucket) {
      case 'current_actionable':
        return t(($) => $.loadingOs.workspace.tabs.currentActionable);
      case 'waiting_driver_confirmation':
        return t(($) => $.loadingOs.workspace.tabs.waitingDriverConfirmation);
      case 'needs_review':
        return t(($) => $.loadingOs.workspace.tabs.needsReview);
      default:
        return t(($) => $.loadingOs.workspace.tabs.completedHistory);
    }
  };
}
