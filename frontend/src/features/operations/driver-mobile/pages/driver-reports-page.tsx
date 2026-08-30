import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, BarChart3, ChevronLeft, ChevronRight } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

import { ReportPeriodFilter } from '../components/report-period-filter';
import {
  useDriverAdvances,
  useDriverGoodsMovement,
  useDriverOrdersReport,
  useDriverShortages,
} from '../hooks/use-driver-mobile';
import type { ReportPeriodValue } from '../types/reports';

type Tab = 'orders' | 'goods' | 'shortage' | 'advances';
const TABS: Tab[] = ['orders', 'goods', 'shortage', 'advances'];

/**
 * Driver Reports (§3) — the driver's own operational reports, tabbed and mobile-first. Every
 * dataset is a server-derived, driver-scoped, date-filtered read; the driver sees only their own.
 */
export function DriverReportsPage() {
  const { t } = useTranslation('driver-mobile');
  const navigate = useNavigate();
  const [tab, setTab] = useState<Tab>('orders');
  const [period, setPeriod] = useState<ReportPeriodValue>({ period: 'this_month' });

  return (
    <div className="min-h-screen bg-background pb-8">
      <div className="sticky top-0 z-10 border-b bg-background">
        <div className="flex items-center gap-3 px-4 py-3">
          <Button variant="ghost" size="icon" aria-label={t(($) => $.nav.home)} onClick={() => navigate(ROUTES.driverHome)}>
            <ArrowLeft className="h-5 w-5" aria-hidden="true" />
          </Button>
          <h1 className="flex items-center gap-2 text-base font-semibold">
            <BarChart3 className="h-5 w-5" aria-hidden="true" />
            {t(($) => $.reports.title)}
          </h1>
        </div>
        <div className="flex gap-1 overflow-x-auto px-3 pb-2">
          {TABS.map((tb) => (
            <button
              key={tb}
              type="button"
              onClick={() => setTab(tb)}
              className={cn(
                'shrink-0 rounded-full px-3 py-1.5 text-xs font-medium transition-colors',
                tab === tb ? 'bg-primary text-primary-foreground' : 'text-muted-foreground',
              )}
            >
              {t(($) => $.reports.tabs[tb])}
            </button>
          ))}
        </div>
      </div>

      <div className="space-y-4 p-4">
        {tab !== 'advances' && <ReportPeriodFilter value={period} onChange={setPeriod} />}
        {tab === 'orders' && <OrdersTab period={period} />}
        {tab === 'goods' && <GoodsTab period={period} />}
        {tab === 'shortage' && <ShortageTab period={period} />}
        {tab === 'advances' && <AdvancesTab />}
      </div>
    </div>
  );
}

function LoadError({ onRetry }: { onRetry: () => void }) {
  const { t } = useTranslation('driver-mobile');
  return (
    <div className="flex flex-col items-center gap-3 py-12 text-muted-foreground">
      <AlertTriangle className="h-8 w-8 text-destructive/70" aria-hidden="true" />
      <p className="text-sm">{t(($) => $.reports.error)}</p>
      <Button variant="outline" size="sm" onClick={onRetry}>{t(($) => $.reports.retry)}</Button>
    </div>
  );
}

function Empty({ text }: { text: string }) {
  return <p className="py-12 text-center text-sm text-muted-foreground">{text}</p>;
}

function StatTile({ label, value, tone }: { label: string; value: number | string; tone?: string }) {
  return (
    <div className="rounded-lg border bg-card p-3 text-center">
      <p className={cn('text-xl font-bold tabular-nums leading-none', tone)}>{value}</p>
      <p className="mt-1 text-[11px] text-muted-foreground">{label}</p>
    </div>
  );
}

