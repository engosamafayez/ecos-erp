import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowLeft,
  Ban,
  CheckCircle2,
  CircleDot,
  Clock,
  Package,
  PackageCheck,
  Truck,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { ROUTES } from '@/router/routes';
import { BLOCKED_STATES, hasTripDeparted } from '../lib/trip-lifecycle';
import {
  useCompleteShipmentLoading,
  useConfirmReceivedProduct,
  useDriverLoading,
  useDriverTrips,
  useRequestQuantityAdjustment,
  useStartTrip,
} from '../hooks/use-driver-mobile';
import { loadingErrorMessage } from '../services/driver-mobile-service';
import type { DriverLoadingItem, DriverLoadingWorkflowState } from '../types/driver-mobile';

/**
 * Driver Loading (TASK-DRIVER-03) — the warehouse → driver custody HANDOVER, reworked as
 * an operational per-product screen.
 *
 * THE MODEL IS UNCHANGED AND CERTIFIED. The WAREHOUSE loads (`quantity_loaded`, read-only
 * to the driver); the DRIVER only ACKNOWLEDGES receipt (`confirmReceived`) or reports a
 * discrepancy (`requestAdjustment`). This screen never writes the warehouse quantity and
 * never bypasses the server-side Loading-Complete gate — it only makes the handover clear:
 * Required → Loaded by warehouse → Driver received → Remaining, with one obvious action.
 */

/** Canonical Trip statuses that mean loading cannot start. Read, never invented. */
/** The states that still need THIS driver's confirmation — mirrors the server gate. */
const AWAITING_STATES: DriverLoadingWorkflowState[] = [
  'awaiting_driver_confirmation',
  'awaiting_driver_reconfirmation',
];

type StatusTone = 'muted' | 'amber' | 'green';

/** Derive a per-product status descriptor from the canonical workflow_state (icon + text, never colour alone). */
function statusOf(item: DriverLoadingItem): { key: DriverLoadingWorkflowState; icon: typeof Clock; tone: StatusTone } {
  switch (item.workflow_state) {
    case 'driver_confirmed':
      return { key: 'driver_confirmed', icon: CheckCircle2, tone: 'green' };
    case 'awaiting_driver_confirmation':
      return { key: 'awaiting_driver_confirmation', icon: CircleDot, tone: 'amber' };
    case 'awaiting_driver_reconfirmation':
      return { key: 'awaiting_driver_reconfirmation', icon: AlertTriangle, tone: 'amber' };
    case 'adjustment_requested':
      return { key: 'adjustment_requested', icon: Clock, tone: 'amber' };
    default:
      return { key: 'pending_loading', icon: Clock, tone: 'muted' };
  }
}

const TONE_CLASS: Record<StatusTone, string> = {
  muted: 'bg-muted text-muted-foreground',
  amber: 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
  green: 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-300',
};

