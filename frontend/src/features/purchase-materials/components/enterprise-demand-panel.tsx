import { AlertCircle, AlertTriangle, ArrowUpRight, BarChart2, CheckCircle, Info, Loader2, Package, ShoppingCart, TrendingDown, TrendingUp } from 'lucide-react';

import { useProductDemandAnalysis } from '../hooks/use-purchase-materials';
import type {
  BusinessImpact,
  CoverageIntelligence,
  DemandAnalysisData,
  DemandIntelligence,
  DemandTimelineEvent,
  InventoryHealth,
  ProcurementIntelligence,
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

// ── Risk / Trend indicators ────────────────────────────────────────────────────

function RiskBadge({ risk }: { risk: string }) {
  const config: Record<string, string> = {
    critical: 'bg-red-100 text-red-700 border-red-200',
    high:     'bg-orange-100 text-orange-700 border-orange-200',
    medium:   'bg-amber-100 text-amber-700 border-amber-200',
    low:      'bg-emerald-100 text-emerald-700 border-emerald-200',
    unknown:  'bg-slate-100 text-slate-600 border-slate-200',
  };
  return (
    <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium border uppercase tracking-wide ${config[risk] ?? config['unknown']}`}>
      {risk}
    </span>
  );
}

function TrendIcon({ trend }: { trend: string }) {
  if (trend === 'higher') return <TrendingUp className="size-3.5 text-amber-600" />;
  if (trend === 'lower')  return <TrendingDown className="size-3.5 text-blue-600" />;
  return <span className="size-3.5 inline-flex items-center justify-center"><span className="size-1.5 rounded-full bg-emerald-500" /></span>;
}

function PriceTrendBadge({ trend }: { trend: string | null }) {
  if (!trend) return <span className="text-xs text-muted-foreground">—</span>;
  const map = {
    rising:  { cls: 'text-red-600', icon: TrendingUp, label: 'ارتفاع' },
    falling: { cls: 'text-emerald-600', icon: TrendingDown, label: 'انخفاض' },
    stable:  { cls: 'text-blue-600', icon: BarChart2, label: 'مستقر' },
  };
  const cfg = map[trend as keyof typeof map];
  if (!cfg) return null;
  const Icon = cfg.icon;
  return (
    <span className={`inline-flex items-center gap-0.5 text-xs font-medium ${cfg.cls}`}>
      <Icon className="size-3" />{cfg.label}
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

function BusinessImpactSection({ bi }: { bi: BusinessImpact }) {
  return (
    <section>
      <SectionLabel>الأثر على الأعمال</SectionLabel>
      <div className="grid grid-cols-3 gap-1.5 mb-2">
        {[
          { label: 'مبيعات 7أيام', value: fmt(bi.sales_last_7d, 0) },
          { label: 'مبيعات 30يوم', value: fmt(bi.sales_last_30d, 0) },
          { label: 'إيرادات 30يوم', value: bi.revenue_last_30d != null ? fmt(bi.revenue_last_30d, 0) : '—' },
        ].map(({ label, value }) => (
          <div key={label} className="rounded-md border bg-background px-2 py-1.5 text-center">
            <p className="text-[10px] text-muted-foreground">{label}</p>
            <p className="font-semibold text-sm tabular-nums">{value}</p>
          </div>
        ))}
      </div>
      <div className="rounded-md border bg-background divide-y">
        <StatRow label="المستودعات الحاملة" value={bi.warehouses_carrying} highlight />
        <StatRow label="إجمالي قيمة المخزون" value={bi.total_inventory_value > 0 ? fmt(bi.total_inventory_value, 0) : '—'} highlight />
        <StatRow label="الكمية المحجوزة" value={fmt(bi.reserved_qty, 0)} />
        <StatRow label="الطلبات المفتوحة" value={bi.open_orders ?? '—'} />
        <StatRow label="المتأخر في التسليم" value={bi.backordered_qty ?? '—'} />
        <StatRow label="تاريخ نفاد المخزون" value={bi.estimated_stockout_date ?? '—'} />
      </div>
    </section>
  );
}

// ── Section: Inventory Health ──────────────────────────────────────────────────

function InventoryHealthSection({ health }: { health: InventoryHealth }) {
  const total = health.on_hand;
  const healthPct = total > 0 ? (health.available / total) * 100 : 0;
  const healthColor = healthPct > 60 ? 'bg-emerald-500' : healthPct > 30 ? 'bg-amber-500' : 'bg-red-500';

  return (
    <section>
      <SectionLabel>صحة المخزون</SectionLabel>
      <div className="grid grid-cols-3 gap-1.5 mb-2">
        {[
          { label: 'الرصيد الفعلي', value: fmt(health.on_hand, 0) },
          { label: 'المحجوز', value: fmt(health.reserved, 0) },
          { label: 'المتاح', value: fmt(health.available, 0), hi: true },
        ].map(({ label, value, hi }) => (
          <div key={label} className={`rounded-md border px-2 py-1.5 text-center ${hi ? 'bg-background border-primary/30' : 'bg-background'}`}>
            <p className="text-[10px] text-muted-foreground">{label}</p>
            <p className={`font-semibold text-sm tabular-nums ${hi ? 'text-foreground' : ''}`}>{value}</p>
          </div>
        ))}
      </div>
      {total > 0 && (
        <div className="flex items-center gap-2 mb-2">
          <div className="flex-1 h-1.5 rounded-full bg-muted overflow-hidden">
            <div className={`h-full rounded-full ${healthColor}`} style={{ width: `${Math.min(100, healthPct)}%` }} />
          </div>
          <span className="text-[10px] text-muted-foreground">{Math.round(healthPct)}% متاح</span>
        </div>
      )}
      <div className="rounded-md border bg-background divide-y">
        <StatRow label="قادم" value={health.incoming > 0 ? fmt(health.incoming, 0) : '—'} highlight={health.incoming > 0} />
        <StatRow label="قيد النقل" value={health.in_transfer > 0 ? fmt(health.in_transfer, 0) : '—'} />
        <StatRow label="تالف" value={health.damaged ?? '—'} />
        <StatRow label="منتهي الصلاحية" value={health.expired ?? '—'} />
        <StatRow label="قرب انتهاء الصلاحية" value={health.near_expiry ?? '—'} />
        <StatRow label="في الحجر" value={health.quarantine ?? '—'} />
      </div>
    </section>
  );
}

// ── Section: Demand Intelligence ──────────────────────────────────────────────

function DemandIntelligenceSection({ demand }: { demand: DemandIntelligence }) {
  return (
    <section>
      <div className="flex items-center justify-between mb-2">
        <SectionLabel>ذكاء الطلب</SectionLabel>
        <div className="flex items-center gap-1 text-[10px] text-muted-foreground -mt-1">
          <TrendIcon trend={demand.trend} />
          <span>{demand.trend === 'normal' ? 'اتجاه طبيعي' : demand.trend === 'higher' ? 'أعلى من المتوسط' : 'أقل من المتوسط'}</span>
        </div>
      </div>
      <div className="grid grid-cols-3 gap-1.5 mb-2">
        {[
          { label: 'يومي', value: fmt(demand.daily_avg, 2) },
          { label: 'أسبوعي', value: fmt(demand.weekly_avg, 1) },
          { label: 'شهري', value: fmt(demand.monthly_avg, 0) },
        ].map(({ label, value }) => (
          <div key={label} className="rounded-md border bg-background px-2 py-1.5 text-center">
            <p className="text-[10px] text-muted-foreground">{label}</p>
            <p className="font-semibold text-sm tabular-nums">{value}</p>
          </div>
        ))}
      </div>
      <div className="rounded-md border bg-background divide-y">
        <StatRow label="متوسط 90 يومًا المتحرك" value={fmt(demand.rolling_90d_avg, 2)} />
        <StatRow label="أعلى استهلاك" value={fmt(demand.peak_consumption, 2)} />
        <StatRow label="التذبذب" value={demand.volatility != null ? fmt(demand.volatility, 2) : '—'} />
      </div>
    </section>
  );
}

// ── Section: Coverage Intelligence ────────────────────────────────────────────

function CoverageIntelligenceSection({ coverage }: { coverage: CoverageIntelligence }) {
  return (
    <section>
      <SectionLabel>ذكاء التغطية</SectionLabel>
      <div className="flex items-center justify-between rounded-md border bg-background px-3 py-2 mb-2">
        <div>
          <p className="text-[10px] text-muted-foreground">التغطية الحالية</p>
          <p className="font-semibold text-base tabular-nums">
            {coverage.current_coverage_days != null ? `${fmt(coverage.current_coverage_days, 1)} يوم` : '—'}
          </p>
        </div>
        <RiskBadge risk={coverage.risk} />
      </div>
      <div className="rounded-md border bg-background divide-y">
        <StatRow label="تاريخ النفاد" value={coverage.stockout_date ?? '—'} highlight={coverage.risk === 'critical' || coverage.risk === 'high'} />
        <StatRow label="تاريخ الشراء المقترح" value={coverage.suggested_purchase_date ?? '—'} />
        <StatRow label="مخزون الأمان" value={coverage.safety_stock ?? '—'} />
        <StatRow label="الحد الأدنى" value={coverage.min_stock ?? '—'} />
        <StatRow label="الحد الأقصى" value={coverage.max_stock ?? '—'} />
        <StatRow label="نقطة إعادة الطلب" value={coverage.reorder_point ?? '—'} />
      </div>
    </section>
  );
}

// ── Section: Procurement Intelligence ────────────────────────────────────────

function ProcurementIntelligenceSection({ proc }: { proc: ProcurementIntelligence }) {
  return (
    <section>
      <SectionLabel>ذكاء المشتريات</SectionLabel>
      {proc.last_purchase && (
        <div className="rounded-md border bg-background px-3 py-2 mb-2">
          <p className="text-[10px] text-muted-foreground mb-0.5">آخر عملية شراء</p>
          <p className="font-medium text-sm">{proc.last_purchase.supplier_name ?? '—'}</p>
          <div className="flex items-center justify-between text-xs text-muted-foreground mt-0.5">
            <span>{fmtDate(proc.last_purchase.purchase_date)}</span>
            {proc.last_purchase.last_price != null && (
              <span className="font-mono font-semibold text-foreground">{fmt(proc.last_purchase.last_price, 2)}</span>
            )}
          </div>
        </div>
      )}
      <div className="rounded-md border bg-background divide-y mb-2">
        <StatRow label="آخر تكلفة" value={proc.last_cost != null ? fmt(proc.last_cost, 2) : '—'} highlight />
        <StatRow label="متوسط التكلفة" value={proc.avg_cost != null ? fmt(proc.avg_cost, 2) : '—'} />
        <StatRow label="أدنى تكلفة" value={proc.lowest_cost != null ? fmt(proc.lowest_cost, 2) : '—'} />
        <StatRow label="أعلى تكلفة" value={proc.highest_cost != null ? fmt(proc.highest_cost, 2) : '—'} />
        <StatRow label="اتجاه السعر" value={<PriceTrendBadge trend={proc.price_trend} />} />
        <StatRow label="تكرار الشراء" value={proc.purchase_frequency != null ? `${fmt(proc.purchase_frequency, 1)}×/شهر` : '—'} />
        <StatRow label="مدة التوريد" value={proc.lead_time_days != null ? `${proc.lead_time_days} أيام` : '—'} />
        <StatRow label="MOQ" value={proc.moq != null ? fmt(proc.moq, 0) : '—'} />
      </div>
      {proc.alternative_suppliers.length > 0 && (
        <>
          <p className="text-[10px] uppercase tracking-wider font-semibold text-muted-foreground mb-1.5">
            موردون بديلون ({proc.alternative_suppliers.length})
          </p>
          <div className="flex flex-col gap-1">
            {proc.alternative_suppliers.map((s) => (
              <div key={s.supplier_id} className="rounded-md border bg-background px-3 py-2 flex items-center justify-between">
                <div>
                  <p className="font-medium text-xs leading-tight">{s.supplier_name}</p>
                  {s.last_delivery_date && (
                    <p className="text-muted-foreground text-[10px]">آخر تسليم: {fmtDate(s.last_delivery_date)}</p>
                  )}
                </div>
                <div className="text-end">
                  {s.last_price != null && (
                    <p className="font-mono text-xs font-semibold">{fmt(s.last_price, 2)}</p>
                  )}
                  {s.lead_time_days != null && (
                    <p className="text-muted-foreground text-[10px]">{s.lead_time_days}أيام توريد</p>
                  )}
                </div>
              </div>
            ))}
          </div>
        </>
      )}
    </section>
  );
}

// ── Section: Recommendations ──────────────────────────────────────────────────

function RecommendationsSection({ recs }: { recs: ProcurementPanelRecommendation[] }) {
  if (recs.length === 0) {
    return (
      <section>
        <SectionLabel>التوصيات</SectionLabel>
        <div className="flex items-center gap-2 text-xs text-emerald-700 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2">
          <CheckCircle className="size-3.5 shrink-0" />
          لا توجد مشكلات. المخزون في حالة جيدة.
        </div>
      </section>
    );
  }
  return (
    <section>
      <SectionLabel>التوصيات ({recs.length})</SectionLabel>
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
  if (events.length === 0) return null;

  return (
    <section>
      <SectionLabel>الجدول الزمني</SectionLabel>
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
  return (
    <div className="sticky bottom-0 bg-background border-t pt-2 -mx-0.5 px-0.5">
      <p className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1.5">إجراءات سريعة</p>
      <div className="flex flex-wrap gap-1.5">
        {[
          { label: 'عرض المنتج', path: `/products?highlight=${productId}` },
          { label: 'سجل المخزون', path: `/stock-ledger?product=${productId}` },
          { label: 'سجل الشراء', path: `/purchasing/purchase-materials?product=${productId}` },
        ].map(({ label, path }) => (
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
  const { data, isLoading, isError } = useProductDemandAnalysis(
    productId,
    { warehouse_id: warehouseId },
  );

  if (!productId) {
    return (
      <div className="flex flex-col items-center justify-center h-full gap-2 text-sm text-muted-foreground text-center px-4">
        <Info className="size-8 text-muted-foreground/40" />
        <p>اختر مادة لعرض ذكاء الطلب وتوصيات المشتريات.</p>
      </div>
    );
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-32 gap-2 text-sm text-muted-foreground">
        <Loader2 className="size-4 animate-spin" /> جارٍ التحميل…
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div className="flex items-center justify-center h-32 gap-2 text-sm text-muted-foreground">
        <AlertCircle className="size-4 text-destructive" /> فشل تحميل بيانات الطلب.
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
    <div className="flex flex-col gap-4 text-xs overflow-y-auto h-full pr-0.5">
      <BusinessImpactSection bi={data.business_impact} />
      <InventoryHealthSection health={data.inventory_health} />
      <DemandIntelligenceSection demand={data.demand_intelligence} />
      <CoverageIntelligenceSection coverage={data.coverage_intelligence} />
      <ProcurementIntelligenceSection proc={data.procurement_intelligence} />
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
