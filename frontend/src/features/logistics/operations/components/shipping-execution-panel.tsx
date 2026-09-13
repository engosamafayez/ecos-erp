import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ROUTES } from '@/router/routes';

import { useShippingSummary } from '../hooks/use-control-tower';

function Panel({ title, action, children }: { title: string; action?: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <div className="mb-3 flex items-center justify-between gap-2">
        <h3 className="text-sm font-medium">{title}</h3>
        {action}
      </div>
      {children}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="flex items-center justify-between text-xs">
      <span className="text-muted-foreground">{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  );
}

/**
 * Shipping/Execution — Trip and DeliveryStop counts from the Task 1 canonical
 * read model. Every figure groups by the EXISTING TripStatus/
 * DeliveryStopStatus values; nothing is recomputed here.
 */
export function ShippingExecutionPanel() {
  const { t } = useTranslation('logistics');
  const navigate = useNavigate();
  const { data, isLoading, isError } = useShippingSummary();

  if (isLoading) {
    return <Skeleton className="h-64 w-full" />;
  }

  if (isError || !data) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{t($ => $.operations.controlTower.loadFailed)}</AlertDescription>
      </Alert>
    );
  }

  const { trips, delivery_stops: stops, window_order_reconciliation: window, groups_awaiting_trip_assignment: groupsWaiting } = data;

  return (
    <div className="space-y-4">
      <div className="grid gap-4 md:grid-cols-3">
        <Panel
          title={t($ => $.operations.controlTower.shipping.title)}
          action={
            <Button
              size="sm"
              variant="ghost"
              className="h-7 text-xs"
              onClick={() => navigate(ROUTES.logisticsDistributionWorkspace)}
            >
              {t($ => $.operations.controlTower.shipping.openDistribution)}
            </Button>
          }
        >
          <div className="space-y-1.5">
            <Stat label={t($ => $.operations.controlTower.shipping.groupsAwaitingTrip)} value={groupsWaiting} />
            <Stat label={t($ => $.operations.controlTower.shipping.awaitingLoading)} value={trips.awaiting_loading} />
            <Stat label={t($ => $.operations.controlTower.shipping.loadingInProgress)} value={trips.loading_in_progress} />
            <Stat label={t($ => $.operations.controlTower.shipping.readyForDispatch)} value={trips.ready_for_dispatch} />
            {trips.dispatch_blocked > 0 && (
              <Stat label={t($ => $.operations.controlTower.shipping.dispatchBlocked)} value={trips.dispatch_blocked} />
            )}
          </div>
        </Panel>

        <Panel
          title={t($ => $.operations.controlTower.shipping.executing)}
          action={
            <Button
              size="sm"
              variant="ghost"
              className="h-7 text-xs"
              onClick={() => navigate(ROUTES.logisticsTrips)}
            >
              {t($ => $.operations.controlTower.shipping.openTrips)}
            </Button>
          }
        >
          <div className="space-y-1.5">
            <Stat label={t($ => $.operations.controlTower.shipping.executing)} value={trips.executing} />
            <Stat label={t($ => $.operations.controlTower.shipping.completedPendingSettlement)} value={trips.completed_pending_settlement} />
            <Stat label={t($ => $.operations.controlTower.shipping.closed)} value={trips.closed} />
            <Stat label={t($ => $.operations.controlTower.buckets.externalCarrier)} value={trips.external_carrier_trips} />
          </div>
        </Panel>

        <Panel
          title={t($ => $.operations.controlTower.shipping.stopsTitle)}
          action={
            <Button
              size="sm"
              variant="ghost"
              className="h-7 text-xs"
              onClick={() => navigate(ROUTES.shippingOrders)}
            >
              {t($ => $.operations.controlTower.shipping.openShippingOrders)}
            </Button>
          }
        >
          <div className="space-y-1.5">
            <Stat label={t($ => $.operations.controlTower.shipping.stopPending)} value={stops.by_status.pending} />
            <Stat label={t($ => $.operations.controlTower.shipping.stopInProgress)} value={stops.by_status.in_progress} />
            <Stat label={t($ => $.operations.controlTower.shipping.stopDelivered)} value={stops.by_status.delivered} />
            <Stat label={t($ => $.operations.controlTower.shipping.stopPartial)} value={stops.by_status.partial} />
            <Stat label={t($ => $.operations.controlTower.shipping.stopFailed)} value={stops.by_status.failed} />
            <Stat label={t($ => $.operations.controlTower.shipping.stopReturned)} value={stops.by_status.returned} />
            <Stat label={t($ => $.operations.controlTower.shipping.stopSkipped)} value={stops.by_status.skipped} />
            {stops.retryable_failed > 0 && (
              <Stat label={t($ => $.operations.controlTower.shipping.retryableFailed)} value={stops.retryable_failed} />
            )}
          </div>
        </Panel>
      </div>

      <Panel title={t($ => $.operations.controlTower.shipping.reconciliationTitle)}>
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Stat label={t($ => $.operations.controlTower.shipping.zoned)} value={window.zoned} />
          <Stat label={t($ => $.operations.controlTower.buckets.unzoned)} value={window.unzoned} />
          <Stat label={t($ => $.operations.controlTower.shipping.grouped)} value={window.grouped} />
          <Stat label={t($ => $.operations.controlTower.shipping.ungrouped)} value={window.ungrouped} />
        </div>
      </Panel>
    </div>
  );
}