function fmtTime(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function LoadingItemRow({
  item,
  disabled,
  pending,
  onConfirm,
  onRequestAdjustment,
}: {
  item: DriverLoadingItem;
  disabled: boolean;
  pending: boolean;
  /** Confirm RECEIPT of the warehouse quantity. Never writes the warehouse's Loaded number. */
  onConfirm: (productId: string) => void;
  /** Report a DIFFERENT received quantity to the warehouse (the existing adjustment mechanism). */
  onRequestAdjustment: (productId: string, reportedQty: number) => void;
}) {
  const { t } = useTranslation('driver-mobile');
  const [reporting, setReporting] = useState(false);
  const [value, setValue] = useState('');

  const status = statusOf(item);
  const StatusIcon = status.icon;
  const productLabel = item.product_name ?? item.product_id;

  const warehouseConfirmed = item.warehouse_confirmed_at !== null;
  const isConfirmed = item.workflow_state === 'driver_confirmed';
  const needsAction = AWAITING_STATES.includes(item.workflow_state);
  const hasOpenAdjustment = item.open_adjustment !== null;
  // ZERO IS A VALID CONFIRMATION (§6/§8): the warehouse loaded nothing for this line, so
  // confirming it is an explicit "checked — none available", not a pending state. The single
  // Confirm tap sends receivedQty = quantity_loaded (0), which the canonical confirm accepts.
  const unavailable = warehouseConfirmed && item.quantity_loaded === 0;

  const parsed = Number(value);
  const canReport = value.trim() !== '' && !Number.isNaN(parsed) && parsed >= 0 && parsed !== item.quantity_loaded;

  return (
    <div className="rounded-xl border bg-card p-4 shadow-sm space-y-3">
      {/* Product + status */}
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-semibold text-sm truncate">{productLabel}</p>
        </div>
        <Badge className={`${TONE_CLASS[status.tone]} gap-1 shrink-0`}>
          <StatusIcon className="h-3 w-3" aria-hidden="true" />
          {t(($) => $.loadingScreen.driverState[status.key])}
        </Badge>
      </div>

      {/* The handover, four distinct facts. */}
      <div className="grid grid-cols-4 gap-2 text-center">
        <HandoverCell label={t(($) => $.loadingScreen.col.required)} value={item.quantity_required} />
        <HandoverCell label={t(($) => $.loadingScreen.loadedByWarehouse)} value={item.quantity_loaded} emphasis />
        <HandoverCell
          label={t(($) => $.loadingScreen.received)}
          value={item.quantity_driver_received}
          notCountedLabel={t(($) => $.loadingScreen.notCounted)}
          tone={item.workflow_state === 'driver_confirmed' ? 'text-green-600' : undefined}
        />
        <HandoverCell label={t(($) => $.loadingScreen.col.remaining)} value={item.quantity_remaining} />
      </div>

      {/* Confirmed state — clear, not colour-only. */}
      {isConfirmed && (
        <p className="flex items-center gap-1.5 text-xs font-medium text-green-700 dark:text-green-300">
          <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
          {t(($) => $.loadingScreen.confirmedState)}
          {item.driver_confirmed_at ? ` · ${t(($) => $.loadingScreen.confirmedAt, { time: fmtTime(item.driver_confirmed_at) })}` : ''}
        </p>
      )}

      {/* Awaiting the warehouse — nothing for the driver to do yet. */}
      {!warehouseConfirmed && !isConfirmed && (
        <p className="text-xs text-muted-foreground">{t(($) => $.loadingScreen.awaitingWarehouse)}</p>
      )}

      {/* An open dispute owns the line until the warehouse rules on it. */}
      {hasOpenAdjustment && (
        <p className="text-xs text-amber-600 dark:text-amber-400" data-testid={`pending-${item.product_id}`}>
          {t(($) => $.loadingScreen.adjustmentPending)}
        </p>
      )}

      {/* Reconfirm banner — the warehouse changed the number the driver had agreed to. */}
      {item.workflow_state === 'awaiting_driver_reconfirmation' && (
        <p className="text-xs text-amber-600 dark:text-amber-400">{t(($) => $.loadingScreen.reconfirmNeeded)}</p>
      )}

      {/* ACTIONS — only when the warehouse has loaded and no dispute is open, and the trip
          is still open. The NORMAL path is a single tap that confirms receipt of the
          warehouse quantity: no editable field, so a driver cannot rubber-stamp by typing. */}
      {!disabled && warehouseConfirmed && !hasOpenAdjustment && needsAction && !reporting && (
        <div className="space-y-2">
          <Button
            variant={unavailable ? 'outline' : 'default'}
            className="h-11 w-full gap-2 text-sm font-semibold"
            disabled={pending}
            aria-label={`${t(($) => unavailable ? $.loadingScreen.confirmNotAvailable : $.loadingScreen.confirmReceived)} — ${productLabel}`}
            data-testid={`confirm-received-${item.product_id}`}
            onClick={() => onConfirm(item.product_id)}
          >
            {unavailable
              ? <Ban className="h-4 w-4" aria-hidden="true" />
              : <CheckCircle2 className="h-4 w-4" aria-hidden="true" />}
            {t(($) => unavailable ? $.loadingScreen.confirmNotAvailable : $.loadingScreen.confirmReceived)}
          </Button>
          <button
            type="button"
            className="w-full text-center text-xs text-muted-foreground underline-offset-2 hover:underline"
            onClick={() => {
              setReporting(true);
              setValue('');
            }}
          >
            {t(($) => $.loadingScreen.reportDifferent)}
          </button>
        </div>
      )}

      {/* Discrepancy path — the EXISTING adjustment mechanism, revealed on demand. */}
      {!disabled && warehouseConfirmed && !hasOpenAdjustment && needsAction && reporting && (
        <div className="space-y-2">
          <Input
            type="number"
            inputMode="decimal"
            min={0}
            value={value}
            aria-label={`${t(($) => $.loadingScreen.reportInputLabel)} — ${productLabel}`}
            onChange={(e) => setValue(e.target.value)}
            className="h-11"
            data-testid={`received-input-${item.product_id}`}
          />
          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant="outline"
              className="h-11 flex-1 gap-1"
              disabled={pending || !canReport}
              data-testid={`request-adjustment-${item.product_id}`}
              onClick={() => onRequestAdjustment(item.product_id, parsed)}
            >
              {t(($) => $.loadingScreen.requestAdjustment)}
            </Button>
            <Button
              size="sm"
              variant="ghost"
              className="h-11 shrink-0"
              onClick={() => setReporting(false)}
            >
              {t(($) => $.loadingScreen.cancel)}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

function HandoverCell({
  label,
  value,
  emphasis,
  tone,
  notCountedLabel,
}: {
  label: string;
  value: number | null;
  emphasis?: boolean;
  tone?: string;
  notCountedLabel?: string;
}) {
  const display = value === null ? (notCountedLabel ?? '—') : value;
  return (
    <div className={`rounded-lg py-2 ${emphasis ? 'bg-primary/10' : 'bg-muted/40'}`}>
      <p className={`text-lg font-bold tabular-nums leading-none ${tone ?? ''} ${emphasis ? 'text-primary' : ''}`}>
        {display}
      </p>
      <p className="mt-1 text-[10px] leading-tight text-muted-foreground">{label}</p>
    </div>
  );
}

export function DriverLoadingPage() {
  const { t } = useTranslation('driver-mobile');
  const navigate = useNavigate();
  const { data: manifest, isLoading, isError, isFetching, refetch } = useDriverLoading();
  const { data: trips } = useDriverTrips();
  // The driver confirms RECEIPT and may REQUEST an adjustment. Neither writes the
  // warehouse's Loaded quantity — that capability is not granted to driver roles.
  const confirmReceived = useConfirmReceivedProduct();
  const requestAdjustment = useRequestQuantityAdjustment();
  const completeMutation = useCompleteShipmentLoading();

  const shipment = manifest?.shipment ?? null;
  const items = manifest?.items ?? [];
  const complete = shipment?.loading_complete ?? false;

  const noManifest = manifest === undefined;
  const showSkeleton = noManifest && !isError && (isFetching || isLoading);
  const showError = isError || (noManifest && !showSkeleton);

  const currentTrip = trips?.[0] ?? null;
  // "Ready to Start Delivery" reuses the canonical trip departure authority — no new endpoint
  // (TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §16/§18).
  const startTrip = useStartTrip(currentTrip?.id ?? '');
  const [departing, setDeparting] = useState(false);
  const [departError, setDepartError] = useState<string | null>(null);
  // Item actions are frozen only once the trip has actually DEPARTED (custody locked) —
  // NOT because the shipment flag says `loading_complete`. A shipment can carry
  // loading_complete while an item still awaits this driver (demo/broken state, §31); the
  // driver must still be able to resolve it (§3, §7, §22). Departure is the real lock.
  const departed = hasTripDeparted(currentTrip?.status);
  const isBlocked =
    (trips?.length ?? 0) > 0 && (trips ?? []).every((trip) => BLOCKED_STATES.includes(trip.status));

  const serverError = confirmReceived.error ?? requestAdjustment.error ?? completeMutation.error;

  // Handover progress + gate — all derived from the SAME canonical workflow_state the
  // server uses, so the screen and the 422 refusal can never disagree.
  const stats = useMemo(() => {
    const list = manifest?.items ?? [];
    return {
      products: list.length,
      warehouseLoaded: list.filter((i) => i.quantity_loaded > 0).length,
      confirmed: list.filter((i) => i.workflow_state === 'driver_confirmed').length,
      awaiting: list.filter((i) => i.quantity_loaded > 0 && AWAITING_STATES.includes(i.workflow_state)).length,
    };
  }, [manifest]);

  const pendingConfirmations = stats.awaiting;
  const progressTotal = Math.max(stats.warehouseLoaded, 1);
  const progressPct = Math.round((stats.confirmed / progressTotal) * 100);

  // STRANDED: the vehicle assignment reached loading_complete but the TRIP never advanced past
  // `loading` (a legacy pre-bridge record, e.g. TRP-003). complete() would short-circuit and
  // startTrip() would no-op, so "Ready to Start Delivery" must NOT be offered (§45/§49) — the
  // trip needs operator/data remediation, not a silent no-op button.
  const stranded = complete && currentTrip?.status === 'loading';
  // READY when every loaded line is confirmed (zero-confirmed lines don't block, §8), the trip
  // has not departed, and it is not stranded. The canonical departure path (§17/§18/§19).
  const canReadyDepart = !departed && !stranded && pendingConfirmations === 0 && items.length > 0;

  function goNext() {
    if (currentTrip) {
      navigate(ROUTES.driverOrders);
      return;
    }
    navigate(ROUTES.driverHome);
  }

  /**
   * READY TO START DELIVERY — the single Driver-facing departure action (§16/§17/§18).
   * Chains the EXISTING canonical authorities: complete loading (→ loading_completed,
   * materialising orders/stops) then the canonical trip departure (→ dispatched → in_progress).
   * No new endpoint, no client-written Trip.status. On success the driver lands on Orders;
   * a partial failure (complete ok, depart refused) leaves the trip at loading_completed and a
   * retry resumes from there.
   */
  async function handleReadyToStartDelivery() {
    if (!currentTrip || departing) {
      return;
    }
    setDeparting(true);
    setDepartError(null);

    const coords = await new Promise<{ lat: number; lng: number }>((resolve) => {
      if (!navigator.geolocation) {
        resolve({ lat: 0, lng: 0 });
        return;
      }
      navigator.geolocation.getCurrentPosition(
        (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
        () => resolve({ lat: 0, lng: 0 }),
        { timeout: 8000 },
      );
    });

    try {
      if (!complete) {
        await completeMutation.mutateAsync();
      }
      await startTrip.mutateAsync({ lat: coords.lat, lng: coords.lng });
      navigate(ROUTES.driverOrders);
    } catch (e) {
      setDepartError(loadingErrorMessage(e));
    } finally {
      setDeparting(false);
    }
  }

  return (
    <div className="min-h-screen bg-background pb-8">
      {/* Operational header — trip identity + handover progress. */}
      <div className="sticky top-0 z-10 space-y-2 border-b bg-background px-4 py-3">
        <div className="flex items-center gap-3">
          <Button
            variant="ghost"
            size="icon"
            aria-label={t(($) => $.loadingScreen.back)}
            onClick={() => navigate(ROUTES.driverHome)}
          >
            <ArrowLeft className="h-5 w-5" aria-hidden="true" />
          </Button>
          <div className="min-w-0 flex-1">
            <h1 className="truncate text-base font-semibold leading-tight">{t(($) => $.loadingScreen.headerTitle)}</h1>
            {currentTrip && (
              <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                <span className="flex items-center gap-1">
                  <Truck className="h-3 w-3" aria-hidden="true" />
                  <span dir="ltr">{currentTrip.vehicle_plate ?? t(($) => $.home.identity.noVehicle)}</span>
                </span>
                <span dir="ltr">{currentTrip.trip_number ?? ''}</span>
              </div>
            )}
          </div>
        </div>

        {/* Progress — only meaningful once the manifest is present with loaded items. */}
        {!showSkeleton && !showError && !isBlocked && shipment !== null && items.length > 0 && (
          <div className="space-y-1">
            <div className="flex items-center justify-between text-xs">
              <span className="text-muted-foreground">
                {t(($) => $.loadingScreen.progress, { done: stats.confirmed, total: stats.warehouseLoaded })}
              </span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
              <div
                className="h-full rounded-full bg-primary transition-all"
                style={{ inlineSize: `${progressPct}%` }}
                role="progressbar"
                aria-valuenow={progressPct}
                aria-valuemin={0}
                aria-valuemax={100}
              />
            </div>
          </div>
        )}
      </div>

      <div className="space-y-4 p-4">
        {showSkeleton ? (
          Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-40 w-full rounded-xl" />)
        ) : showError ? (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
            <AlertTriangle className="h-10 w-10 text-destructive/70" aria-hidden="true" />
            <p className="text-sm">{t(($) => $.loadingScreen.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>
              {t(($) => $.loadingScreen.retry)}
            </Button>
          </div>
        ) : isBlocked ? (
          <div className="flex flex-col items-center justify-center gap-2 py-16 text-center">
            <Ban className="h-10 w-10 text-amber-600 opacity-70" aria-hidden="true" />
            <p className="text-base font-medium">{t(($) => $.loadingScreen.blocked.title)}</p>
            <p className="text-sm text-muted-foreground">{t(($) => $.loadingScreen.blocked.reason)}</p>
          </div>
        ) : shipment === null ? (
          <div className="flex flex-col items-center justify-center py-20 text-muted-foreground">
            <Package className="mb-3 h-12 w-12 opacity-30" aria-hidden="true" />
            <p className="text-base font-medium">{t(($) => $.home.empty.title)}</p>
          </div>
        ) : departed ? (
          <div className="space-y-4">
            <div className="flex flex-col items-center justify-center rounded-xl border border-green-200 bg-green-50 py-10 text-center dark:bg-green-950/30">
              <PackageCheck className="mb-2 h-10 w-10 text-green-600" aria-hidden="true" />
              <p className="text-base font-semibold text-green-700 dark:text-green-300">
                {t(($) => $.loadingScreen.departedTitle)}
              </p>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-xl border bg-card p-4 text-center">
                <p className="text-xs text-muted-foreground">{t(($) => $.loadingScreen.handover.confirmed)}</p>
                <p className="mt-1 text-2xl font-bold tabular-nums">{stats.confirmed}</p>
              </div>
              <div className="rounded-xl border bg-card p-4 text-center">
                <p className="text-xs text-muted-foreground">{t(($) => $.loadingScreen.handover.products)}</p>
                <p className="mt-1 text-2xl font-bold tabular-nums">{stats.products}</p>
              </div>
            </div>
            <Button className="h-12 w-full text-base font-semibold" onClick={goNext}>
              {t(($) => $.loadingScreen.next)}
            </Button>
          </div>
        ) : (
          <>
            {/* Handover summary — Products / Confirmed / Awaiting (canonical counts). */}
            <div className="grid grid-cols-3 gap-3">
              <SummaryTile label={t(($) => $.loadingScreen.handover.products)} value={stats.products} />
              <SummaryTile label={t(($) => $.loadingScreen.handover.confirmed)} value={stats.confirmed} tone="text-green-600" />
              <SummaryTile label={t(($) => $.loadingScreen.handover.awaiting)} value={stats.awaiting} tone="text-amber-600" emphasize={stats.awaiting > 0} />
            </div>

            {serverError !== null && serverError !== undefined && (
              <p
                role="alert"
                className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
              >
                {loadingErrorMessage(serverError)}
              </p>
            )}

            {items.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-16 text-muted-foreground">
                <Package className="mb-3 h-10 w-10 opacity-30" aria-hidden="true" />
                <p className="text-base font-medium">{t(($) => $.loadingScreen.noProducts.title)}</p>
                <p className="mt-1 text-sm">{t(($) => $.loadingScreen.noProducts.subtitle)}</p>
              </div>
            ) : (
              <>
                {items.map((item) => (
                  <LoadingItemRow
                    key={`${item.product_id}:${item.quantity_loaded}:${item.driver_confirmed_at ?? ''}`}
                    item={item}
                    disabled={departed}
                    pending={confirmReceived.isPending || requestAdjustment.isPending}
                    onConfirm={(productId) =>
                      confirmReceived.mutate({
                        productId,
                        // Full receipt of the warehouse quantity — the driver acknowledges what
                        // the warehouse loaded; the warehouse number is never overwritten here.
                        receivedQty: item.quantity_loaded,
                        expectedLoadedQty: item.quantity_loaded,
                      })
                    }
                    onRequestAdjustment={(productId, reportedQty) =>
                      requestAdjustment.mutate({ productId, reportedQty, expectedLoadedQty: item.quantity_loaded })
                    }
                  />
                ))}

                {/* Sticky departure — the SINGLE Driver-facing "Ready to Start Delivery" action
                    (§16/§17). It chains the canonical complete-loading + trip-departure authorities;
                    the server still refuses departure while any warehouse-loaded item awaits this
                    driver. Zero-confirmed lines do NOT block (§8). A stranded trip (assignment
                    complete but trip stuck at loading) is surfaced honestly, never a no-op button. */}
                <div className="sticky bottom-0 -mx-4 border-t bg-background/95 px-4 py-3 backdrop-blur">
                  {stranded ? (
                    <p className="mb-2 text-center text-xs text-amber-600 dark:text-amber-400">
                      {t(($) => $.loadingScreen.awaitingDispatch)}
                    </p>
                  ) : pendingConfirmations > 0 ? (
                    <p
                      className="mb-2 text-center text-xs text-amber-600 dark:text-amber-400"
                      data-testid="driver-loading-pending-reason"
                    >
                      {t(($) => $.loadingScreen.pendingConfirmations, { count: pendingConfirmations })}
                    </p>
                  ) : (
                    <p className="mb-2 text-center text-xs text-muted-foreground">
                      {t(($) => $.loadingScreen.completeReady)} ·{' '}
                      {t(($) => $.loadingScreen.completeCount, { count: stats.confirmed })}
                    </p>
                  )}
                  {departError !== null && (
                    <p role="alert" className="mb-2 text-center text-xs text-destructive">{departError}</p>
                  )}
                  {!stranded && (
                    <Button
                      className="h-12 w-full text-base font-semibold"
                      disabled={!canReadyDepart || departing}
                      data-testid="driver-ready-to-start-delivery"
                      onClick={handleReadyToStartDelivery}
                    >
                      <PackageCheck className="mr-2 h-5 w-5 rtl:ml-2 rtl:mr-0" aria-hidden="true" />
                      {departing ? t(($) => $.loadingScreen.departing) : t(($) => $.loadingScreen.readyToStartDelivery)}
                    </Button>
                  )}
                </div>
              </>
            )}
          </>
        )}
      </div>
    </div>
  );
}

function SummaryTile({ label, value, tone, emphasize }: { label: string; value: number; tone?: string; emphasize?: boolean }) {
  return (
    <div className={`rounded-xl border bg-card p-3 text-center ${emphasize ? 'ring-1 ring-amber-400/50' : ''}`}>
      <p className={`text-2xl font-bold tabular-nums leading-none ${tone ?? ''}`}>{value}</p>
      <p className="mt-1 text-xs text-muted-foreground">{label}</p>
    </div>
  );
}