function OrdersTab({ period }: { period: ReportPeriodValue }) {
  const { t } = useTranslation('driver-mobile');
  const { money } = useFormatter();
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, refetch } = useDriverOrdersReport(period, page);

  if (isLoading && !data) return <Skeleton className="h-64 w-full rounded-xl" />;
  if (isError || !data) return <LoadError onRetry={() => void refetch()} />;

  const s = data.summary;
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-2">
        <StatTile label={t(($) => $.reports.orders.received)} value={s.received} />
        <StatTile label={t(($) => $.reports.orders.delivered)} value={s.delivered} tone="text-green-600" />
        <StatTile label={t(($) => $.reports.orders.deliveryRate)} value={`${s.delivery_rate}%`} />
        <StatTile label={t(($) => $.reports.orders.partial)} value={s.partial} tone="text-amber-600" />
        <StatTile label={t(($) => $.reports.orders.failed)} value={s.failed} tone="text-red-600" />
        <StatTile label={t(($) => $.reports.orders.deferred)} value={s.deferred} />
      </div>

      {data.items.length === 0 ? (
        <Empty text={t(($) => $.reports.orders.empty)} />
      ) : (
        <div className="space-y-2">
          {data.items.map((row, i) => (
            <div key={`${row.order_id}-${i}`} className="rounded-lg border bg-card p-3">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{row.order_number ?? '—'}</p>
                  <p className="truncate text-xs text-muted-foreground">
                    {[row.customer_name, row.area].filter(Boolean).join(' · ') || '—'}
                  </p>
                </div>
                <div className="shrink-0 text-end">
                  <Badge variant="outline" className="text-[10px]">{row.outcome}</Badge>
                  {row.order_value !== null && (
                    <p className="mt-1 text-xs tabular-nums">{money(row.order_value)}</p>
                  )}
                </div>
              </div>
            </div>
          ))}
          {data.meta.last_page > 1 && (
            <div className="flex items-center justify-between pt-1 text-xs">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                <ChevronLeft className="h-4 w-4" aria-hidden="true" />
              </Button>
              <span className="text-muted-foreground">{data.meta.current_page} / {data.meta.last_page}</span>
              <Button variant="outline" size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                <ChevronRight className="h-4 w-4" aria-hidden="true" />
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function GoodsTab({ period }: { period: ReportPeriodValue }) {
  const { t } = useTranslation('driver-mobile');
  const { data, isLoading, isError, refetch } = useDriverGoodsMovement(period);

  if (isLoading && !data) return <Skeleton className="h-56 w-full rounded-xl" />;
  if (isError || !data) return <LoadError onRetry={() => void refetch()} />;
  if (data.products.length === 0) return <Empty text={t(($) => $.reports.goods.empty)} />;

  return (
    <div className="space-y-3">
      {data.products.map((p) => (
        <div key={p.product_id} className="rounded-xl border bg-card p-4">
          <p className="truncate text-sm font-semibold">{p.product_name}</p>
          <p className="mb-2 text-xs text-muted-foreground" dir="ltr">{p.sku}</p>
          <div className="grid grid-cols-3 gap-2 text-xs">
            <GoodsCell label={t(($) => $.reports.goods.received)} value={p.received} />
            <GoodsCell label={t(($) => $.reports.goods.delivered)} value={p.delivered} tone="text-green-600" />
            <GoodsCell label={t(($) => $.reports.goods.remaining)} value={p.remaining_custody} tone="text-primary" />
            <GoodsCell label={t(($) => $.reports.goods.returned)} value={p.returned} tone="text-amber-600" />
            <GoodsCell label={t(($) => $.reports.goods.damaged)} value={p.damaged} tone="text-red-600" />
            <GoodsCell label={t(($) => $.reports.goods.shortage)} value={p.shortage} tone="text-red-600" />
          </div>
        </div>
      ))}
      <p className="text-[11px] text-muted-foreground">{t(($) => $.reports.goods.note)}</p>
    </div>
  );
}

function GoodsCell({ label, value, tone }: { label: string; value: number; tone?: string }) {
  return (
    <div className="flex flex-col items-center rounded-lg bg-muted/40 py-2">
      <span className={cn('text-base font-semibold tabular-nums', tone)}>{value}</span>
      <span className="text-[10px] text-muted-foreground">{label}</span>
    </div>
  );
}

function ShortageTab({ period }: { period: ReportPeriodValue }) {
  const { t } = useTranslation('driver-mobile');
  const { data, isLoading, isError, refetch } = useDriverShortages(period);

  if (isLoading && !data) return <Skeleton className="h-48 w-full rounded-xl" />;
  if (isError || !data) return <LoadError onRetry={() => void refetch()} />;

  return (
    <div className="space-y-3">
      <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-[11px] text-amber-800">
        {t(($) => $.reports.shortage.noAutoDebt)}
      </p>
      {data.items.length === 0 ? (
        <Empty text={t(($) => $.reports.shortage.empty)} />
      ) : (
        data.items.map((row, i) => (
          <div key={`${row.product_id}-${i}`} className="rounded-lg border bg-card p-3">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium" dir="ltr">{row.sku}</p>
                {row.damage_reason && <p className="truncate text-xs text-muted-foreground">{row.damage_reason}</p>}
              </div>
              <Badge variant="secondary" className="shrink-0 text-[10px]">
                {t(($) => $.reports.shortage[row.investigation_status === 'reviewed' ? 'reviewed' : 'underInvestigation'])}
              </Badge>
            </div>
            <p className="mt-1 text-sm font-semibold text-red-600 tabular-nums">
              {t(($) => $.reports.shortage.shortageQty)}: {row.shortage_qty}
            </p>
          </div>
        ))
      )}
      <p className="text-[11px] text-muted-foreground">{t(($) => $.reports.shortage.valueUnavailable)}</p>
    </div>
  );
}

function AdvancesTab() {
  const { t } = useTranslation('driver-mobile');
  const { data, isLoading } = useDriverAdvances();

  if (isLoading) return <Skeleton className="h-32 w-full rounded-xl" />;

  // Whether or not the read resolves, there is no canonical driver advances authority (§5).
  return (
    <div className="flex flex-col items-center gap-2 py-14 text-center text-muted-foreground">
      <AlertTriangle className="h-9 w-9 opacity-40" aria-hidden="true" />
      <p className="text-sm font-medium">{t(($) => $.reports.advances.unavailable)}</p>
      <p className="max-w-xs text-xs">{t(($) => $.reports.advances.unavailableHint)}</p>
      {data && !data.available && <span className="sr-only">{data.reason}</span>}
    </div>
  );
}
