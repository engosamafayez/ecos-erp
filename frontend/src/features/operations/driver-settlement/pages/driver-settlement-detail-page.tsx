import { useMemo, useState } from 'react';
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
  DaySettlementTransferRow,
} from '../types/driver-settlement';
import { ClosingStageBadge, DaySettlementStatusBadge } from '../components/day-settlement-status-badge';
import { DriverCashPositionCards } from '../components/driver-cash-position-cards';
import { DriverMovementReview } from '../components/driver-movement-review';

type OrderFilter = 'all' | 'delivered' | 'failed' | 'partial' | 'returned';

function Stat({ label, value, tone }: { label: string; value: React.ReactNode; tone?: string }) {
  return (
    <div className="rounded-md border p-3">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-0.5 text-sm font-semibold tabular-nums ${tone ?? ''}`}>{value}</p>
    </div>
  );
}

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
      <div className="p-4 space-y-4">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div className="flex flex-col items-center justify-center h-72 gap-3 text-muted-foreground">
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
    <div className="flex flex-col h-full overflow-y-auto">
      {/* Header */}
      <div className="sticky top-0 z-10 bg-background border-b px-4 py-3">
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <div className="flex items-center gap-3 min-w-0">
            <Button variant="ghost" size="icon" onClick={backToList} aria-label={t(($) => $.driverSettlement.back)}>
              <ArrowLeft className="h-5 w-5" />
            </Button>
            <div className="min-w-0">
              <h1 className="text-base font-semibold leading-tight truncate">
                {data.driver.name ?? t(($) => $.driverSettlement.unknownDriver)}
              </h1>
              <p className="text-xs text-muted-foreground">
                {data.driver.vehicle_plate ?? '—'} · {date}
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

      <div className="p-4 space-y-4">
        {/* Completed / difference / blockers banners */}
        {isSettled ? (
          <Banner tone="ok" icon={<CheckCircle2 className="h-4 w-4" />} text={t(($) => $.driverSettlement.completedBanner)} />
        ) : (
          <>
            {hasDifference && (
              <Banner tone="bad" icon={<AlertTriangle className="h-4 w-4" />} text={t(($) => $.driverSettlement.differenceBanner)} />
            )}
            {!allReconciled && blockers.length > 0 && (
              <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                <div className="flex items-center gap-2 font-medium">
                  <Info className="h-4 w-4" />
                  {t(($) => $.driverSettlement.blockersTitle)}
                </div>
                <ul className="mt-1 list-disc ps-8 text-xs space-y-0.5">
                  {blockers.map((b) => (
                    <li key={b}>{blockerLabel[b] ?? b}</li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}

        {/* Overview KPIs */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <Stat label={t(($) => $.driverSettlement.columns.orders)} value={data.overview.orders} />
          <Stat label={t(($) => $.driverSettlement.columns.delivered)} value={data.overview.delivered} />
          <Stat label={t(($) => $.driverSettlement.orderStatus.partial)} value={data.overview.partial} />
          <Stat label={t(($) => $.driverSettlement.orderStatus.failed)} value={data.overview.failed} />
          <Stat label={t(($) => $.driverSettlement.columns.deliveryPct)} value={`${data.overview.delivery_pct}%`} />
          <Stat label={t(($) => $.driverSettlement.overview.trips)} value={data.overview.trips} />
        </div>

        {/* Canonical settlement strip — cash reconciliation figures (backend-derived) */}
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label={t(($) => $.driverSettlement.financial.cashExpected)} value={money(data.financial.cash_expected)} />
          <Stat label={t(($) => $.driverSettlement.financial.approvedTransfers)} value={money(data.financial.approved_transfers)} />
          <Stat
            label={t(($) => $.driverSettlement.financial.actualCash)}
            value={data.financial.actual_cash === null ? '—' : money(data.financial.actual_cash)}
          />
          <Stat
            label={t(($) => $.driverSettlement.financial.difference)}
            tone={
              data.financial.difference === null || Math.abs(data.financial.difference) < 0.01
                ? ''
                : data.financial.difference < 0
                  ? 'text-destructive'
                  : 'text-emerald-600'
            }
            value={data.financial.difference === null ? '—' : money(data.financial.difference)}
          />
        </div>

        {/* Driver cash position (§1–§9, §12–§14) — the ONE active custody's cash summary, prominent
            before closing. Expenses / Cash In / Net Cash are canonical (approved movements). */}
        <DriverCashPositionCards
          collections={data.collections}
          expenses={data.financial.expenses}
          cashIn={data.financial.cash_in}
          netCash={data.financial.net_cash}
        />

        {/* Trip Movements review (§7–§10) — Operations approves/rejects driver cash movements. */}
        <DriverMovementReview
          movements={data.movements}
          assignmentId={numericAssignmentId}
          date={date}
          canReview={canWrite}
        />

        {/* Sales & Collections (§6, §7) — every figure canonical; expected collection unavailable */}
        <section>
          <SectionTitle text={t(($) => $.driverSettlement.salesTitle)} />
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <Stat label={t(($) => $.driverSettlement.sales.deliveredSales)} value={money(c.delivered_sales)} />
            <Stat label={t(($) => $.driverSettlement.sales.cash)} value={money(c.cash)} />
            <Stat label={t(($) => $.driverSettlement.sales.bankTransfer)} value={money(c.bank_transfer)} />
            <Stat label={t(($) => $.driverSettlement.sales.card)} value={money(c.card)} />
            <Stat label={t(($) => $.driverSettlement.sales.alreadyPaid)} value={money(c.already_paid)} />
            <Stat label={t(($) => $.driverSettlement.sales.actualCollected)} value={money(c.actual_collected)} />
            <Stat
              label={t(($) => $.driverSettlement.sales.expectedCollection)}
              tone="text-muted-foreground text-xs font-normal"
              value={
                c.expected_collection_available && c.expected_collection !== null ? (
                  money(c.expected_collection)
                ) : (
                  <span className="inline-flex items-center gap-1">
                    {t(($) => $.driverSettlement.notAvailable)}
                  </span>
                )
              }
            />
          </div>
          {!c.expected_collection_available && (
            <p className="mt-1.5 text-[11px] text-muted-foreground flex items-center gap-1">
              <Info className="h-3 w-3" />
              {t(($) => $.driverSettlement.expectedCollectionNote)}
            </p>
          )}
        </section>

        {/* Vehicle Custody summary (§8) */}
        <section>
          <SectionTitle text={t(($) => $.driverSettlement.custodyTitle)} />
          {custody.reconciliation_available ? (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
              <Stat label={t(($) => $.driverSettlement.custody.loaded)} value={custody.total_loaded} />
              <Stat label={t(($) => $.driverSettlement.custody.delivered)} value={custody.total_delivered} />
              <Stat label={t(($) => $.driverSettlement.custody.expectedReturn)} value={custody.expected_return} />
              <Stat label={t(($) => $.driverSettlement.custody.actualReturn)} value={custody.actual_return} />
              <Stat label={t(($) => $.driverSettlement.custody.accepted)} value={custody.accepted} />
              <Stat
                label={t(($) => $.driverSettlement.custody.damaged)}
                tone={custody.damaged > 0 ? 'text-destructive' : ''}
                value={custody.damaged}
              />
              <Stat
                label={t(($) => $.driverSettlement.custody.shortage)}
                tone={custody.shortage > 0 ? 'text-orange-600 dark:text-orange-400' : ''}
                value={custody.shortage}
              />
              <Stat label={t(($) => $.driverSettlement.custody.remaining)} value={custody.remaining_on_hand} />
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
              <Stat label={t(($) => $.driverSettlement.custody.loaded)} value={custody.total_loaded} />
              <Stat label={t(($) => $.driverSettlement.custody.delivered)} value={custody.total_delivered} />
              <Stat label={t(($) => $.driverSettlement.custody.remaining)} value={custody.remaining_on_hand} />
              <div className="rounded-md border border-dashed p-3 col-span-2 sm:col-span-1 flex items-center text-[11px] text-muted-foreground">
                {t(($) => $.driverSettlement.custody.notReconciled)}
              </div>
            </div>
          )}
        </section>

        {/* Tabs */}
        <Tabs defaultValue="overview" className="w-full">
          <TabsList className="flex-wrap h-auto">
            <TabsTrigger value="overview">{t(($) => $.driverSettlement.tabs.overview)}</TabsTrigger>
            <TabsTrigger value="orders">{t(($) => $.driverSettlement.tabs.orders)}</TabsTrigger>
            <TabsTrigger value="transfers">{t(($) => $.driverSettlement.tabs.transfers)}</TabsTrigger>
            <TabsTrigger value="reconciliation">{t(($) => $.driverSettlement.tabs.reconciliation)}</TabsTrigger>
            <TabsTrigger value="returns">{t(($) => $.driverSettlement.tabs.returns)}</TabsTrigger>
            <TabsTrigger value="timeline">{t(($) => $.driverSettlement.tabs.timeline)}</TabsTrigger>
            <TabsTrigger value="settlement">{t(($) => $.driverSettlement.tabs.settlement)}</TabsTrigger>
          </TabsList>

          {/* Overview */}
          <TabsContent value="overview" className="pt-3">
            <div className="rounded-lg border divide-y text-sm">
              {data.trips.map((tr) => (
                <div key={tr.id} className="flex items-center justify-between px-4 py-2.5">
                  <span className="font-mono text-xs">{tr.trip_number ?? tr.id.slice(0, 8)}</span>
                  <span className="text-muted-foreground text-xs">
                    {tr.stops_total - tr.stops_outstanding}/{tr.stops_total}
                  </span>
                  <span className="tabular-nums">{money(tr.cash_expected)}</span>
                  <Badge variant="secondary" className="text-[10px] capitalize">
                    {tr.settlement_status ?? 'draft'}
                  </Badge>
                </div>
              ))}
            </div>
          </TabsContent>

          {/* Orders */}
          <TabsContent value="orders" className="pt-3 space-y-3">
            <div className="flex items-center gap-1.5 flex-wrap">
              {(['all', 'delivered', 'partial', 'failed', 'returned'] as OrderFilter[]).map((f) => (
                <button
                  key={f}
                  onClick={() => setOrderFilter(f)}
                  className={`px-2.5 py-1 rounded-md text-xs font-medium transition-colors ${
                    orderFilter === f
                      ? 'bg-primary text-primary-foreground'
                      : 'bg-muted text-muted-foreground hover:text-foreground'
                  }`}
                >
                  {f === 'all' ? t(($) => $.driverSettlement.filterAll) : t(($) => $.driverSettlement.orderStatus[f])}
                </button>
              ))}
            </div>
            <OrdersTable
              rows={data.orders.filter((o) => orderFilter === 'all' || o.status === orderFilter)}
              onOpen={(id) => setDetailOrderId(id)}
              money={money}
              emptyLabel={t(($) => $.driverSettlement.emptyOrders)}
            />
          </TabsContent>

          {/* Transfers */}
          <TabsContent value="transfers" className="pt-3">
            {data.transfers.length === 0 ? (
              <EmptyPanel icon={<Truck className="h-7 w-7 opacity-30" />} label={t(($) => $.driverSettlement.emptyTransfers)} />
            ) : (
              <div className="rounded-lg border divide-y text-sm">
                {data.transfers.map((tr, i) => (
                  <div key={`${tr.order_id ?? 'x'}-${tr.payment_type}-${i}`} className="flex items-center justify-between gap-2 px-4 py-2.5">
                    <div className="min-w-0">
                      <p className="font-mono text-xs">{tr.order_number ?? '—'}</p>
                      <p className="text-xs text-muted-foreground truncate">{tr.customer_name ?? '—'}</p>
                    </div>
                    <Badge variant="secondary" className="text-[10px]">{tr.payment_label}</Badge>
                    <span className="tabular-nums">{money(tr.amount)}</span>
                    <Badge variant="outline" className="text-[10px] capitalize">
                      {tr.proof ? tr.proof.state : t(($) => $.driverSettlement.noProof)}
                    </Badge>
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
                  </div>
                ))}
              </div>
            )}
          </TabsContent>

          {/* Reconciliation — per-product (§9), damage (§11), shortage (§12), goods */}
          <TabsContent value="reconciliation" className="pt-3 space-y-4">
            <div>
              <SectionTitle text={t(($) => $.driverSettlement.productReconTitle)} />
              <ProductReconciliationTable rows={data.product_reconciliation} statusLabel={reconStatusLabel} />
            </div>

            {data.damage.items.length > 0 && (
              <div>
                <SectionTitle text={t(($) => $.driverSettlement.damageTitle)} />
                <div className="rounded-lg border divide-y text-sm">
                  {data.damage.items.map((d, i) => (
                    <div key={i} className="flex items-center justify-between gap-2 px-4 py-2.5">
                      <span className="inline-flex items-center gap-1.5 truncate">
                        <PackageX className="h-3.5 w-3.5 text-destructive" />
                        {d.product_name}
                      </span>
                      <span className="text-xs text-muted-foreground truncate">{d.reason ?? '—'}</span>
                      <span className="tabular-nums text-destructive">{d.quantity}</span>
                    </div>
                  ))}
                </div>
                <GapNote text={t(($) => $.driverSettlement.damageGapNote)} />
              </div>
            )}

            {data.shortage_review.items.length > 0 && (
              <div>
                <SectionTitle text={t(($) => $.driverSettlement.shortageTitle)} />
                <div className="rounded-lg border divide-y text-sm">
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

            <div>
              <SectionTitle
                text={`${t(($) => $.driverSettlement.goodsTitle)} · ${custody.remaining_on_hand} ${t(($) => $.driverSettlement.units)}`}
              />
              {data.goods_remaining.length === 0 ? (
                <EmptyPanel icon={<Package className="h-7 w-7 opacity-30" />} label={t(($) => $.driverSettlement.emptyGoods)} />
              ) : (
                <div className="rounded-lg border divide-y text-sm">
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

          {/* Returns */}
          <TabsContent value="returns" className="pt-3">
            {data.returns.length === 0 ? (
              <EmptyPanel icon={<Package className="h-7 w-7 opacity-30" />} label={t(($) => $.driverSettlement.emptyReturns)} />
            ) : (
              <div className="rounded-lg border divide-y text-sm">
                {data.returns.map((r, i) => (
                  <div key={i} className="flex items-center justify-between gap-2 px-4 py-2.5">
                    <span className="truncate">{r.product_name ?? '—'}</span>
                    <span className="tabular-nums text-xs">
                      {r.returned_qty}
                      {r.warehouse_confirmed_qty !== null ? ` / ${r.warehouse_confirmed_qty}` : ''}
                    </span>
                    <Badge variant={r.confirmed ? 'secondary' : 'outline'} className="text-[10px]">
                      {r.confirmed ? t(($) => $.driverSettlement.confirmed) : t(($) => $.driverSettlement.pending)}
                    </Badge>
                  </div>
                ))}
              </div>
            )}
          </TabsContent>

          {/* Timeline (§16) */}
          <TabsContent value="timeline" className="pt-3">
            {data.timeline.length === 0 ? (
              <EmptyPanel icon={<Clock className="h-7 w-7 opacity-30" />} label={t(($) => $.driverSettlement.emptyTimeline)} />
            ) : (
              <ol className="relative border-s ps-5 space-y-3 text-sm">
                {data.timeline.map((e, i) => (
                  <li key={i} className="relative">
                    <span className="absolute -start-[23px] top-1 h-2.5 w-2.5 rounded-full bg-primary/60" />
                    <p className="font-medium">{timelineLabel[e.code] ?? e.code}</p>
                    <p className="text-[11px] text-muted-foreground tabular-nums">
                      {new Date(e.at).toLocaleString()}
                    </p>
                  </li>
                ))}
              </ol>
            )}
          </TabsContent>

          {/* Settlement — reuse the canonical per-trip TripSettlementTab */}
          <TabsContent value="settlement" className="pt-3 space-y-4">
            {data.trips.map((tr) => (
              <div key={tr.id} className="rounded-lg border p-3">
                <p className="font-mono text-xs text-muted-foreground mb-2">{tr.trip_number ?? tr.id.slice(0, 8)}</p>
                <TripSettlementTab tripId={tr.id} />
              </div>
            ))}
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
          <div className="grid grid-cols-2 gap-3 text-sm">
            <Stat label={t(($) => $.driverSettlement.columns.orders)} value={data.overview.orders} />
            <Stat label={t(($) => $.driverSettlement.columns.delivered)} value={data.overview.delivered} />
            <Stat label={t(($) => $.driverSettlement.columns.returns)} value={data.overview.returns} />
            <Stat label={t(($) => $.driverSettlement.financial.cashExpected)} value={money(data.financial.cash_expected)} />
            <Stat label={t(($) => $.driverSettlement.financial.approvedTransfers)} value={money(data.financial.approved_transfers)} />
            <Stat
              label={t(($) => $.driverSettlement.financial.difference)}
              value={data.financial.difference === null ? '—' : money(data.financial.difference)}
            />
          </div>
          {hasDifference && (
            <p className="text-xs text-destructive flex items-center gap-1.5">
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

function Banner({ tone, icon, text }: { tone: 'ok' | 'bad'; icon: React.ReactNode; text: string }) {
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
  return <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-2">{text}</p>;
}

function GapNote({ text }: { text: string }) {
  return (
    <p className="mt-1.5 text-[11px] text-muted-foreground flex items-center gap-1">
      <Info className="h-3 w-3" />
      {text}
    </p>
  );
}

function EmptyPanel({ icon, label }: { icon: React.ReactNode; label: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-10 gap-2 text-muted-foreground">
      {icon}
      <p className="text-sm">{label}</p>
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
            <tr key={`${r.product_id}-${r.source}`} className="[&>td]:px-3 [&>td]:py-2 [&>td]:text-end [&>td:first-child]:text-start tabular-nums">
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

function OrdersTable({
  rows,
  onOpen,
  money,
  emptyLabel,
}: {
  rows: DaySettlementOrderRow[];
  onOpen: (orderId: string) => void;
  money: (n: number) => string;
  emptyLabel: string;
}) {
  if (rows.length === 0) {
    return <EmptyPanel icon={<Package className="h-7 w-7 opacity-30" />} label={emptyLabel} />;
  }
  return (
    <div className="rounded-lg border divide-y text-sm">
      {rows.map((o) => (
        <button
          key={o.order_id}
          onClick={() => onOpen(o.order_id)}
          className="w-full flex items-center justify-between gap-2 px-4 py-2.5 text-start hover:bg-muted/40 transition-colors"
        >
          <div className="min-w-0">
            <p className="font-mono text-xs">{o.order_number ?? '—'}</p>
            <p className="text-xs text-muted-foreground truncate">{o.customer_name ?? '—'}</p>
          </div>
          <span className="tabular-nums">{money(o.order_value ?? 0)}</span>
          <span className="text-xs text-muted-foreground capitalize">{o.payment_method ?? '—'}</span>
          <Badge variant="outline" className="text-[10px] capitalize">
            {o.status}
          </Badge>
        </button>
      ))}
    </div>
  );
}
