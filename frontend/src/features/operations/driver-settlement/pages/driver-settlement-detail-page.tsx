import { useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  ClipboardCheck,
  Clock,
  Eye,
  Info,
  Loader2,
  Package,
  PackageX,
  Truck,
  Warehouse,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useFormatter } from '@/hooks/use-formatter';
import { usePermission } from '@/features/authorization';
import { useToast } from '@/components/ds/use-toast';
import { ROUTES } from '@/router/routes';
import { TripSettlementTab } from '@/features/logistics/trips/components/trip-settlement-tab';
import { tripSettlementService } from '@/features/logistics/trips/services/trip-settlement-service';
import { PaymentProofSection } from '@/features/orders/components/payment-proof-section';
import { OrderDetailDrawer } from '@/features/orders/components/order-detail-drawer';
import type { Order } from '@/features/orders/types/order';
import { useDriverSettlementDetail } from '../hooks/use-driver-settlement';
import type {
  DaySettlementOrderRow,
  DaySettlementProductRow,
  DaySettlementReturnRow,
  DaySettlementTransferRow,
} from '../types/driver-settlement';
import { ClosingStageBadge, DaySettlementStatusBadge } from '../components/day-settlement-status-badge';
import { DriverMovementReview } from '../components/driver-movement-review';

type OrderFilter = 'all' | 'delivered' | 'failed' | 'partial' | 'returned';

/**
 * Driver Day Settlement — DETAIL workspace
 * (TASK-ECOS-DISTRIBUTION-DRIVER-SETTLEMENT-DETAIL-REDESIGN-002).
 *
 * An operational settlement workspace, read-only over canonical authorities. It posts no journal,
 * creates no payment allocation, and never turns a shortage, a damage line or a collection
 * difference into driver debt. The single write it offers is the CANONICAL per-trip finalize, gated
 * exactly as before.
 *
 * Layout: header + preserved closing-blocked banners → a 10-card KPI grid → the Goods / Driver
 * Warehouse position → a six-tab operational workspace (Settlement, Transfers, Returns,
 * Reconciliation, Timeline, Orders).
 *
 * The previous page stacked ~30 stat cards above the tabs across six overlapping sections
 * (Overview, a cash strip, Cash Position, Trip Cash Movements, Sales & Collections, Vehicle
 * Custody). Every one of those figures is still on the page — consolidated into the KPI grid, the
 * Goods section, and the Settlement / Reconciliation tabs. No canonical data or authority was
 * dropped; only the presentation was reorganised.
 *
 * MONEY SEMANTICS — each payment lands in exactly ONE bucket, server-side, by canonical
 * `PaymentType`: physical cash, driver-collected electronic (bank transfer / card), or prepaid
 * before delivery (`already_paid`). Nothing is double-counted, and nothing is inferred from an
 * order's declared payment method.
 */
