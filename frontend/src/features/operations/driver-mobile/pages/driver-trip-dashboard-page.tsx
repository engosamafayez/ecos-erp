import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  DollarSign,
  AlertTriangle,
  RotateCcw,
  Clock,
  CheckCircle,
  CheckCircle2,
  Circle,
  Package,
  Truck,
  ClipboardList,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { ROUTES } from '@/router/routes';
import { useDriverTrip, useFinishTrip, useDriverLoading } from '../hooks/use-driver-mobile';
import { BLOCKED_STATES, COMPLETED_STATES, ON_THE_ROAD, UNRESOLVED_LOADING } from '../lib/trip-lifecycle';

/**
 * DRIVER TRIP DETAIL — the operational workspace for one trip.
 *
 * TASK-DRIVER-APP-TRIP-START-WORKSPACE-CLOSURE-001. This page used to render an action button
 * ONLY for `out_for_delivery` / `in_progress` / `completed`, so an assigned trip in ANY
 * pre-departure state (loading / loading_completed / …) rendered a header and an EMPTY body —
 * with no readiness information and no path to Start Trip. Worse, Start Trip was gated on
 * `out_for_delivery` (an already-on-the-road state), the inverse of the canonical departure seam.
 *
 * The workspace renders an operational state for EVERY status and derives loading/custody
 * readiness from the canonical driver reads (trip summary + loading manifest).
 *
 * TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §16/§22 — the separate Driver-facing "Start Trip"
 * action is REMOVED from this page. Departure is now a single canonical path: the driver confirms
 * readiness and departs from the Loading workspace's "Ready to Start Delivery" action. Trip Detail
 * shows readiness/blockers and routes to Loading; it never carries a second Start/Ready button and
 * never writes Trip.status. It derives from the same lifecycle truth as Driver Home and Loading.
 */
