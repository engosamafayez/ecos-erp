import { useTranslation } from 'react-i18next';
import { AlertCircle, AlertTriangle, ArrowUpRight, CheckCircle, Info, Loader2, Package, ShoppingCart, TrendingDown, TrendingUp } from 'lucide-react';

import { useProductDemandAnalysis } from '../hooks/use-purchase-materials';
import type {
  BusinessImpact,
  CoverageIntelligence,
  DemandAnalysisData,
  DemandIntelligence,
  DemandTimelineEvent,
  InventoryHealth,
  ProcurementPanelRecommendation,
} from '../types/purchase-material';

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmt(n: number | null | undefined, decimals = 2): string {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function fmtDate(d: string | null | undefined): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <p className="text-[10px] uppercase tracking-wider font-semibold text-muted-foreground mb-2 mt-1">
      {children}
    </p>
  );
}

function StatRow({ label, value, highlight = false }: { label: string; value: React.ReactNode; highlight?: boolean }) {
  return (
    <div className="flex items-center justify-between py-1 border-b border-border/40 last:border-0">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className={`text-xs font-medium ${highlight ? 'text-foreground' : 'text-muted-foreground'}`}>{value}</span>
    </div>
  );
}

function StatBox({ label, value, highlight = false }: { label: string; value: React.ReactNode; highlight?: boolean }) {
  return (
    <div className={`rounded-md border px-2 py-1.5 text-center ${highlight ? 'bg-background border-primary/30' : 'bg-background'}`}>
      <p className="text-[10px] text-muted-foreground">{label}</p>
      <p className={`font-semibold text-sm tabular-nums ${highlight ? 'text-foreground' : ''}`}>{value}</p>
    </div>
  );
}

// ── Risk / Trend indicators ────────────────────────────────────────────────────

