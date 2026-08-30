import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  Circle,
  ClipboardList,
  Coins,
  MapPin,
  Package,
  RefreshCw,
  Truck,
  User,
} from 'lucide-react';

import { useAuthStore } from '@/features/auth/store/auth-store';
import { useFormatter } from '@/hooks/use-formatter';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ROUTES } from '@/router/routes';

import { BLOCKED_STATES, COMPLETED_STATES, ON_THE_ROAD, UNRESOLVED_LOADING } from '../lib/trip-lifecycle';
import {
  buildAttention,
  buildCollectionSummary,
  buildCustodySnapshot,
  buildJourney,
  buildOrderMetrics,
  nextStop as pickNextStop,
  statusRank,
  type JourneyStage,
} from '../lib/home-command-center';

import {
  useDriverLoading,
  useDriverStops,
  useDriverTrips,
  useTripSettlement,
  useVehicleInventory,
} from '../hooks/use-driver-mobile';
import type { DeliveryStop, DriverLoadingManifest, DriverTrip } from '../types/driver-mobile';

/**
 * Driver Home — an OPERATIONAL home, not an admin dashboard. It answers, at a glance:
 * who am I working as, which vehicle/trip, what is the current work, and the ONE next
 * action. Everything is derived from canonical driver read models (trips + loading
 * manifest + delivery stops + vehicle inventory); no state is invented client-side and
 * only one primary action is ever offered.
 */

// Lifecycle vocabulary is imported from the single shared map (see ../lib/trip-lifecycle).

type WorkTone = 'loading' | 'ready' | 'delivery' | 'done' | 'blocked' | 'none';

type WorkDetail = { kind: 'stops' | 'awaiting' | 'products'; count: number } | null;

interface DerivedState {
  workKey: 'loading' | 'readyForDelivery' | 'inDelivery' | 'completed' | 'settlementPending' | 'blocked' | 'none';
  tone: WorkTone;
  detail: WorkDetail;
  actionKey:
    | 'startLoading'
    | 'continueLoading'
    | 'confirmReceived'
    | 'loadingComplete'
    | 'viewOrders'
    | 'nextStop'
    | 'tripSummary'
    | 'startSettlement'
    | 'readyToStartDelivery'
    | null;
  actionRoute: string | null;
}