export function DriverTripDashboardPage() {
  const { t } = useTranslation('driver-mobile');
  const { tripId = '' } = useParams<{ tripId: string }>();
  const navigate = useNavigate();

  const { data: trip, isLoading, isError, refetch } = useDriverTrip(tripId);
  // The current shipment loading manifest — the canonical loading/custody readiness for the
  // driver's active trip. Used ONLY to describe pre-departure readiness; it never drives
  // Trip.status. (Same read Driver Home consumes, so the two cannot disagree.)
  const { data: manifest } = useDriverLoading();
  const finishMutation = useFinishTrip(tripId);

  const [finishDialogOpen, setFinishDialogOpen] = useState(false);

  function handleFinishTrip() {
    if (!navigator.geolocation) {
      finishMutation.mutate({ lat: 0, lng: 0 });
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        finishMutation.mutate({ lat: pos.coords.latitude, lng: pos.coords.longitude });
        setFinishDialogOpen(false);
      },
      () => {
        finishMutation.mutate({ lat: 0, lng: 0 });
        setFinishDialogOpen(false);
      },
    );
  }

  const go = (path: string) => navigate(path);

  // ── Loading / Error / Not-found (§11: never a blank body for a valid state) ──
  if (isLoading) {
    return (
      <div className="p-4 space-y-4">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-3 p-6 text-center text-muted-foreground">
        <AlertTriangle className="h-9 w-9 text-destructive/70" aria-hidden="true" />
        <p className="text-sm">{t(($) => $.dashboard.error)}</p>
        <Button variant="outline" size="sm" onClick={() => void refetch()}>{t(($) => $.dashboard.retry)}</Button>
      </div>
    );
  }

  if (!trip) {
    return (
      <div className="p-4 text-center text-muted-foreground">
        {t(($) => $.dashboard.notFound)}
      </div>
    );
  }

  const status = trip.status;

  // ── Canonical status label + badge tone (every status, no blank fallback) ────
  const STATUS_BADGE: Record<string, string> = {
    planning:          'bg-gray-100 text-gray-600',
    loading:           'bg-indigo-100 text-indigo-700',
    loading_completed: 'bg-violet-100 text-violet-700',
    driver_accepted:   'bg-violet-100 text-violet-700',
    ready_for_dispatch:'bg-violet-100 text-violet-700',
    dispatched:        'bg-blue-100 text-blue-700',
    out_for_delivery:  'bg-blue-100 text-blue-700',
    in_progress:       'bg-amber-100 text-amber-700',
    completed:         'bg-green-100 text-green-700',
    settlement_pending:'bg-amber-100 text-amber-700',
    closed:            'bg-gray-100 text-gray-600',
    dispatch_blocked:  'bg-red-100 text-red-700',
    cancelled:         'bg-red-100 text-red-700',
  };
  const STATUS_KEYS = new Set([
    'planning', 'loading', 'loading_completed', 'driver_accepted', 'ready_for_dispatch',
    'dispatched', 'out_for_delivery', 'in_progress', 'completed', 'settlement_pending',
    'closed', 'dispatch_blocked', 'cancelled',
  ]);
  const statusLabel = STATUS_KEYS.has(status)
    ? t(($) => $.status[status as 'loading'])
    : status.replace(/_/g, ' ');

  // ── Canonical readiness derivation (loading manifest; never fabricated) ──────
  const items = manifest?.items ?? [];
  const loadedItems = items.filter((i) => i.quantity_loaded > 0);
  const loadedCount = loadedItems.length;
  // Pending = a loaded item still awaiting THIS driver's confirmation. Per §8 (Phase-2) this
  // takes precedence over any shipment-level `loading_complete` flag.
  const pendingConfirmations = loadedItems.filter((i) => UNRESOLVED_LOADING.includes(i.workflow_state)).length;
  const confirmedCount = loadedCount - pendingConfirmations;
  const loadingCompleteFlag = manifest?.shipment?.loading_complete ?? false;
  const ordersCount = trip.stops_count || manifest?.shipment?.orders_count || 0;

  const onRoad     = ON_THE_ROAD.includes(status);
  const isCompleted = status === 'completed';
  const isSettlement = status === 'settlement_pending';
  const isClosed   = status === 'closed';
  const isBlocked  = BLOCKED_STATES.includes(status);
  const isPreDeparture = !onRoad && !COMPLETED_STATES.includes(status) && !isBlocked;

  // START TRIP is exposed ONLY at the canonical departure seam: the trip has reached
  // `loading_completed` AND no loaded item still awaits confirmation (the exact precondition of
  // the backend advanceToDispatched, which otherwise refuses / no-ops). The UI never writes
  // Trip.status — pressing it calls the canonical mutation and re-reads canonical truth.
  const readyToStart = status === 'loading_completed' && pendingConfirmations === 0;

  return (
    <div className="min-h-screen bg-background pb-6">
      {/* Header */}
      <div className="sticky top-0 z-10 bg-background border-b px-4 py-3 flex items-center gap-3">
        <Button variant="ghost" size="icon" onClick={() => navigate(ROUTES.driverHome)}>
          <ArrowLeft className="h-5 w-5" />
        </Button>
        <div className="flex-1 min-w-0">
          <h1 className="font-semibold text-base truncate">{trip.trip_number}</h1>
          {trip.vehicle_plate && (
            <p className="text-xs text-muted-foreground truncate">
              {[trip.vehicle_plate, trip.vehicle_name].filter(Boolean).join(' · ')}
            </p>
          )}
        </div>
        <Badge className={STATUS_BADGE[status] ?? 'bg-gray-100 text-gray-600'}>
          {statusLabel}
        </Badge>
      </div>

      <div className="p-4 space-y-4">
        {/* ── PRE-DEPARTURE: readiness workspace + Start Trip or the real blocker ── */}
        {isPreDeparture && (
          <>
            {/* Readiness card — loading / custody / orders, all canonical */}
            <div className="rounded-xl border p-4 space-y-3">
              <p className="flex items-center gap-2 text-sm font-semibold">
                <ClipboardList className="h-4 w-4" aria-hidden="true" />
                {t(($) => $.dashboard.readiness.title)}
              </p>

              <ReadinessRow
                icon={<Package className="h-4 w-4 text-muted-foreground" aria-hidden="true" />}
                label={t(($) => $.dashboard.readiness.loading)}
                value={
                  loadedCount > 0
                    ? t(($) => $.dashboard.readiness.confirmed, { confirmed: confirmedCount, loaded: loadedCount })
                    : t(($) => $.dashboard.readiness.notStarted)
                }
                done={loadedCount > 0 && pendingConfirmations === 0}
              />
              {pendingConfirmations > 0 && (
                <p className="pl-6 text-xs text-amber-600">
                  {t(($) => $.dashboard.readiness.awaiting, { count: pendingConfirmations })}
                </p>
              )}
              <ReadinessRow
                icon={<ClipboardList className="h-4 w-4 text-muted-foreground" aria-hidden="true" />}
                label={t(($) => $.dashboard.readiness.orders)}
                value={String(ordersCount)}
                done={ordersCount > 0}
              />
              {trip.vehicle_plate && (
                <ReadinessRow
                  icon={<Truck className="h-4 w-4 text-muted-foreground" aria-hidden="true" />}
                  label={t(($) => $.dashboard.readiness.vehicle)}
                  value={trip.vehicle_plate}
                  done
                />
              )}
            </div>

            {/* Departure is a SINGLE canonical path (TASK-...-OPERATIONAL-FLOW-VNEXT-001 §16/§22):
                the driver confirms readiness and departs from the Loading workspace's "Ready to
                Start Delivery" action. Trip Detail shows the readiness/blocker and routes there —
                it never carries a second Start/Ready button. */}
            {readyToStart ? (
              <div className="space-y-2">
                <div className="flex items-start gap-2 rounded-lg border border-green-300 bg-green-50 p-3 text-xs text-green-800">
                  <CheckCircle2 className="h-4 w-4 shrink-0" aria-hidden="true" />
                  <span>{t(($) => $.dashboard.ready.hint)}</span>
                </div>
                <Button
                  className="w-full h-12 text-base font-semibold"
                  onClick={() => go(ROUTES.driverLoading)}
                >
                  <Package className="mr-2 h-5 w-5" />
                  {t(($) => $.dashboard.goToDeparture)}
                </Button>
              </div>
            ) : (
              <div className="space-y-2">
                <div className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                  <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" aria-hidden="true" />
                  <div>
                    <p className="font-semibold">{t(($) => $.dashboard.blocker.title)}</p>
                    <p className="text-xs">
                      {pendingConfirmations > 0
                        ? t(($) => $.dashboard.blocker.awaiting, { count: pendingConfirmations })
                        : loadingCompleteFlag
                          ? t(($) => $.dashboard.blocker.awaitingDispatch)
                          : loadedCount > 0
                            ? t(($) => $.dashboard.blocker.finishLoading)
                            : t(($) => $.dashboard.blocker.startLoading)}
                    </p>
                  </div>
                </div>
                <Button className="w-full" variant="default" onClick={() => go(ROUTES.driverLoading)}>
                  <Package className="mr-2 h-4 w-4" />
                  {t(($) => $.dashboard.goToLoading)}
                </Button>
              </div>
            )}
          </>
        )}

        {/* ── ON THE ROAD: deliver + trip tools + finish ── */}
        {onRoad && (
          <div className="space-y-2">
            <Button
              className="w-full"
              variant="default"
              onClick={() => go(ROUTES.driverTripStops.replace(':tripId', tripId))}
            >
              {t(($) => $.dashboard.viewStops)}
            </Button>
            <div className="grid grid-cols-3 gap-2">
              <Button variant="outline" onClick={() => go(ROUTES.driverTripCollections.replace(':tripId', tripId))}>
                <DollarSign className="h-4 w-4" />
              </Button>
              <Button variant="outline" onClick={() => go(ROUTES.driverTripExceptions.replace(':tripId', tripId))}>
                <AlertTriangle className="h-4 w-4" />
              </Button>
              <Button variant="outline" onClick={() => go(ROUTES.driverTripTimeline.replace(':tripId', tripId))}>
                <Clock className="h-4 w-4" />
              </Button>
            </div>
            {/* Finish only from a state the lifecycle can complete (out_for_delivery / in_progress).
                A remaining-stops precondition is intentionally NOT enforced here: the trip payload
                carries no per-stop KPI, so gating on a phantom count would permanently disable it —
                the backend remains the authority and refuses a premature finish. */}
            {(status === 'in_progress' || status === 'out_for_delivery') && (
              <Button
                variant="destructive"
                className="w-full"
                onClick={() => setFinishDialogOpen(true)}
                disabled={finishMutation.isPending}
              >
                <CheckCircle className="mr-2 h-4 w-4" />
                {t(($) => $.dashboard.finishTrip)}
              </Button>
            )}
          </div>
        )}

        {/* ── COMPLETED: settlement + returns + custody ── */}
        {isCompleted && (
          <div className="space-y-2">
            <Button
              className="w-full"
              onClick={() => go(ROUTES.driverTripSettlement.replace(':tripId', tripId))}
            >
              <DollarSign className="mr-2 h-4 w-4" />
              {t(($) => $.dashboard.settlement)}
            </Button>
            <div className="grid grid-cols-2 gap-2">
              <Button variant="outline" onClick={() => go(ROUTES.driverTripReturns.replace(':tripId', tripId))}>
                <RotateCcw className="mr-2 h-4 w-4" />
                {t(($) => $.dashboard.returns)}
              </Button>
              <Button variant="outline" onClick={() => go(ROUTES.driverTripCustody.replace(':tripId', tripId))}>
                {t(($) => $.dashboard.custody)}
              </Button>
            </div>
          </div>
        )}

        {/* ── SETTLEMENT PENDING: the day owes a settlement ── */}
        {isSettlement && (
          <div className="space-y-2">
            <div className="flex items-start gap-2 rounded-lg border p-3 text-xs text-muted-foreground">
              <DollarSign className="h-4 w-4 shrink-0 mt-0.5" aria-hidden="true" />
              <span>{t(($) => $.dashboard.settlementPendingHint)}</span>
            </div>
            <Button
              className="w-full"
              onClick={() => go(ROUTES.driverTripSettlement.replace(':tripId', tripId))}
            >
              <DollarSign className="mr-2 h-4 w-4" />
              {t(($) => $.dashboard.settlement)}
            </Button>
          </div>
        )}

        {/* ── CLOSED: read-only, the day is done ── */}
        {isClosed && (
          <div className="flex flex-col items-center gap-2 rounded-xl border p-6 text-center text-muted-foreground">
            <CheckCircle2 className="h-9 w-9 text-green-600/70" aria-hidden="true" />
            <p className="text-sm font-medium text-foreground">{t(($) => $.dashboard.closed.title)}</p>
            <p className="text-xs">{t(($) => $.dashboard.closed.hint)}</p>
          </div>
        )}

        {/* ── BLOCKED: needs dispatch attention or cancelled ── */}
        {isBlocked && (
          <div className="flex flex-col items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-6 text-center">
            <AlertTriangle className="h-9 w-9 text-red-500/80" aria-hidden="true" />
            <p className="text-sm font-semibold text-red-800">{t(($) => $.dashboard.blocked.title)}</p>
            <p className="text-xs text-red-700">
              {status === 'cancelled'
                ? t(($) => $.dashboard.blocked.cancelled)
                : t(($) => $.dashboard.blocked.dispatchBlocked)}
            </p>
          </div>
        )}
      </div>


      {/* Finish trip dialog */}
      <Dialog open={finishDialogOpen} onOpenChange={setFinishDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t(($) => $.dashboard.finishDialog.title)}</DialogTitle>
            <DialogDescription>
              {t(($) => $.dashboard.finishDialog.description)}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="flex gap-2">
            <Button variant="outline" onClick={() => setFinishDialogOpen(false)}>{t(($) => $.dashboard.cancel)}</Button>
            <Button onClick={handleFinishTrip} disabled={finishMutation.isPending}>
              {finishMutation.isPending ? t(($) => $.dashboard.finishing) : t(($) => $.dashboard.finishTrip)}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function ReadinessRow({ icon, label, value, done }: { icon: ReactNode; label: string; value: string; done?: boolean }) {
  return (
    <div className="flex items-center gap-2 text-sm">
      {icon}
      <span className="text-muted-foreground">{label}</span>
      <span className="ml-auto flex items-center gap-1.5 font-medium tabular-nums">
        {value}
        {done ? (
          <CheckCircle2 className="h-4 w-4 text-green-600" aria-hidden="true" />
        ) : (
          <Circle className="h-4 w-4 text-muted-foreground/40" aria-hidden="true" />
        )}
      </span>
    </div>
  );
}
