import { useTranslation } from 'react-i18next';

import { ErrorState, LoadingState, PageHeader } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useInventoryDashboard } from '@/features/inventory-control/hooks/use-inventory-control';
import type { HealthLabel, VarianceProductRow } from '@/features/inventory-control/types/inventory-control';
import { ROUTES } from '@/router/routes';

function fmtPct(val: number | null) {
  if (val === null) return '—';
  return `${val.toFixed(1)}%`;
}

function fmtCurrency(val: number) {
  return val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function healthVariant(h: HealthLabel): 'default' | 'secondary' | 'destructive' | 'outline' {
  return h === 'excellent' || h === 'good' ? 'default' : h === 'warning' ? 'secondary' : 'destructive';
}

function KpiCard({ title, value, sub }: { title: string; value: string; sub?: string }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-muted-foreground text-sm">{title}</p>
        <p className="mt-1 text-3xl font-semibold tabular-nums">{value}</p>
        {sub && <p className="text-muted-foreground mt-0.5 text-xs">{sub}</p>}
      </CardContent>
    </Card>
  );
}

function VarianceTable({ rows, title }: { rows: VarianceProductRow[]; title: string }) {
  const { t } = useTranslation('inventory-control');
  return (
    <Card className="flex-1">
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-muted-foreground border-b text-xs">
              <th className="px-4 py-2 text-start font-medium">{t('dashboard.topVariances.product')}</th>
              <th className="px-4 py-2 text-end font-medium">{t('dashboard.topVariances.varianceQty')}</th>
              <th className="px-4 py-2 text-end font-medium">{t('dashboard.topVariances.varianceValue')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={3} className="text-muted-foreground px-4 py-6 text-center text-xs">—</td>
              </tr>
            ) : rows.map((r) => (
              <tr key={r.product_id} className="hover:bg-muted/50 border-b last:border-0 transition-colors">
                <td className="px-4 py-2">
                  <span className="font-medium">{r.product_name}</span>
                  <span className="text-muted-foreground ml-1.5 text-xs">{r.product_sku}</span>
                </td>
                <td className={`px-4 py-2 text-end font-mono tabular-nums ${r.variance_qty < 0 ? 'text-destructive' : 'text-green-600'}`}>
                  {r.variance_qty > 0 ? '+' : ''}{r.variance_qty.toFixed(2)}
                </td>
                <td className="text-muted-foreground px-4 py-2 text-end font-mono tabular-nums text-xs">
                  {fmtCurrency(r.variance_value)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}

export function InventoryDashboardPage() {
  const { t } = useTranslation('inventory-control');
  const { t: tCommon } = useTranslation('common');
  const { data, isLoading, isError } = useInventoryDashboard();

  if (isLoading) return <LoadingState />;
  if (isError || !data) return <ErrorState />;

  const { kpis, top_negative, top_positive, recent_sessions } = data;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('dashboard.title')}
        subtitle={
          <Badge variant={healthVariant(kpis.health)} className="mt-1">
            {t(`dashboard.health.${kpis.health}`)}
          </Badge>
        }
        breadcrumbs={[
          { label: tCommon('home'), to: ROUTES.dashboard },
          { label: t('dashboard.title') },
        ]}
      />

      {/* KPI Cards */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <KpiCard title={t('dashboard.kpis.accuracy')} value={fmtPct(kpis.accuracy_pct)} sub={`${kpis.matched_products}/${kpis.total_counted_products}`} />
        <KpiCard title={t('dashboard.kpis.openSessions')} value={String(kpis.open_sessions)} />
        <KpiCard title={t('dashboard.kpis.productsWithVariance')} value={String(kpis.products_with_variance)} />
        <KpiCard title={t('dashboard.kpis.adjustmentValue')} value={fmtCurrency(kpis.adjustment_value_month)} />
        <KpiCard title={t('dashboard.kpis.shrinkage')} value={fmtCurrency(kpis.shrinkage_value_month)} />
        <KpiCard
          title={t('dashboard.kpis.lastCountDate')}
          value={kpis.last_count_date ? kpis.last_count_date.slice(0, 10) : t('dashboard.kpis.never')}
        />
      </div>

      {/* Top variance tables */}
      <div className="flex flex-col gap-4 md:flex-row">
        <VarianceTable rows={top_negative} title={t('dashboard.topVariances.negativeTitle')} />
        <VarianceTable rows={top_positive} title={t('dashboard.topVariances.positiveTitle')} />
      </div>

      {/* Recent sessions */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm font-medium">{t('dashboard.recentSessions.title')}</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-muted-foreground border-b text-xs">
                <th className="px-4 py-2 text-start font-medium">{t('dashboard.recentSessions.session')}</th>
                <th className="px-4 py-2 text-start font-medium">{t('dashboard.recentSessions.warehouse')}</th>
                <th className="px-4 py-2 text-start font-medium">{t('dashboard.recentSessions.completedAt')}</th>
                <th className="px-4 py-2 text-end font-medium">{t('dashboard.recentSessions.accuracy')}</th>
              </tr>
            </thead>
            <tbody>
              {recent_sessions.length === 0 ? (
                <tr>
                  <td colSpan={4} className="text-muted-foreground px-4 py-6 text-center text-xs">—</td>
                </tr>
              ) : recent_sessions.map((s) => (
                <tr key={s.id} className="hover:bg-muted/50 border-b last:border-0 transition-colors">
                  <td className="px-4 py-2 font-mono text-xs">{s.count_number}</td>
                  <td className="px-4 py-2">{s.warehouse_name}</td>
                  <td className="text-muted-foreground px-4 py-2 text-xs">{s.completed_at?.slice(0, 10) ?? '—'}</td>
                  <td className="px-4 py-2 text-end font-mono tabular-nums">
                    {s.accuracy_pct !== null ? `${s.accuracy_pct.toFixed(1)}%` : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>
    </div>
  );
}