export function DriverSettlementDetailPage() {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();
  const navigate = useNavigate();
  const { can } = usePermission();
  const { toast } = useToast();

  const { assignmentId = '' } = useParams<{ assignmentId: string }>();
  const [searchParams] = useSearchParams();
  const date = searchParams.get('date') ?? new Date().toISOString().slice(0, 10);

  const numericAssignmentId = Number(assignmentId) || null;
  const { data, isLoading, isError, refetch } = useDriverSettlementDetail(numericAssignmentId, date);

  const [tab, setTab] = useState('settlement');
  const [orderFilter, setOrderFilter] = useState<OrderFilter>('all');
  const [proofOrder, setProofOrder] = useState<DaySettlementTransferRow | null>(null);
  const [detailOrderId, setDetailOrderId] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [finalizing, setFinalizing] = useState(false);

  const canWrite = can('logistics.distribution.update');

  function backToList() {
    navigate(ROUTES.logisticsDriverSettlement);
  }

  // Finalization reuses the CANONICAL per-trip finalize — one call per trip. There is
  // no second finalization endpoint and no day-level settlement record.
  const reconciledTripIds = useMemo(
    () => (data?.trips ?? []).filter((tr) => tr.settlement_status === 'reconciled').map((tr) => tr.id),
    [data],
  );
  const allReconciled =
    (data?.trips.length ?? 0) > 0 && reconciledTripIds.length === (data?.trips.length ?? 0);

  async function finalizeDay() {
    if (!data || !allReconciled) return;
    setFinalizing(true);
    try {
      for (const tripId of reconciledTripIds) {
        await tripSettlementService.finalize(tripId);
      }
      toast({ title: t(($) => $.driverSettlement.finalizeDone) });
      setConfirmOpen(false);
      void refetch();
    } catch {
      toast({ title: t(($) => $.driverSettlement.finalizeFailed), variant: 'destructive' });
    } finally {
      setFinalizing(false);
    }
  }

  if (isLoading) {
    return (
      <div className="space-y-4 p-4">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div className="flex h-72 flex-col items-center justify-center gap-3 text-muted-foreground">
        <AlertTriangle className="h-8 w-8 text-destructive/70" />
        <p className="text-sm">{t(($) => $.driverSettlement.loadError)}</p>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => void refetch()}>
            {t(($) => $.driverSettlement.retry)}
          </Button>
          <Button variant="ghost" size="sm" onClick={backToList}>
            {t(($) => $.driverSettlement.back)}
          </Button>
        </div>
      </div>
    );
  }

  const isSettled = data.settlement_status === 'settled';
  const hasDifference = data.financial.difference !== null && Math.abs(data.financial.difference) >= 0.01;
  const blockers = data.closing_readiness.blockers;
  const c = data.collections;
  const custody = data.custody_summary;
  const firstTrip = data.trips[0];

  // Driver-collected channels. The server already split them by canonical PaymentType; these
  // fallbacks only cover an older payload and re-derive nothing new.
  const instapay = c.instapay ?? 0;
  const wallet = c.wallet ?? 0;
  // Bank transfer + card stay visible in their own right: historical collections recorded before
  // the channel extension live here and are never reinterpreted as InstaPay or Wallet.
  const electronicOther = Math.round((c.bank_transfer + c.card) * 100) / 100;
  const driverElectronic =
    c.driver_collected_electronic ?? Math.round((electronicOther + instapay + wallet) * 100) / 100;
  const collectedFromCustomers = c.driver_collected_total ?? Math.round((c.cash + driverElectronic) * 100) / 100;
  const expectedAvailable = c.expected_collection_available && c.expected_collection !== null;

  // Deliveries are complete when every stop has reached a canonical outcome.
  const stopsOutstanding = data.trips.reduce((n, tr) => n + tr.stops_outstanding, 0);
  const deliveriesComplete = data.overview.orders > 0 && stopsOutstanding === 0;
  const reconComplete =
    custody.reconciliation_available &&
    !blockers.includes('reconciliation_not_opened') &&
    !blockers.includes('unresolved_variance');
  const closingReady = data.closing_readiness.ready;

  const na = t(($) => $.driverSettlement.notAvailable);
  const moneyOrNa = (v: number | null | undefined): string => (v === null || v === undefined ? na : money(v));

  // Canonical outcome counts, shown on the Orders filter chips.
  const outcomeCount: Record<OrderFilter, number> = {
    all: data.overview.orders,
    delivered: data.overview.delivered,
    partial: data.overview.partial,
    failed: data.overview.failed,
    returned: data.overview.returns,
  };

  // Selector mode has no type for a runtime-chosen key, so map each canonical backend code
  // to its explicit static selector (never index the resource tree with a plain string).
  const blockerLabel: Record<string, string> = {
    stops_outstanding: t(($) => $.driverSettlement.blockers.stops_outstanding),
    reconciliation_not_opened: t(($) => $.driverSettlement.blockers.reconciliation_not_opened),
    unresolved_variance: t(($) => $.driverSettlement.blockers.unresolved_variance),
    cash_difference: t(($) => $.driverSettlement.blockers.cash_difference),
    settlement_not_reconciled: t(($) => $.driverSettlement.blockers.settlement_not_reconciled),
    pending_movements: t(($) => $.driverSettlement.blockers.pending_movements),
  };
  const timelineLabel: Record<string, string> = {
    dispatched: t(($) => $.driverSettlement.timeline.dispatched),
    trip_started: t(($) => $.driverSettlement.timeline.trip_started),
    trip_finished: t(($) => $.driverSettlement.timeline.trip_finished),
    cash_submitted: t(($) => $.driverSettlement.timeline.cash_submitted),
    reconciled: t(($) => $.driverSettlement.timeline.reconciled),
    closed: t(($) => $.driverSettlement.timeline.closed),
    reconciliation_opened: t(($) => $.driverSettlement.timeline.reconciliation_opened),
    reconciliation_completed: t(($) => $.driverSettlement.timeline.reconciliation_completed),
  };
  const reconStatusLabel: Record<string, string> = {
    received: t(($) => $.driverSettlement.reconStatus.received),
    pending: t(($) => $.driverSettlement.reconStatus.pending),
    not_reconciled: t(($) => $.driverSettlement.reconStatus.not_reconciled),
  };

  return (
    <div className="flex h-full flex-col overflow-y-auto">
      {/* ── Header ─────────────────────────────────────────────────────────────── */}
      <div className="sticky top-0 z-10 border-b bg-background px-4 py-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <Button variant="ghost" size="icon" onClick={backToList} aria-label={t(($) => $.driverSettlement.back)}>
              <ArrowLeft className="h-5 w-5" data-flip-rtl />
            </Button>
            <div className="min-w-0">
              <h1 className="truncate text-base font-semibold leading-tight">
                {data.driver.name ?? t(($) => $.driverSettlement.unknownDriver)}
              </h1>
              <p className="truncate text-xs text-muted-foreground">
                {t(($) => $.driverSettlement.detail.subtitle)} · {data.driver.vehicle_plate ?? '—'} · {date}
              </p>
            </div>
            <ClosingStageBadge stage={data.closing_stage} />
            <DaySettlementStatusBadge status={data.settlement_status} />
          </div>
          {canWrite && !isSettled && (
            <Button size="sm" disabled={!allReconciled} onClick={() => setConfirmOpen(true)} className="gap-1.5">
              <ClipboardCheck className="h-4 w-4" />
              {t(($) => $.driverSettlement.approve)}
            </Button>
          )}
        </div>
      </div>

      <div className="space-y-4 p-4">
        {/* ── Banners — canonical closing state, preserved verbatim ─────────────── */}
        {isSettled ? (
          <Banner tone="ok" icon={<CheckCircle2 className="h-4 w-4" />} text={t(($) => $.driverSettlement.completedBanner)} />
        ) : (
          <>
            {hasDifference && (
              <Banner tone="bad" icon={<AlertTriangle className="h-4 w-4" />} text={t(($) => $.driverSettlement.differenceBanner)} />
            )}
            {!closingReady && blockers.length > 0 && (
              <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                <div className="flex items-center gap-2 font-medium">
                  <Info className="h-4 w-4" />
                  {t(($) => $.driverSettlement.blockersTitle)}
                </div>
                <ul className="mt-1 list-disc space-y-0.5 ps-8 text-xs">
                  {blockers.map((b) => (
                    <li key={b}>{blockerLabel[b] ?? b}</li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}

        {/* ── KPI grid — 10 canonical concepts, 5 per row from lg ───────────────── */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          {/* Row 1 — delivery + commercial */}
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.ordersDelivered)}
            value={`${data.overview.orders} / ${data.overview.delivered}`}
            caption={t(($) => $.driverSettlement.detail.kpi.ordersDeliveredCaption)}
          />
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.deliveryPct)}
            value={`${data.overview.delivery_pct}%`}
            caption={t(($) => $.driverSettlement.detail.kpi.deliveryPctCaption)}
          />
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.deliveredSales)}
            value={money(c.delivered_sales)}
            caption={t(($) => $.driverSettlement.detail.kpi.deliveredSalesCaption)}
          />
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.cashCollected)}
            value={money(c.cash)}
            caption={t(($) => $.driverSettlement.detail.kpi.cashCollectedCaption)}
          />
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.alreadyPaid)}
            value={money(c.already_paid)}
            caption={t(($) => $.driverSettlement.detail.kpi.alreadyPaidCaption)}
          />

          {/* Row 2 — the two intended driver-collected channel KPIs, now backed by canonical
              PaymentType cases (TASK-...-DRIVER-COLLECTION-CHANNELS-003). These are ACTUAL
              driver collections, never inferred from an order's declared payment method. Bank
              Transfer and Card keep their own rows in the Settlement summary, the Reconciliation
              ledger and the Transfers tab — no data was discarded to free these slots. */}
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.instapay)}
            value={money(instapay)}
            caption={t(($) => $.driverSettlement.detail.kpi.instapayCaption)}
          />
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.wallet)}
            value={money(wallet)}
            caption={t(($) => $.driverSettlement.detail.kpi.walletCaption)}
          />
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.expenses)}
            value={money(data.financial.expenses)}
            caption={t(($) => $.driverSettlement.detail.kpi.expensesCaption)}
            tone={data.financial.expenses > 0 ? 'text-destructive' : undefined}
          />
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.cashIn)}
            value={money(data.financial.cash_in)}
            caption={t(($) => $.driverSettlement.detail.kpi.cashInCaption)}
            tone={data.financial.cash_in > 0 ? 'text-emerald-600 dark:text-emerald-400' : undefined}
          />
          <Kpi
            label={t(($) => $.driverSettlement.detail.kpi.collectionDifference)}
            value={moneyOrNa(c.collection_difference)}
            caption={t(($) => $.driverSettlement.detail.kpi.collectionDifferenceCaption)}
            tone={
              c.collection_difference === null || Math.abs(c.collection_difference) < 0.01
                ? undefined
                : c.collection_difference < 0
                  ? 'text-destructive'
                  : 'text-emerald-600 dark:text-emerald-400'
            }
          />
        </div>

        {/* ── GOODS · Driver Warehouse ─────────────────────────────────────────── */}
        <section className="rounded-lg border bg-muted/20 p-3">
          <div className="mb-2 flex items-center gap-2">
            <span className="flex h-7 w-7 items-center justify-center rounded-md bg-primary/10 text-primary">
              <Warehouse className="h-4 w-4" />
            </span>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide">{t(($) => $.driverSettlement.detail.goods.title)}</p>
              <p className="text-[11px] text-muted-foreground">{t(($) => $.driverSettlement.detail.goods.subtitle)}</p>
            </div>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <GoodsCard
              label={t(($) => $.driverSettlement.detail.goods.loaded)}
              value={custody.total_loaded}
              caption={t(($) => $.driverSettlement.detail.goods.loadedCaption)}
            />
            <GoodsCard
              label={t(($) => $.driverSettlement.detail.goods.delivered)}
              value={custody.total_delivered}
              caption={t(($) => $.driverSettlement.detail.goods.deliveredCaption)}
            />
            {/* Remaining is the CURRENT on-hand stock in the canonical Driver/Vehicle Warehouse —
                never loaded − delivered, which would ignore returns, damage and adjustments.
                Actionable: opens the per-SKU custody list in Reconciliation. */}
            <GoodsCard
              label={t(($) => $.driverSettlement.detail.goods.remaining)}
              value={custody.remaining_on_hand}
              caption={t(($) => $.driverSettlement.detail.goods.remainingCaption)}
              emphasis
              onOpen={() => setTab('reconciliation')}
              openLabel={t(($) => $.driverSettlement.detail.goods.viewSkus)}
            />
          </div>
          <GapNote text={t(($) => $.driverSettlement.detail.goods.remainingNote)} />
        </section>

        {/* ── Operational workspace — six tabs, Settlement first ────────────────── */}
        <Tabs value={tab} onValueChange={setTab} className="w-full">
          <TabsList className="h-auto flex-wrap">
            <TabsTrigger value="settlement">{t(($) => $.driverSettlement.tabs.settlement)}</TabsTrigger>
            <TabsTrigger value="transfers">{t(($) => $.driverSettlement.tabs.transfers)}</TabsTrigger>
            <TabsTrigger value="returns">{t(($) => $.driverSettlement.tabs.returns)}</TabsTrigger>
            <TabsTrigger value="reconciliation">{t(($) => $.driverSettlement.tabs.reconciliation)}</TabsTrigger>
            <TabsTrigger value="timeline">{t(($) => $.driverSettlement.tabs.timeline)}</TabsTrigger>
            <TabsTrigger value="orders">{t(($) => $.driverSettlement.tabs.orders)}</TabsTrigger>
          </TabsList>

          {/* ── 1. Settlement ──────────────────────────────────────────────────── */}
          <TabsContent value="settlement" className="space-y-4 pt-3">
            <div className="grid gap-4 lg:grid-cols-3">
              {/* Driver / Trip context — read-only, no editable control here (§24). */}
              <section className="rounded-lg border p-3">
                <SectionTitle text={t(($) => $.driverSettlement.detail.settlement.contextTitle)} />
                <dl className="space-y-1.5 text-sm">
                  <ContextRow label={t(($) => $.driverSettlement.detail.settlement.driver)} value={data.driver.name} />
                  <ContextRow label={t(($) => $.driverSettlement.detail.settlement.vehicle)} value={data.driver.vehicle_plate} />
                  <ContextRow
                    label={t(($) => $.driverSettlement.detail.settlement.trip)}
                    value={firstTrip?.trip_number ?? null}
                    mono
                  />
                  <ContextRow
                    label={t(($) => $.driverSettlement.detail.settlement.tripDate)}
                    value={firstTrip?.operational_date ?? date}
                  />
                  <ContextRow
                    label={t(($) => $.driverSettlement.detail.settlement.tripStatus)}
                    value={firstTrip?.trip_status ?? null}
                  />
                  <ContextRow
                    label={t(($) => $.driverSettlement.detail.settlement.trips)}
                    value={String(data.overview.trips)}
                  />
                </dl>
              </section>

              {/* Settlement summary — one row per canonical fact, no netting between them. */}
              <section className="rounded-lg border p-3 lg:col-span-2">
                <SectionTitle text={t(($) => $.driverSettlement.detail.settlement.summaryTitle)} />
                <dl className="divide-y text-sm">
                  <SummaryRow
                    label={t(($) => $.driverSettlement.detail.settlement.expectedCollection)}
                    value={expectedAvailable ? money(c.expected_collection as number) : na}
                    muted={!expectedAvailable}
                  />
                  <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.cashPhysical)} value={money(c.cash)} />
                  <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.instapay)} value={money(instapay)} />
                  <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.wallet)} value={money(wallet)} />
                  <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.bankTransfer)} value={money(c.bank_transfer)} />
                  <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.card)} value={money(c.card)} />
                  <SummaryRow
                    label={t(($) => $.driverSettlement.detail.settlement.totalCollected)}
                    value={money(collectedFromCustomers)}
                    hint={t(($) => $.driverSettlement.detail.settlement.totalCollectedHint)}
                    strong
                  />
                  <SummaryRow
                    label={t(($) => $.driverSettlement.detail.settlement.alreadyPaid)}
                    value={money(c.already_paid)}
                    hint={t(($) => $.driverSettlement.detail.kpi.alreadyPaidCaption)}
                  />
                  <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.expenses)} value={money(data.financial.expenses)} />
                  <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.cashIn)} value={money(data.financial.cash_in)} />
                  <SummaryRow
                    label={t(($) => $.driverSettlement.detail.settlement.difference)}
                    value={moneyOrNa(c.collection_difference)}
                    muted={c.collection_difference === null}
                    strong
                  />
                  <SummaryRow
                    label={t(($) => $.driverSettlement.detail.settlement.netCash)}
                    value={money(data.financial.net_cash)}
                    strong
                  />
                </dl>
              </section>
            </div>

            {/* Settlement status — canonical states only; no new closing condition (§23). */}
            <section className="rounded-lg border p-3">
              <SectionTitle text={t(($) => $.driverSettlement.detail.settlement.statusTitle)} />
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatusPill
                  label={t(($) => $.driverSettlement.detail.settlement.deliveries)}
                  ok={deliveriesComplete}
                  okText={t(($) => $.driverSettlement.detail.settlement.deliveriesComplete)}
                  badText={t(($) => $.driverSettlement.detail.settlement.deliveriesIncomplete)}
                />
                <StatusPill
                  label={t(($) => $.driverSettlement.detail.settlement.reconciliation)}
                  ok={reconComplete}
                  okText={t(($) => $.driverSettlement.detail.settlement.reconComplete)}
                  badText={t(($) => $.driverSettlement.detail.settlement.reconPending)}
                />
                <StatusPill
                  label={t(($) => $.driverSettlement.detail.settlement.differenceLabel)}
                  ok={data.financial.is_balanced === true}
                  unknown={data.financial.is_balanced === null}
                  okText={t(($) => $.driverSettlement.detail.settlement.balanced)}
                  badText={t(($) => $.driverSettlement.detail.settlement.unbalanced)}
                  unknownText={na}
                />
                <StatusPill
                  label={t(($) => $.driverSettlement.detail.settlement.closing)}
                  ok={closingReady}
                  okText={t(($) => $.driverSettlement.detail.settlement.closingAvailable)}
                  badText={t(($) => $.driverSettlement.detail.settlement.closingBlocked)}
                />
              </div>
            </section>

            {/* Trip Cash Movements — relocated out of the top-level hierarchy into Settlement (§30).
                Same canonical DriverTripMovement authority and the same approve/reject review. */}
            <DriverMovementReview
              movements={data.movements}
              assignmentId={numericAssignmentId}
              date={date}
              canReview={canWrite}
            />

            {/* The canonical per-trip settlement panel — the authority for the trip's own
                settlement lifecycle. Reused, never reimplemented. */}
            {data.trips.map((tr) => (
              <div key={tr.id} className="rounded-lg border p-3">
                <p className="mb-2 font-mono text-xs text-muted-foreground">{tr.trip_number ?? tr.id.slice(0, 8)}</p>
                <TripSettlementTab tripId={tr.id} />
              </div>
            ))}
          </TabsContent>

          {/* ── 2. Transfers ───────────────────────────────────────────────────── */}
          <TabsContent value="transfers" className="space-y-2 pt-3">
            {/* The canonical aggregates for this tab. `approved_transfers` is the VERIFIED subset
                only — a recorded-but-unverified collection is deliberately not "approved" — so the
                two figures are reported side by side instead of being blended. */}
            <div className="grid grid-cols-2 gap-3">
              <Kpi
                label={t(($) => $.driverSettlement.detail.transfers.electronicTotal)}
                value={money(driverElectronic)}
                caption={t(($) => $.driverSettlement.detail.transfers.driverCollected)}
              />
              <Kpi
                label={t(($) => $.driverSettlement.detail.transfers.approvedTotal)}
                value={money(data.financial.approved_transfers)}
                caption={t(($) => $.driverSettlement.detail.transfers.approvedHint)}
              />
            </div>
            <GapNote text={t(($) => $.driverSettlement.detail.transfers.note)} />
            {data.transfers.length === 0 ? (
              <EmptyPanel icon={<Truck className="h-7 w-7 opacity-30" />} label={t(($) => $.driverSettlement.emptyTransfers)} />
            ) : (
              <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-xs">
                  <thead className="bg-muted/40 text-muted-foreground">
                    <tr className="[&>th]:px-3 [&>th]:py-2 [&>th]:text-start">
                      <th>{t(($) => $.driverSettlement.detail.orders.order)}</th>
                      <th>{t(($) => $.driverSettlement.detail.transfers.method)}</th>
                      <th className="text-end">{t(($) => $.driverSettlement.detail.orders.value)}</th>
                      <th>{t(($) => $.driverSettlement.detail.transfers.reference)}</th>
                      <th>{t(($) => $.driverSettlement.detail.transfers.submitted)}</th>
                      <th>{t(($) => $.driverSettlement.columns.status)}</th>
                      <th className="text-end">{t(($) => $.driverSettlement.columns.action)}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {data.transfers.map((tr, i) => (
                      <tr key={`${tr.order_id ?? 'x'}-${tr.payment_type}-${i}`} className="[&>td]:px-3 [&>td]:py-2">
                        <td className="min-w-0">
                          <p className="font-mono">{tr.order_number ?? '—'}</p>
                          <p className="truncate text-muted-foreground">{tr.customer_name ?? '—'}</p>
                        </td>
                        <td>
                          <Badge variant="secondary" className="text-[10px]">{tr.payment_label}</Badge>
                          <p className="mt-0.5 text-[10px] text-muted-foreground">
                            {t(($) => $.driverSettlement.detail.transfers.driverCollected)}
                          </p>
                        </td>
                        <td className="text-end tabular-nums">{money(tr.amount)}</td>
                        <td className="font-mono text-[11px] text-muted-foreground">{tr.reference_number ?? '—'}</td>
                        <td className="tabular-nums text-[11px] text-muted-foreground">
                          {tr.collected_at ? new Date(tr.collected_at).toLocaleString() : '—'}
                        </td>
                        <td>
                          <Badge variant="outline" className="text-[10px] capitalize">
                            {tr.proof ? tr.proof.state : t(($) => $.driverSettlement.noProof)}
                          </Badge>
                          <p className="mt-0.5 text-[10px] capitalize text-muted-foreground">{tr.collection_status}</p>
                        </td>
                        <td className="text-end">
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1.5 text-xs"
                            disabled={!tr.order_id}
                            onClick={() => setProofOrder(tr)}
                          >
                            <Eye className="h-3.5 w-3.5" />
                            {t(($) => $.driverSettlement.viewProof)}
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </TabsContent>

          {/* ── 3. Returns ─────────────────────────────────────────────────────── */}
          <TabsContent value="returns" className="space-y-2 pt-3">
            <GapNote text={t(($) => $.driverSettlement.detail.returns.note)} />
            <ReturnsTable rows={data.returns} />
          </TabsContent>

          {/* ── 4. Reconciliation ──────────────────────────────────────────────── */}
          <TabsContent value="reconciliation" className="space-y-4 pt-3">
            {/* Why the settlement balances or does not — the canonical components, in order. */}
            <section className="rounded-lg border p-3">
              <SectionTitle text={t(($) => $.driverSettlement.detail.recon.ledgerTitle)} />
              <dl className="divide-y text-sm">
                <SummaryRow
                  label={t(($) => $.driverSettlement.detail.settlement.expectedCollection)}
                  value={expectedAvailable ? money(c.expected_collection as number) : na}
                  muted={!expectedAvailable}
                />
                <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.alreadyPaid)} value={money(c.already_paid)} />
                <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.cashPhysical)} value={money(c.cash)} />
                <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.instapay)} value={money(instapay)} />
                <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.wallet)} value={money(wallet)} />
                <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.bankTransfer)} value={money(c.bank_transfer)} />
                <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.card)} value={money(c.card)} />
                <SummaryRow
                  label={t(($) => $.driverSettlement.detail.settlement.totalCollected)}
                  value={money(collectedFromCustomers)}
                  hint={t(($) => $.driverSettlement.detail.settlement.totalCollectedHint)}
                  strong
                />
                <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.expenses)} value={money(data.financial.expenses)} />
                <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.cashIn)} value={money(data.financial.cash_in)} />
                <SummaryRow
                  label={t(($) => $.driverSettlement.detail.settlement.difference)}
                  value={moneyOrNa(c.collection_difference)}
                  muted={c.collection_difference === null}
                  strong
                />
              </dl>
              {!c.expected_collection_available && <GapNote text={t(($) => $.driverSettlement.expectedCollectionNote)} />}
            </section>

            {/* Physical-cash custody — the settlement engine's own cash reconciliation. Distinct
                from the collection reconciliation above: this one compares expected physical cash
                against what the driver actually handed back, and drives the difference banner. */}
            <section className="rounded-lg border p-3">
              <SectionTitle text={t(($) => $.driverSettlement.detail.recon.cashCustodyTitle)} />
              <dl className="divide-y text-sm">
                <SummaryRow
                  label={t(($) => $.driverSettlement.financial.cashExpected)}
                  value={money(data.financial.cash_expected)}
                />
                <SummaryRow
                  label={t(($) => $.driverSettlement.financial.actualCash)}
                  value={moneyOrNa(data.financial.actual_cash)}
                  muted={data.financial.actual_cash === null}
                />
                <SummaryRow
                  label={t(($) => $.driverSettlement.financial.difference)}
                  value={moneyOrNa(data.financial.difference)}
                  muted={data.financial.difference === null}
                  strong
                />
              </dl>
              <GapNote text={t(($) => $.driverSettlement.detail.recon.cashCustodyHint)} />
            </section>

            {/* Custody quantity aggregates — the former Vehicle Custody strip, in its proper tab. */}
            {custody.reconciliation_available ? (
              <section>
                <SectionTitle text={t(($) => $.driverSettlement.detail.recon.custodyTitle)} />
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                  <Kpi label={t(($) => $.driverSettlement.custody.expectedReturn)} value={String(custody.expected_return)} />
                  <Kpi label={t(($) => $.driverSettlement.custody.actualReturn)} value={String(custody.actual_return)} />
                  <Kpi label={t(($) => $.driverSettlement.custody.accepted)} value={String(custody.accepted)} />
                  <Kpi
                    label={t(($) => $.driverSettlement.custody.damaged)}
                    value={String(custody.damaged)}
                    tone={custody.damaged > 0 ? 'text-destructive' : undefined}
                  />
                  <Kpi
                    label={t(($) => $.driverSettlement.custody.shortage)}
                    value={String(custody.shortage)}
                    tone={custody.shortage > 0 ? 'text-orange-600 dark:text-orange-400' : undefined}
                  />
                </div>
              </section>
            ) : (
              <div className="rounded-lg border border-dashed px-4 py-3 text-xs text-muted-foreground">
                {t(($) => $.driverSettlement.custody.notReconciled)}
              </div>
            )}

            {/* Custody exceptions — visible facts, never automatic liabilities (§27/§33). */}
            <section>
              <SectionTitle text={t(($) => $.driverSettlement.detail.recon.exceptionsTitle)} />
              {data.damage.items.length === 0 && data.shortage_review.items.length === 0 ? (
                <div className="rounded-lg border border-dashed px-4 py-3 text-xs text-muted-foreground">
                  {t(($) => $.driverSettlement.detail.recon.noExceptions)}
                </div>
              ) : (
                <div className="space-y-3">
                  {data.damage.items.length > 0 && (
                    <div>
                      <p className="mb-1 text-[11px] font-medium uppercase text-muted-foreground">
                        {t(($) => $.driverSettlement.damageTitle)}
                      </p>
                      <div className="divide-y rounded-lg border text-sm">
                        {data.damage.items.map((d, i) => (
                          <div key={i} className="flex items-center justify-between gap-2 px-4 py-2.5">
                            <span className="inline-flex items-center gap-1.5 truncate">
                              <PackageX className="h-3.5 w-3.5 text-destructive" />
                              {d.product_name}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">{d.reason ?? '—'}</span>
                            <span className="tabular-nums text-destructive">{d.quantity}</span>
                          </div>
                        ))}
                      </div>
                      <GapNote text={t(($) => $.driverSettlement.damageGapNote)} />
                    </div>
                  )}
                  {data.shortage_review.items.length > 0 && (
                    <div>
                      <p className="mb-1 text-[11px] font-medium uppercase text-muted-foreground">
                        {t(($) => $.driverSettlement.shortageTitle)}
                      </p>
                      <div className="divide-y rounded-lg border text-sm">
                        {data.shortage_review.items.map((s, i) => (
                          <div key={i} className="flex items-center justify-between gap-2 px-4 py-2.5">
                            <span className="truncate">{s.product_name}</span>
                            <Badge variant="outline" className="text-[10px] capitalize">
                              {s.reconciliation_status}
                            </Badge>
                            <span className="tabular-nums text-orange-600 dark:text-orange-400">{s.variance}</span>
                          </div>
                        ))}
                      </div>
                      <GapNote text={t(($) => $.driverSettlement.shortageGapNote)} />
                    </div>
                  )}
                </div>
              )}
              <GapNote text={t(($) => $.driverSettlement.detail.recon.liabilityNote)} />
            </section>

            <div>
              <SectionTitle text={t(($) => $.driverSettlement.productReconTitle)} />
              <ProductReconciliationTable rows={data.product_reconciliation} statusLabel={reconStatusLabel} />
            </div>

            {/* Per-SKU Driver Warehouse stock — the drill-down behind Goods · Remaining. */}
            <div>
              <SectionTitle
                text={`${t(($) => $.driverSettlement.goodsTitle)} · ${custody.remaining_on_hand} ${t(($) => $.driverSettlement.units)}`}
              />
              {data.goods_remaining.length === 0 ? (
                <EmptyPanel icon={<Package className="h-7 w-7 opacity-30" />} label={t(($) => $.driverSettlement.emptyGoods)} />
              ) : (
                <div className="divide-y rounded-lg border text-sm">
                  {data.goods_remaining.map((g) => (
                    <div key={String(g.product_id)} className="flex items-center justify-between px-4 py-2.5">
                      <span className="truncate">{g.product_name ?? '—'}</span>
                      <span className="tabular-nums text-xs">{g.quantity_on_hand}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </TabsContent>

          {/* ── 5. Timeline ────────────────────────────────────────────────────── */}
          <TabsContent value="timeline" className="pt-3">
            {data.timeline.length === 0 ? (
              <EmptyPanel icon={<Clock className="h-7 w-7 opacity-30" />} label={t(($) => $.driverSettlement.emptyTimeline)} />
            ) : (
              <ol className="relative space-y-3 border-s ps-5 text-sm">
                {data.timeline.map((e, i) => (
                  <li key={i} className="relative">
                    <span className="absolute -start-[23px] top-1 h-2.5 w-2.5 rounded-full bg-primary/60" />
                    <p className="font-medium">{timelineLabel[e.code] ?? e.code}</p>
                    <p className="tabular-nums text-[11px] text-muted-foreground">{new Date(e.at).toLocaleString()}</p>
                  </li>
                ))}
              </ol>
            )}
          </TabsContent>

          {/* ── 6. Orders ──────────────────────────────────────────────────────── */}
          <TabsContent value="orders" className="space-y-3 pt-3">
            {/* The chips carry the canonical outcome COUNTS, so Partial / Failed / Returned stay
                on the page after the KPI consolidation — no canonical figure was dropped (§15). */}
            <div className="flex flex-wrap items-center gap-1.5">
              {(['all', 'delivered', 'partial', 'failed', 'returned'] as OrderFilter[]).map((f) => (
                <button
                  key={f}
                  onClick={() => setOrderFilter(f)}
                  className={`rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                    orderFilter === f
                      ? 'bg-primary text-primary-foreground'
                      : 'bg-muted text-muted-foreground hover:text-foreground'
                  }`}
                >
                  {f === 'all' ? t(($) => $.driverSettlement.filterAll) : t(($) => $.driverSettlement.orderStatus[f])}
                  <span className="ms-1.5 tabular-nums opacity-70">{outcomeCount[f]}</span>
                </button>
              ))}
            </div>
            <OrdersTable
              rows={data.orders.filter((o) => orderFilter === 'all' || o.status === orderFilter)}
              onOpen={(id) => setDetailOrderId(id)}
              money={money}
              na={na}
              emptyLabel={t(($) => $.driverSettlement.emptyOrders)}
            />
          </TabsContent>
        </Tabs>
      </div>

      {/* Payment proof modal — reuses the canonical payment_proofs review UI */}
      <Dialog open={proofOrder !== null} onOpenChange={(o) => !o && setProofOrder(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>{t(($) => $.driverSettlement.proofTitle)}</DialogTitle>
            <DialogDescription>
              {proofOrder?.order_number} · {proofOrder?.customer_name ?? '—'} · {money(proofOrder?.amount ?? 0)}
            </DialogDescription>
          </DialogHeader>
          {proofOrder?.order_id && (
            <PaymentProofSection orderId={proofOrder.order_id} paymentMethod={proofOrder.payment_type} />
          )}
        </DialogContent>
      </Dialog>

      {/* Order detail — reuses the canonical OrderDetailDrawer (self-refetches by id) */}
      <OrderDetailDrawer
        order={detailOrderId ? ({ id: detailOrderId } as Order) : null}
        open={detailOrderId !== null}
        onOpenChange={(o) => !o && setDetailOrderId(null)}
      />

      {/* Finalization confirmation */}
      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t(($) => $.driverSettlement.confirmTitle)}</DialogTitle>
            <DialogDescription>{t(($) => $.driverSettlement.confirmBody)}</DialogDescription>
          </DialogHeader>
          <dl className="divide-y text-sm">
            <SummaryRow
              label={t(($) => $.driverSettlement.detail.kpi.ordersDelivered)}
              value={`${data.overview.orders} / ${data.overview.delivered}`}
            />
            <SummaryRow label={t(($) => $.driverSettlement.columns.returns)} value={String(data.overview.returns)} />
            <SummaryRow
              label={t(($) => $.driverSettlement.detail.settlement.totalCollected)}
              value={money(collectedFromCustomers)}
            />
            <SummaryRow
              label={t(($) => $.driverSettlement.detail.settlement.difference)}
              value={moneyOrNa(c.collection_difference)}
              muted={c.collection_difference === null}
            />
            <SummaryRow label={t(($) => $.driverSettlement.detail.settlement.netCash)} value={money(data.financial.net_cash)} strong />
          </dl>
          {hasDifference && (
            <p className="flex items-center gap-1.5 text-xs text-destructive">
              <AlertTriangle className="h-3.5 w-3.5" />
              {t(($) => $.driverSettlement.differenceBanner)}
            </p>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmOpen(false)}>
              {t(($) => $.driverSettlement.cancel)}
            </Button>
            <Button disabled={finalizing || !allReconciled} onClick={() => void finalizeDay()}>
              {finalizing ? <Loader2 className="h-4 w-4 animate-spin" /> : t(($) => $.driverSettlement.confirmClose)}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

// ── Presentation primitives ───────────────────────────────────────────────────

/** One KPI: label, primary value, and a small caption that says what the value means. */
function Kpi({
  label,
  value,
  caption,
  tone,
}: {
  label: string;
  value: string;
  caption?: string;
  tone?: string;
}) {
  return (
    <div className="rounded-lg border bg-card p-3">
      <p className="text-[11px] uppercase leading-tight tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-0.5 break-words text-base font-semibold leading-tight tabular-nums ${tone ?? ''}`}>{value}</p>
      {caption ? <p className="mt-0.5 text-[10px] leading-snug text-muted-foreground">{caption}</p> : null}
    </div>
  );
}

function GoodsCard({
  label,
  value,
  caption,
  emphasis,
  onOpen,
  openLabel,
}: {
  label: string;
  value: number;
  caption?: string;
  emphasis?: boolean;
  onOpen?: () => void;
  openLabel?: string;
}) {
  const body = (
    <>
      <p className="text-[11px] uppercase leading-tight tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-0.5 text-lg font-semibold leading-tight tabular-nums">{value}</p>
      {caption ? <p className="mt-0.5 text-[10px] leading-snug text-muted-foreground">{caption}</p> : null}
    </>
  );
  const cls = `rounded-lg border p-3 ${emphasis ? 'border-primary/30 bg-primary/5' : 'bg-card'}`;

  if (!onOpen) {
    return <div className={cls}>{body}</div>;
  }
  return (
    <button type="button" onClick={onOpen} title={openLabel} className={`${cls} text-start hover:bg-primary/10`}>
      {body}
      <span className="mt-1 block text-[10px] font-medium text-primary underline decoration-dotted underline-offset-2">
        {openLabel}
      </span>
    </button>
  );
}

/** One line of the settlement / reconciliation summary. Values are never netted against each other. */
function SummaryRow({
  label,
  value,
  hint,
  strong,
  muted,
}: {
  label: string;
  value: string;
  hint?: string;
  strong?: boolean;
  muted?: boolean;
}) {
  return (
    <div className="flex items-start justify-between gap-3 py-1.5">
      <dt className="min-w-0">
        <span className={`text-xs ${strong ? 'font-semibold' : ''}`}>{label}</span>
        {hint ? <span className="block text-[10px] leading-snug text-muted-foreground">{hint}</span> : null}
      </dt>
      <dd
        className={`shrink-0 tabular-nums ${strong ? 'text-sm font-semibold' : 'text-sm'} ${
          muted ? 'text-muted-foreground' : ''
        }`}
      >
        {value}
      </dd>
    </div>
  );
}

function ContextRow({ label, value, mono }: { label: string; value: string | null; mono?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={`min-w-0 truncate text-xs font-medium ${mono ? 'font-mono' : ''}`}>
        {value ?? <span className="text-muted-foreground">—</span>}
      </dd>
    </div>
  );
}

/** A canonical binary (or unknown) settlement state. Reflects backend state; decides nothing. */
function StatusPill({
  label,
  ok,
  unknown,
  okText,
  badText,
  unknownText,
}: {
  label: string;
  ok: boolean;
  unknown?: boolean;
  okText: string;
  badText: string;
  unknownText?: string;
}) {
  const cls = unknown
    ? 'border-dashed text-muted-foreground'
    : ok
      ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-700 dark:text-emerald-400'
      : 'border-amber-500/30 bg-amber-500/5 text-amber-700 dark:text-amber-400';
  return (
    <div className={`rounded-md border px-3 py-2 ${cls}`}>
      <p className="text-[10px] uppercase tracking-wide opacity-70">{label}</p>
      <p className="mt-0.5 text-sm font-semibold">{unknown ? (unknownText ?? '—') : ok ? okText : badText}</p>
    </div>
  );
}

function Banner({ tone, icon, text }: { tone: 'ok' | 'bad'; icon: ReactNode; text: string }) {
  const cls =
    tone === 'ok'
      ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-700 dark:text-emerald-400'
      : 'border-destructive/30 bg-destructive/5 text-destructive';
  return (
    <div className={`flex items-center gap-2 rounded-lg border px-4 py-3 text-sm ${cls}`}>
      {icon}
      {text}
    </div>
  );
}

function SectionTitle({ text }: { text: string }) {
  return <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{text}</p>;
}

function GapNote({ text }: { text: string }) {
  return (
    <p className="mt-1.5 flex items-start gap-1 text-[11px] leading-snug text-muted-foreground">
      <Info className="mt-0.5 h-3 w-3 shrink-0" />
      <span>{text}</span>
    </p>
  );
}

function EmptyPanel({ icon, label }: { icon: ReactNode; label: string }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 py-10 text-muted-foreground">
      {icon}
      <p className="text-sm">{label}</p>
    </div>
  );
}

// ── Tables ────────────────────────────────────────────────────────────────────

function ReturnsTable({ rows }: { rows: DaySettlementReturnRow[] }) {
  const { t } = useTranslation('logistics');
  if (rows.length === 0) {
    return <EmptyPanel icon={<Package className="h-7 w-7 opacity-30" />} label={t(($) => $.driverSettlement.emptyReturns)} />;
  }
  return (
    <div className="overflow-x-auto rounded-lg border">
      <table className="w-full text-xs">
        <thead className="bg-muted/40 text-muted-foreground">
          <tr className="[&>th]:px-3 [&>th]:py-2 [&>th]:text-start">
            <th>{t(($) => $.driverSettlement.detail.returns.product)}</th>
            <th className="text-end">{t(($) => $.driverSettlement.detail.returns.qty)}</th>
            <th>{t(($) => $.driverSettlement.detail.returns.reason)}</th>
            <th>{t(($) => $.driverSettlement.detail.returns.condition)}</th>
            <th>{t(($) => $.driverSettlement.detail.returns.disposition)}</th>
            <th>{t(($) => $.driverSettlement.detail.returns.status)}</th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {rows.map((r, i) => (
            <tr key={i} className="[&>td]:px-3 [&>td]:py-2">
              <td className="font-medium">{r.product_name ?? '—'}</td>
              <td className="text-end tabular-nums">
                {r.returned_qty}
                {r.warehouse_confirmed_qty !== null ? ` / ${r.warehouse_confirmed_qty}` : ''}
                {r.discrepancy_qty !== null && r.discrepancy_qty !== undefined && r.discrepancy_qty !== 0 ? (
                  <span className="ms-1 text-orange-600 dark:text-orange-400">({r.discrepancy_qty})</span>
                ) : null}
              </td>
              <td className="max-w-[14rem] truncate text-muted-foreground">{r.reason ?? '—'}</td>
              <td className="capitalize text-muted-foreground">{r.custody_type ?? r.kind}</td>
              <td className="capitalize text-muted-foreground">{r.disposition ?? '—'}</td>
              <td>
                <Badge variant={r.confirmed ? 'secondary' : 'outline'} className="text-[10px]">
                  {r.confirmed ? t(($) => $.driverSettlement.confirmed) : t(($) => $.driverSettlement.pending)}
                </Badge>
                {r.driver_liable ? (
                  <p className="mt-0.5 text-[10px] text-orange-600 dark:text-orange-400">
                    {t(($) => $.driverSettlement.detail.returns.liable)}
                  </p>
                ) : null}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ProductReconciliationTable({
  rows,
  statusLabel,
}: {
  rows: DaySettlementProductRow[];
  statusLabel: Record<string, string>;
}) {
  const { t } = useTranslation('logistics');
  if (rows.length === 0) {
    return <EmptyPanel icon={<Package className="h-7 w-7 opacity-30" />} label={t(($) => $.driverSettlement.emptyProducts)} />;
  }
  const cell = (v: number | null) => (v === null ? '—' : v);
  return (
    <div className="overflow-x-auto rounded-lg border">
      <table className="w-full text-xs">
        <thead className="bg-muted/40 text-muted-foreground">
          <tr className="[&>th]:px-3 [&>th]:py-2 [&>th]:text-end [&>th:first-child]:text-start">
            <th>{t(($) => $.driverSettlement.recon.product)}</th>
            <th>{t(($) => $.driverSettlement.recon.loaded)}</th>
            <th>{t(($) => $.driverSettlement.recon.delivered)}</th>
            <th>{t(($) => $.driverSettlement.recon.expectedReturn)}</th>
            <th>{t(($) => $.driverSettlement.recon.goodReturn)}</th>
            <th>{t(($) => $.driverSettlement.recon.damaged)}</th>
            <th>{t(($) => $.driverSettlement.recon.shortage)}</th>
            <th>{t(($) => $.driverSettlement.recon.status)}</th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {rows.map((r) => (
            <tr key={`${r.product_id}-${r.source}`} className="tabular-nums [&>td]:px-3 [&>td]:py-2 [&>td]:text-end [&>td:first-child]:text-start">
              <td className="font-medium">{r.product_name}</td>
              <td>{cell(r.loaded)}</td>
              <td>{cell(r.delivered)}</td>
              <td>{cell(r.expected_return)}</td>
              <td>{cell(r.actual_good_return)}</td>
              <td className={r.damaged && r.damaged > 0 ? 'text-destructive' : ''}>{cell(r.damaged)}</td>
              <td className={r.shortage && r.shortage > 0 ? 'text-orange-600 dark:text-orange-400' : ''}>{cell(r.shortage)}</td>
              <td>
                <Badge variant="outline" className="text-[10px] capitalize">
                  {statusLabel[r.reconciliation_status] ?? r.reconciliation_status}
                </Badge>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Per-order money, all server-aggregated. Cash / Electronic / Prepaid are mutually exclusive
 * canonical buckets, so a row's three amounts never describe the same payment twice.
 */
function OrdersTable({
  rows,
  onOpen,
  money,
  na,
  emptyLabel,
}: {
  rows: DaySettlementOrderRow[];
  onOpen: (orderId: string) => void;
  money: (n: number) => string;
  na: string;
  emptyLabel: string;
}) {
  const { t } = useTranslation('logistics');
  if (rows.length === 0) {
    return <EmptyPanel icon={<Package className="h-7 w-7 opacity-30" />} label={emptyLabel} />;
  }
  const amount = (v: number | null | undefined) => (v === null || v === undefined ? na : money(v));
  return (
    <div className="overflow-x-auto rounded-lg border">
      <table className="w-full text-xs">
        <thead className="bg-muted/40 text-muted-foreground">
          <tr className="[&>th]:px-3 [&>th]:py-2 [&>th]:text-end [&>th:first-child]:text-start">
            <th>{t(($) => $.driverSettlement.detail.orders.order)}</th>
            <th>{t(($) => $.driverSettlement.detail.orders.value)}</th>
            <th>{t(($) => $.driverSettlement.detail.orders.delivered)}</th>
            <th>{t(($) => $.driverSettlement.detail.orders.cash)}</th>
            <th>{t(($) => $.driverSettlement.detail.orders.electronic)}</th>
            <th>{t(($) => $.driverSettlement.detail.orders.prepaid)}</th>
            <th>{t(($) => $.driverSettlement.detail.orders.outstanding)}</th>
            <th className="text-start">{t(($) => $.driverSettlement.detail.orders.method)}</th>
            <th className="text-start">{t(($) => $.driverSettlement.detail.orders.outcome)}</th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {rows.map((o) => (
            <tr
              key={o.order_id}
              className="tabular-nums transition-colors hover:bg-muted/40 [&>td]:px-3 [&>td]:py-2 [&>td]:text-end [&>td:first-child]:text-start"
            >
              <td className="min-w-0">
                <button
                  type="button"
                  onClick={() => onOpen(o.order_id)}
                  className="text-start hover:underline focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                >
                  <span className="block font-mono">{o.order_number ?? '—'}</span>
                  <span className="block truncate text-muted-foreground">{o.customer_name ?? '—'}</span>
                </button>
              </td>
              <td>{amount(o.order_value)}</td>
              <td>{amount(o.delivered_value)}</td>
              <td>{amount(o.cash_collected)}</td>
              <td>{amount(o.electronic_collected)}</td>
              <td>{amount(o.already_paid)}</td>
              <td
                className={
                  o.outstanding !== null && o.outstanding !== undefined && Math.abs(o.outstanding) >= 0.01
                    ? 'text-destructive'
                    : ''
                }
              >
                {amount(o.outstanding)}
              </td>
              <td className="text-start capitalize text-muted-foreground">{o.payment_method ?? '—'}</td>
              <td className="text-start">
                <Badge variant="outline" className="text-[10px] capitalize">
                  {o.status}
                </Badge>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