function RiskBadge({ risk }: { risk: string }) {
  const { t } = useTranslation('purchase-materials');
  const config: Record<string, string> = {
    critical: 'bg-red-100 text-red-700 border-red-200',
    high:     'bg-orange-100 text-orange-700 border-orange-200',
    medium:   'bg-amber-100 text-amber-700 border-amber-200',
    low:      'bg-emerald-100 text-emerald-700 border-emerald-200',
    unknown:  'bg-slate-100 text-slate-600 border-slate-200',
  };
  const tAny = t as (key: string) => string;
  return (
    <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium border uppercase tracking-wide ${config[risk] ?? config['unknown']}`}>
      {tAny(`wizard.step2.demandPanel.risk.${risk}`)}
    </span>
  );
}

function TrendIndicator({ trend }: { trend: string }) {
  const { t } = useTranslation('purchase-materials');
  const tAny = t as (key: string) => string;
  const Icon = trend === 'higher' ? TrendingUp : trend === 'lower' ? TrendingDown : null;
  const color = trend === 'higher' ? 'text-amber-600' : trend === 'lower' ? 'text-blue-600' : '';
  return (
    <span className="flex items-center gap-1 text-[10px] text-muted-foreground">
      {Icon ? <Icon className={`size-3.5 ${color}`} /> : <span className="size-1.5 rounded-full bg-emerald-500" />}
      <span>{tAny(`wizard.step2.demandPanel.trend.${trend}`)}</span>
    </span>
  );
}

function RecommendationCard({ rec }: { rec: ProcurementPanelRecommendation }) {
  const Icon = { error: AlertCircle, warning: AlertTriangle, info: Info }[rec.severity] ?? Info;
  const styles = {
    error:   'bg-red-50 border-red-200 text-red-700',
    warning: 'bg-amber-50 border-amber-200 text-amber-700',
    info:    'bg-blue-50 border-blue-200 text-blue-700',
  }[rec.severity] ?? 'bg-blue-50 border-blue-200 text-blue-700';
  return (
    <div className={`flex gap-2 rounded-md border px-2.5 py-2 text-xs ${styles}`}>
      <Icon className="size-3.5 mt-0.5 shrink-0" />
      <p className="leading-tight">{rec.message}</p>
    </div>
  );
}

// ── Section: Business Impact ───────────────────────────────────────────────────
// Real, query-backed fields only. `open_orders` / `backordered_qty` were removed
// here — DemandAnalysisService hardcodes both to null pending dedicated tracking
// tables, so they rendered as a permanent "—" no matter the product.

function BusinessImpactSection({ bi }: { bi: BusinessImpact }) {
  const { t } = useTranslation('purchase-materials');
  return (
    <section>
      <SectionLabel>{t($ => $.wizard.step2.demandPanel.sections.businessImpact)}</SectionLabel>
      <div className="grid grid-cols-3 gap-1.5 mb-2">
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.salesLast7d)} value={fmt(bi.sales_last_7d, 0)} />
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.salesLast30d)} value={fmt(bi.sales_last_30d, 0)} />
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.revenueLast30d)} value={bi.revenue_last_30d != null ? fmt(bi.revenue_last_30d, 0) : '—'} />
      </div>
      <div className="rounded-md border bg-background divide-y">
        <StatRow label={t($ => $.wizard.step2.demandPanel.stats.carryingWarehouses)} value={bi.warehouses_carrying} highlight />
        <StatRow label={t($ => $.wizard.step2.demandPanel.stats.totalInventoryValue)} value={bi.total_inventory_value > 0 ? fmt(bi.total_inventory_value, 0) : '—'} highlight />
        <StatRow label={t($ => $.wizard.step2.demandPanel.stats.reservedQty)} value={fmt(bi.reserved_qty, 0)} />
      </div>
    </section>
  );
}

// ── Section: Inventory Health ──────────────────────────────────────────────────
// In Transit / Damaged / Expired / Near Expiry / Quarantine were removed — none of
// them are backed by a real column or query yet (DemandAnalysisService hardcodes
// them to 0/null with a "requires ... tracking" comment), so they never showed
// anything but a permanent "—". Incoming is real and is promoted into the stat grid.

function InventoryHealthSection({ health }: { health: InventoryHealth }) {
  const { t } = useTranslation('purchase-materials');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;
  const total = health.on_hand;
  const healthPct = total > 0 ? (health.available / total) * 100 : 0;
  const healthColor = healthPct > 60 ? 'bg-emerald-500' : healthPct > 30 ? 'bg-amber-500' : 'bg-red-500';

  return (
    <section>
      <SectionLabel>{t($ => $.wizard.step2.demandPanel.sections.inventoryHealth)}</SectionLabel>
      <div className="grid grid-cols-2 gap-1.5 mb-2">
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.onHand)} value={fmt(health.on_hand, 0)} />
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.reserved)} value={fmt(health.reserved, 0)} />
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.available)} value={fmt(health.available, 0)} highlight />
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.incoming)} value={health.incoming > 0 ? fmt(health.incoming, 0) : '—'} highlight={health.incoming > 0} />
      </div>
      {total > 0 && (
        <div className="flex items-center gap-2">
          <div className="flex-1 h-1.5 rounded-full bg-muted overflow-hidden">
            <div className={`h-full rounded-full ${healthColor}`} style={{ width: `${Math.min(100, healthPct)}%` }} />
          </div>
          <span className="text-[10px] text-muted-foreground shrink-0">
            {tAny('wizard.step2.demandPanel.stats.availablePct', { pct: Math.round(healthPct) })}
          </span>
        </div>
      )}
    </section>
  );
}

// ── Section: Demand Intelligence ──────────────────────────────────────────────
// Every field here is a real aggregate over StockMovement rows — nothing removed.

function DemandIntelligenceSection({ demand }: { demand: DemandIntelligence }) {
  const { t } = useTranslation('purchase-materials');
  return (
    <section>
      <div className="flex items-center justify-between mb-2">
        <SectionLabel>{t($ => $.wizard.step2.demandPanel.sections.demandIntelligence)}</SectionLabel>
        <TrendIndicator trend={demand.trend} />
      </div>
      <div className="grid grid-cols-3 gap-1.5 mb-2">
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.daily)} value={fmt(demand.daily_avg, 2)} />
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.weekly)} value={fmt(demand.weekly_avg, 1)} />
        <StatBox label={t($ => $.wizard.step2.demandPanel.stats.monthly)} value={fmt(demand.monthly_avg, 0)} />
      </div>
      <div className="rounded-md border bg-background divide-y">
        <StatRow label={t($ => $.wizard.step2.demandPanel.stats.rolling90dAvg)} value={fmt(demand.rolling_90d_avg, 2)} />
        <StatRow label={t($ => $.wizard.step2.demandPanel.stats.peakConsumption)} value={fmt(demand.peak_consumption, 2)} />
        <StatRow label={t($ => $.wizard.step2.demandPanel.stats.volatility)} value={demand.volatility != null ? fmt(demand.volatility, 2) : '—'} />
      </div>
    </section>
  );
}

// ── Section: Coverage Intelligence ────────────────────────────────────────────
// Safety Stock / Min Stock / Max Stock / Reorder Point were removed — hardcoded
// null pending a stock-level config table (same DemandAnalysisService comment as
// above). Current Coverage / risk / both dates are real, derived from real
// on-hand and consumption figures.

function CoverageIntelligenceSection({ coverage }: { coverage: CoverageIntelligence }) {
  const { t } = useTranslation('purchase-materials');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;
  return (
    <section>
      <SectionLabel>{t($ => $.wizard.step2.demandPanel.sections.coverageIntelligence)}</SectionLabel>
      <div className="flex items-center justify-between rounded-md border bg-background px-3 py-2 mb-2">
        <div>
          <p className="text-[10px] text-muted-foreground">{t($ => $.wizard.step2.demandPanel.stats.currentCoverage)}</p>
          <p className="font-semibold text-base tabular-nums">
            {coverage.current_coverage_days != null
              ? tAny('wizard.step2.demandPanel.stats.days', { count: Math.round(coverage.current_coverage_days * 10) / 10 })
              : '—'}
          </p>
        </div>
        <RiskBadge risk={coverage.risk} />
      </div>
      <div className="rounded-md border bg-background divide-y">
        <StatRow label={t($ => $.wizard.step2.demandPanel.stats.stockoutDate)} value={fmtDate(coverage.stockout_date)} highlight={coverage.risk === 'critical' || coverage.risk === 'high'} />
        <StatRow label={t($ => $.wizard.step2.demandPanel.stats.suggestedPurchaseDate)} value={fmtDate(coverage.suggested_purchase_date)} />
      </div>
    </section>
  );
}

// ── Section: Recommendations ──────────────────────────────────────────────────

function RecommendationsSection({ recs }: { recs: ProcurementPanelRecommendation[] }) {
  const { t } = useTranslation('purchase-materials');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;
  if (recs.length === 0) {
    return (
      <section>
        <SectionLabel>{t($ => $.wizard.step2.demandPanel.sections.recommendations)}</SectionLabel>
        <div className="flex items-center gap-2 text-xs text-emerald-700 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2">
          <CheckCircle className="size-3.5 shrink-0" />
          {t($ => $.wizard.step2.demandPanel.noIssues)}
        </div>
      </section>
    );
  }
  return (
    <section>
      <SectionLabel>{tAny('wizard.step2.demandPanel.recommendationsCount', { count: recs.length })}</SectionLabel>
      <div className="flex flex-col gap-1.5">
        {recs.map((rec, i) => (
          <RecommendationCard key={i} rec={rec} />
        ))}
      </div>
    </section>
  );
}

// ── Section: Timeline ─────────────────────────────────────────────────────────

function TimelineSection({ events }: { events: DemandTimelineEvent[] }) {
  const { t } = useTranslation('purchase-materials');
  if (events.length === 0) return null;

  return (
    <section>
      <SectionLabel>{t($ => $.wizard.step2.demandPanel.sections.timeline)}</SectionLabel>
      <div className="relative flex flex-col gap-0">
        <div className="absolute left-[13px] top-3 bottom-3 w-px bg-border" />
        {events.slice(0, 10).map((ev, i) => {
          const isPurchase = ev.type === 'purchase_event';
          const Icon = isPurchase ? ShoppingCart : Package;
          const color = isPurchase ? 'text-blue-600' : 'text-slate-500';
          return (
            <div key={i} className="flex gap-3 relative">
              <div className={`size-7 rounded-full border bg-background flex items-center justify-center shrink-0 z-10 ${color}`}>
                <Icon className="size-3" />
              </div>
              <div className="flex-1 pb-2">
                <div className="flex items-center justify-between">
                  <p className="text-xs font-medium">{ev.description}</p>
                  {ev.quantity !== 0 && (
                    <span className={`text-xs font-mono tabular-nums ${ev.quantity > 0 ? 'text-emerald-600' : 'text-red-600'}`}>
                      {ev.quantity > 0 ? '+' : ''}{fmt(Math.abs(ev.quantity), 2).replace(/\.?0+$/, '')}
                    </span>
                  )}
                </div>
                <p className="text-[10px] text-muted-foreground">
                  {fmtDate(ev.date)}
                  {ev.supplier && ` · ${ev.supplier}`}
                </p>
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}

// ── Quick Actions (sticky footer) ─────────────────────────────────────────────

function QuickActions({ productId }: { productId: string }) {
  const { t } = useTranslation('purchase-materials');
  const links = [
    { label: t($ => $.wizard.step2.demandPanel.quickActions.viewProduct), path: `/products?highlight=${productId}` },
    { label: t($ => $.wizard.step2.demandPanel.quickActions.stockLedger), path: `/stock-ledger?product=${productId}` },
    { label: t($ => $.wizard.step2.demandPanel.quickActions.purchaseHistory), path: `/purchasing/purchase-materials?product=${productId}` },
  ];
  return (
    <div className="sticky bottom-0 bg-background border-t pt-2 -mx-0.5 px-0.5">
      <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1.5">{t($ => $.wizard.step2.demandPanel.quickActions.title)}</p>
      <div className="flex flex-wrap gap-1.5">
        {links.map(({ label, path }) => (
          <a
            key={label}
            href={path}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 px-2 py-1 text-[10px] font-medium border rounded hover:bg-muted transition-colors text-muted-foreground hover:text-foreground"
          >
            {label}
            <ArrowUpRight className="size-2.5" />
          </a>
        ))}
      </div>
    </div>
  );
}

// ── Main Panel ─────────────────────────────────────────────────────────────────

type Props = {
  productId: string | null;
  warehouseId?: string;
  requestedQty?: number;
  requiredDate?: string;
  showQuickActions?: boolean;
};

export function EnterpriseDemandPanel({ productId, warehouseId, showQuickActions = false }: Props) {
  const { t } = useTranslation('purchase-materials');
  const { data, isLoading, isError } = useProductDemandAnalysis(
    productId,
    { warehouse_id: warehouseId },
  );

  if (!productId) {
    return (
      <div className="flex flex-col items-center justify-center h-full gap-2 text-sm text-muted-foreground text-center px-4">
        <Info className="size-8 text-muted-foreground/40" />
        <p>{t($ => $.wizard.step2.demandPanel.selectPrompt)}</p>
      </div>
    );
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-32 gap-2 text-sm text-muted-foreground">
        <Loader2 className="size-4 animate-spin" /> {t($ => $.wizard.step2.demandPanel.loading)}
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div className="flex items-center justify-center h-32 gap-2 text-sm text-muted-foreground">
        <AlertCircle className="size-4 text-destructive" /> {t($ => $.wizard.step2.demandPanel.failedToLoad)}
      </div>
    );
  }

  return (
    <FullDemandPanel data={data} productId={productId} showQuickActions={showQuickActions} />
  );
}

function FullDemandPanel({ data, productId, showQuickActions }: {
  data: DemandAnalysisData;
  productId: string;
  showQuickActions: boolean;
}) {
  return (
    <div className="flex flex-col gap-4 text-xs">
      <BusinessImpactSection bi={data.business_impact} />
      <InventoryHealthSection health={data.inventory_health} />
      <DemandIntelligenceSection demand={data.demand_intelligence} />
      <CoverageIntelligenceSection coverage={data.coverage_intelligence} />
      <RecommendationsSection recs={data.recommendations} />
      {data.timeline.length > 0 && <TimelineSection events={data.timeline} />}
      {showQuickActions && <QuickActions productId={productId} />}
    </div>
  );
}

// Legacy wrapper for places that still pass the old procurement panel data
export function LegacyDemandPanel({ productId, warehouseId, requestedQty, requiredDate }: {
  productId: string | null;
  warehouseId?: string;
  requestedQty?: number;
  requiredDate?: string;
}) {
  return (
    <EnterpriseDemandPanel
      productId={productId}
      warehouseId={warehouseId}
      requestedQty={requestedQty}
      requiredDate={requiredDate}
    />
  );
}