export function DriverHomePage() {
  const { t } = useTranslation('driver-mobile');
  const { money } = useFormatter();
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);

  const { data: trips, isLoading, isError, isFetching, refetch } = useDriverTrips();
  const currentTrip = useMemo<DriverTrip | null>(() => (trips && trips.length > 0 ? trips[0] : null), [trips]);

  const { data: manifest } = useDriverLoading();
  const { data: stops } = useDriverStops(currentTrip?.id ?? '');
  const { data: inventory } = useVehicleInventory();

  // Trip-scoped settlement drives the read-only Collections card (§19). Fetched ONLY once the
  // trip has departed (rank >= dispatched) — during loading it is irrelevant, so gating the
  // id keeps Home from firing a needless read (§32). No cross-trip aggregation, no wallet.
  const departed = statusRank(currentTrip?.status) >= 5;
  const { data: settlement } = useTripSettlement(departed && currentTrip ? currentTrip.id : '');

  const driverName = user?.name?.trim() ? user.name.trim() : null;

  const noTrips = trips === undefined;
  const showSkeleton = noTrips && !isError && (isFetching || isLoading);
  const showError = isError || (noTrips && !showSkeleton);
  const hasTrip = currentTrip !== null;

  // ── Canonical presentation derivations (one place, no fabrication) ──────────
  const stopRows = useMemo<DeliveryStop[]>(() => stops ?? [], [stops]);
  const metrics = useMemo(() => buildOrderMetrics(stopRows), [stopRows]);
  const custody = useMemo(() => buildCustodySnapshot(inventory?.summary ?? null), [inventory]);
  const collections = useMemo(() => buildCollectionSummary(settlement ?? null), [settlement]);
  const next = useMemo(() => pickNextStop(stopRows), [stopRows]);

  const derived = useMemo(
    () => deriveState(currentTrip, manifest ?? null, metrics.remaining),
    [currentTrip, manifest, metrics.remaining],
  );

  const journey = useMemo(
    () => (currentTrip ? buildJourney(currentTrip, manifest ?? null, metrics, custody, settlement ?? null) : []),
    [currentTrip, manifest, metrics, custody, settlement],
  );
  const attention = useMemo(
    () => buildAttention(manifest ?? null, metrics, custody, settlement ?? null, currentTrip?.status),
    [manifest, metrics, custody, settlement, currentTrip],
  );

  // Which contextual sections are relevant to the current phase (§3/§12/§27).
  const phase = derived.workKey;
  const isDelivering = phase === 'inDelivery';
  const isClosing = phase === 'settlementPending';
  const isDone = phase === 'completed';
  const showOrders = isDelivering || isClosing || isDone;
  const showCollections = (isDelivering || isClosing || isDone) && collections.hasData;

  return (
    <div className="min-h-screen bg-background pb-8">
      {/* Identity header */}
      <div className="sticky top-0 z-10 border-b bg-background px-4 py-3">
        <div className="flex items-center justify-between gap-2">
          <div className="flex min-w-0 items-center gap-2">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
              <User className="h-4 w-4" aria-hidden="true" />
            </span>
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold leading-tight">{driverName ?? t(($) => $.app.title)}</p>
              <p className="truncate text-xs text-muted-foreground">{t(($) => $.app.role)}</p>
            </div>
          </div>
          <Button
            variant="ghost"
            size="icon"
            aria-label={t(($) => $.home.refresh)}
            onClick={() => void refetch()}
            disabled={isFetching}
          >
            <RefreshCw className={`h-4 w-4 ${isFetching ? 'animate-spin' : ''}`} aria-hidden="true" />
          </Button>
        </div>
        {hasTrip && (
          <div className="mt-2 flex items-center gap-3 text-xs">
            <span className="flex items-center gap-1 text-muted-foreground">
              <Truck className="h-3.5 w-3.5" aria-hidden="true" />
              <span dir="ltr">{currentTrip?.vehicle_plate ?? t(($) => $.home.identity.noVehicle)}</span>
            </span>
            <span className="flex items-center gap-1 text-muted-foreground">
              <ClipboardList className="h-3.5 w-3.5" aria-hidden="true" />
              <span dir="ltr">{currentTrip?.trip_number ?? t(($) => $.home.identity.noTrip)}</span>
            </span>
          </div>
        )}
      </div>

      <div className="space-y-4 p-4">
        {showSkeleton ? (
          <>
            <Skeleton className="h-28 w-full rounded-xl" />
            <Skeleton className="h-12 w-full rounded-xl" />
            <Skeleton className="h-24 w-full rounded-xl" />
          </>
        ) : showError ? (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
            <Package className="h-10 w-10 opacity-30" aria-hidden="true" />
            <p className="text-sm">{t(($) => $.loadingScreen.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>
              {t(($) => $.loadingScreen.retry)}
            </Button>
          </div>
        ) : !hasTrip ? (
          <div className="flex flex-col items-center justify-center py-20 text-center text-muted-foreground">
            <Package className="mb-3 h-12 w-12 opacity-30" aria-hidden="true" />
            <p className="text-base font-medium">{t(($) => $.home.currentWork.none)}</p>
            <p className="mt-1 text-sm">{t(($) => $.home.currentWork.noneHint)}</p>
          </div>
        ) : (
          <>
            {/* 1 — CURRENT WORK (the dominant element) */}
            <div className={`rounded-xl border p-4 shadow-sm ${WORK_TONE[derived.tone]}`}>
              <p className="text-xs font-medium uppercase tracking-wide opacity-70">
                {t(($) => $.home.currentWork.title)}
              </p>
              <p className="mt-1 text-2xl font-bold leading-tight">{t(($) => $.home.currentWork[derived.workKey])}</p>
              {derived.detail && (
                <p className="mt-1 text-sm opacity-80">
                  {derived.detail.kind === 'stops'
                    ? t(($) => $.home.currentWork.stopsRemaining, { count: derived.detail!.count })
                    : derived.detail.kind === 'awaiting'
                      ? t(($) => $.home.currentWork.awaitingConfirmation, { count: derived.detail!.count })
                      : t(($) => $.home.currentWork.productsCount, { count: derived.detail!.count })}
                </p>
              )}
            </div>

            {/* 2 — NEXT ACTION (single primary) */}
            {derived.actionKey && derived.actionRoute && (
              <Button
                className="h-14 w-full justify-between text-base font-semibold"
                onClick={() => navigate(derived.actionRoute as string)}
              >
                <span>{t(($) => $.home.nextAction[derived.actionKey as NonNullable<DerivedState['actionKey']>])}</span>
                <ArrowRight className="h-5 w-5 rtl:rotate-180" aria-hidden="true" />
              </Button>
            )}

            {/* 4 — DAILY JOURNEY (§11) — the read-only spine of the day. Presentation over
                 canonical status + counts; it mutates nothing and invents no completion. */}
            <div className="rounded-xl border bg-card p-4 shadow-sm">
              <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {t(($) => $.home.journey.title)}
              </p>
              <div className="space-y-2.5">
                {journey.map((stage) => (
                  <JourneyRow
                    key={stage.key}
                    label={t(($) => $.home.journey.stages[stage.key])}
                    stage={stage}
                    detailText={
                      stage.detail
                        ? t(($) => $.home.journey.deliveredOf, {
                            delivered: stage.detail!.delivered,
                            total: stage.detail!.total,
                          })
                        : null
                    }
                  />
                ))}
              </div>
            </div>

            {/* 5 — ORDERS SUMMARY (§13–§15) — only once deliveries are relevant. Received vs
                 delivered are kept distinct; partial is NOT counted as delivered. */}
            {showOrders && (
              <button
                type="button"
                onClick={() => navigate(ROUTES.driverOrders)}
                className="w-full rounded-xl border bg-card p-4 text-start shadow-sm transition-colors hover:bg-accent/40"
              >
                <div className="flex items-center justify-between">
                  <p className="text-sm font-medium">{t(($) => $.home.orders.title)}</p>
                  <span className="text-sm text-muted-foreground">
                    {t(($) => $.home.orders.received, { count: metrics.received })}
                  </span>
                </div>
                <div className="mt-2 grid grid-cols-4 gap-2 text-center text-xs">
                  <MiniStat label={t(($) => $.home.stats.delivered)} value={metrics.delivered} tone="text-green-600" />
                  <MiniStat label={t(($) => $.home.orders.partial)} value={metrics.partial} tone="text-amber-600" />
                  <MiniStat label={t(($) => $.home.stats.pending)} value={metrics.remaining} />
                  <MiniStat label={t(($) => $.home.stats.failed)} value={metrics.failed} tone="text-red-600" />
                </div>
                {metrics.deliveryRatePct !== null && (
                  <p className="mt-2 text-xs text-muted-foreground">
                    {t(($) => $.home.orders.deliveryRate, { pct: metrics.deliveryRatePct })}
                  </p>
                )}
              </button>
            )}

            {/* 6 — NEXT ORDER (§16) — the lowest-sequence unresolved stop. Canonical ordering
                 only; NO route/map/zone sequencing (that is Phase 3). */}
            {isDelivering && next && (
              <button
                type="button"
                onClick={() => navigate(ROUTES.driverOrders)}
                className="w-full rounded-xl border border-primary/30 bg-primary/5 p-4 text-start shadow-sm transition-colors hover:bg-primary/10"
              >
                <div className="flex items-center justify-between">
                  <p className="text-xs font-medium uppercase tracking-wide text-primary/80">
                    {t(($) => $.home.next.title)}
                  </p>
                  {/* Canonical delivery sequence (§8) — "Stop N", never a computed order. */}
                  <span className="text-xs font-semibold text-primary">
                    {t(($) => $.home.next.stop, { sequence: next.sequence })}
                  </span>
                </div>
                <div className="mt-1 flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-base font-semibold" dir="ltr">{next.order.order_number}</p>
                    {next.order.customer_name && (
                      <p className="truncate text-sm text-muted-foreground">{next.order.customer_name}</p>
                    )}
                  </div>
                  <ArrowRight className="h-5 w-5 shrink-0 text-primary rtl:rotate-180" aria-hidden="true" />
                </div>
                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                  {next.order.governorate && (
                    <span className="flex items-center gap-1">
                      <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
                      {next.order.governorate}
                    </span>
                  )}
                  {next.order.remaining_balance > 0 && (
                    <span className="flex items-center gap-1">
                      <Coins className="h-3.5 w-3.5" aria-hidden="true" />
                      {t(($) => $.home.next.cod, { amount: money(next.order.remaining_balance) })}
                    </span>
                  )}
                </div>
              </button>
            )}

            {/* 7 — VEHICLE WAREHOUSE (§17/§18) — the driver's custody, from the Vehicle
                 Inventory authority. Not warehouse administration. */}
            <button
              type="button"
              onClick={() => navigate(ROUTES.driverVehicleInventory)}
              className="w-full rounded-xl border bg-card p-4 text-start shadow-sm transition-colors hover:bg-accent/40"
            >
              <div className="flex items-center justify-between">
                <p className="flex items-center gap-2 text-sm font-medium">
                  <Truck className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                  {t(($) => $.home.stats.vehicleStock)}
                </p>
                <ArrowRight className="h-4 w-4 text-muted-foreground rtl:rotate-180" aria-hidden="true" />
              </div>
              <div className="mt-2 grid grid-cols-3 gap-2 text-center text-xs">
                <MiniStat label={t(($) => $.home.custody.delivered)} value={custody.delivered} tone="text-green-600" />
                <MiniStat label={t(($) => $.home.stats.onHand)} value={custody.onHand} emphasis />
                <MiniStat label={t(($) => $.home.stats.loaded)} value={custody.loaded} />
              </div>
            </button>

            {/* 8 — COLLECTIONS (§19) — trip-scoped, read-only, from TripSettlement. No wallet,
                 no cross-trip aggregation; omitted entirely when the read has no data. */}
            {showCollections && (
              <div className="rounded-xl border bg-card p-4 shadow-sm">
                <p className="flex items-center gap-2 text-sm font-medium">
                  <Coins className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                  {t(($) => $.home.collections.title)}
                </p>
                <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
                  <div className="flex flex-col">
                    <span className="text-xs text-muted-foreground">{t(($) => $.home.collections.expected)}</span>
                    <span className="text-lg font-bold tabular-nums">{money(collections.expected)}</span>
                  </div>
                  <div className="flex flex-col">
                    <span className="text-xs text-muted-foreground">{t(($) => $.home.collections.collected)}</span>
                    <span className="text-lg font-bold tabular-nums text-green-600">{money(collections.collected)}</span>
                  </div>
                </div>
                {Math.abs(collections.difference) > 0.005 && (
                  <p className="mt-2 border-t pt-2 text-xs text-amber-600 dark:text-amber-400">
                    {t(($) => $.home.collections.difference, { amount: money(Math.abs(collections.difference)) })}
                  </p>
                )}
              </div>
            )}

            {/* 9 — NEEDS ATTENTION (§21/§22) — rendered ONLY when real canonical issues exist.
                 These are BUSINESS alerts, visually distinct from a system/read error above. */}
            {attention.length > 0 && (
              <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 shadow-sm dark:border-amber-800 dark:bg-amber-950/30">
                <p className="flex items-center gap-2 text-sm font-semibold text-amber-800 dark:text-amber-200">
                  <AlertTriangle className="h-4 w-4" aria-hidden="true" />
                  {t(($) => $.home.attention.title)}
                </p>
                <ul className="mt-2 space-y-1 text-sm text-amber-800 dark:text-amber-200">
                  {attention.map((item) => (
                    <li key={item.key} className="flex items-start gap-2">
                      <span aria-hidden="true">•</span>
                      <span>{t(($) => $.home.attention.items[item.key], { count: item.count })}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {/* 10 — DAY SUMMARY / CLOSING READINESS (§24/§25) — end of day only. */}
            {(isDone || isClosing) && (
              <div className="rounded-xl border bg-card p-4 shadow-sm">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  {t(($) => $.home.daySummary.title)}
                </p>

                <div className="mt-3 grid grid-cols-4 gap-2 text-center">
                  <MiniStat label={t(($) => $.home.stats.orders)} value={metrics.received} />
                  <MiniStat label={t(($) => $.home.stats.delivered)} value={metrics.delivered} tone="text-green-600" />
                  <MiniStat label={t(($) => $.home.stats.failed)} value={metrics.failed} tone="text-red-600" />
                  <MiniStat label={t(($) => $.home.stats.pending)} value={metrics.remaining} />
                </div>

                <div className="mt-3 space-y-1.5 border-t pt-3 text-sm">
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">{t(($) => $.home.daySummary.vehicleStock)}</span>
                    <span className={custody.onHand > 0 ? 'font-semibold text-amber-600' : 'font-semibold text-green-600'}>
                      {custody.onHand > 0
                        ? t(($) => $.home.daySummary.needsReturn, { count: custody.onHand })
                        : t(($) => $.home.daySummary.clear)}
                    </span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">{t(($) => $.home.daySummary.settlement)}</span>
                    <span className={isClosing ? 'font-semibold text-amber-600' : 'font-semibold text-green-600'}>
                      {isClosing
                        ? t(($) => $.home.daySummary.settlementPending)
                        : t(($) => $.home.daySummary.settlementDone)}
                    </span>
                  </div>
                </div>

                {/* §18 — reach the full wallet + closing indicators for the day. */}
                <button
                  type="button"
                  onClick={() => navigate(ROUTES.driverWallet)}
                  className="mt-3 w-full rounded-lg border py-2 text-xs font-medium text-primary"
                >
                  {t(($) => $.home.daySummary.viewWallet)}
                </button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}

const WORK_TONE: Record<WorkTone, string> = {
  loading: 'border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
  ready: 'border-blue-300 bg-blue-50 text-blue-900 dark:bg-blue-950/30 dark:text-blue-200',
  delivery: 'border-primary/40 bg-primary/5 text-foreground',
  done: 'border-green-300 bg-green-50 text-green-900 dark:bg-green-950/30 dark:text-green-200',
  blocked: 'border-red-300 bg-red-50 text-red-900 dark:bg-red-950/30 dark:text-red-200',
  none: 'border bg-card text-foreground',
};

function JourneyRow({ label, stage, detailText }: { label: string; stage: JourneyStage; detailText: string | null }) {
  const Icon = stage.state === 'done' ? CheckCircle2 : Circle;
  const iconClass =
    stage.state === 'done'
      ? 'text-green-600'
      : stage.state === 'active'
        ? 'text-primary'
        : 'text-muted-foreground/40';
  const labelClass =
    stage.state === 'upcoming' ? 'text-muted-foreground' : 'font-medium';
  return (
    <div className="flex items-center gap-2.5">
      <Icon className={`h-4 w-4 shrink-0 ${iconClass}`} aria-hidden="true" />
      <span className={`flex-1 text-sm ${labelClass}`}>{label}</span>
      {detailText && <span className="text-xs tabular-nums text-muted-foreground">{detailText}</span>}
    </div>
  );
}

function MiniStat({ label, value, tone, emphasis }: { label: string; value: number; tone?: string; emphasis?: boolean }) {
  return (
    <div className={`rounded-lg py-1.5 ${emphasis ? 'bg-primary/10' : 'bg-muted/40'}`}>
      <p className={`text-lg font-bold tabular-nums leading-none ${tone ?? ''} ${emphasis ? 'text-primary' : ''}`}>
        {value}
      </p>
      <p className="mt-0.5 text-[10px] text-muted-foreground">{label}</p>
    </div>
  );
}

/**
 * Derive the driver's current work + single next action from canonical state.
 * Pure (no hooks): returns a structured detail descriptor the component localizes.
 */
function deriveState(trip: DriverTrip | null, manifest: DriverLoadingManifest | null, stopsRemaining: number): DerivedState {
  if (trip === null) {
    return { workKey: 'none', tone: 'none', detail: null, actionKey: null, actionRoute: null };
  }

  const status = trip.status;

  if (BLOCKED_STATES.includes(status)) {
    return { workKey: 'blocked', tone: 'blocked', detail: null, actionKey: null, actionRoute: null };
  }
  /*
   * END OF DAY — TASK-DRIVER-APP-SHELL-HOME-NAVIGATION-FINAL-001 Part 4 (STATE E).
   *
   * `settlement_pending` is NOT "finished": the driving is done but the day is not,
   * and the driver still owes a settlement. Collapsing it into `completed` (as this
   * did) told the driver they were done while work remained. The two are now
   * separate states with different answers to "هل انتهى يومي؟" — one still carries a
   * primary action, the other deliberately carries none.
   */
  if (status === 'settlement_pending') {
    return {
      workKey: 'settlementPending',
      tone: 'done',
      detail: null,
      actionKey: 'startSettlement',
      actionRoute: ROUTES.driverTripSettlement.replace(':tripId', trip.id),
    };
  }
  if (COMPLETED_STATES.includes(status)) {
    return { workKey: 'completed', tone: 'done', detail: null, actionKey: null, actionRoute: null };
  }
  if (ON_THE_ROAD.includes(status)) {
    return {
      workKey: 'inDelivery',
      tone: 'delivery',
      detail: { kind: 'stops', count: stopsRemaining },
      actionKey: 'nextStop',
      actionRoute: ROUTES.driverOrders,
    };
  }

  const items = manifest?.items ?? [];
  const loadingComplete = manifest?.shipment?.loading_complete ?? false;
  const loadedCount = items.filter((i) => i.quantity_loaded > 0).length;
  const pendingConfirmations = items.filter(
    (i) => i.quantity_loaded > 0 && UNRESOLVED_LOADING.includes(i.workflow_state),
  ).length;

  // PENDING CONFIRMATIONS TAKE PRECEDENCE over the shipment `loading_complete` flag —
  // TASK-...-PHASE-2 §7/§22. The flag can be set while an item still awaits this driver
  // (demo/broken state); telling the driver "ready for delivery" then would hide work that
  // is not done. The per-item confirmation state is the authority, so this is checked FIRST.
  if (pendingConfirmations > 0) {
    return {
      workKey: 'loading',
      tone: 'loading',
      detail: { kind: 'awaiting', count: pendingConfirmations },
      actionKey: 'confirmReceived',
      actionRoute: ROUTES.driverLoading,
    };
  }

  // Every loaded line is confirmed (nothing awaiting) and something is loaded → the driver is
  // ready to depart. TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §16/§21 removes the separate
  // "Start Trip" journey: the single departure action ("Ready to Start Delivery") lives on the
  // Loading workspace, so Home routes there — never to a separate Trip/Start-Trip screen and never
  // writing Trip.status. The Loading page owns the honest departure gate (including the stranded
  // case: assignment complete but trip stuck at `loading`, which cannot depart and is surfaced,
  // not no-op'd). Home and Loading therefore stay on the same lifecycle truth.
  if (loadingComplete || loadedCount > 0) {
    return {
      workKey: 'readyForDelivery',
      tone: 'ready',
      detail: null,
      actionKey: 'readyToStartDelivery',
      actionRoute: ROUTES.driverLoading,
    };
  }
  return {
    workKey: 'loading',
    tone: 'loading',
    detail: { kind: 'products', count: items.length },
    actionKey: 'startLoading',
    actionRoute: ROUTES.driverLoading,
  };
}
