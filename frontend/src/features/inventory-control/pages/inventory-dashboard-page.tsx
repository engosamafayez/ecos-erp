import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import {
  Archive,
  BarChart3,
  ClipboardList,
  FlaskConical,
  Package,
  PackageX,
  ShoppingBag,
  TrendingDown,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { ErrorState, LoadingState, PageHeader } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { productsService } from '@/features/products/services/products-service';
import { useInventoryDashboard } from '@/features/inventory-control/hooks/use-inventory-control';
import type { HealthLabel, VarianceProductRow } from '@/features/inventory-control/types/inventory-control';
import { ROUTES } from '@/router/routes';

function fmtCurrency(val: number) {
  return val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtQty(val: number) {
  return val.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

function fmtPct(val: number | null) {
  if (val === null) return '—';
  return `${val.toFixed(1)}%`;
}

function healthVariant(h: HealthLabel): 'default' | 'secondary' | 'destructive' | 'outline' {
  return h === 'excellent' || h === 'good' ? 'default' : h === 'warning' ? 'secondary' : 'destructive';
}

type ValueKpiProps = {
  title: string;
  value: string;
  sub?: string;
  icon: LucideIcon;
  iconColor?: string;
  onClick?: () => void;
  loading?: boolean;
};

function ValueKpiCard({ title, value, sub, icon: Icon, iconColor = 'text-muted-foreground', onClick, loading }: ValueKpiProps) {
  return (
    <Card
      className={onClick ? 'cursor-pointer hover:border-primary/40 transition-colors' : ''}
      onClick={onClick}
    >
      <CardContent className="flex items-start gap-3 pt-5 pb-4">
        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
          <Icon className={`size-4 ${iconColor}`} />
        </div>
        <div className="min-w-0">
          <p className="text-muted-foreground text-xs truncate">{title}</p>
          {loading ? (
            <div className="mt-1 h-6 w-20 animate-pulse rounded bg-muted" />
          ) : (
            <p className="mt-0.5 text-2xl font-semibold tabular-nums leading-none">{value}</p>
          )}
          {sub && <p className="text-muted-foreground mt-1 text-[10px]">{sub}</p>}
        </div>
      </CardContent>
    </Card>
  );
}

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{children}</p>
  );
}

function VarianceTable({ rows, title }: { rows: VarianceProductRow[]; title: string }) {
  return (
    <Card className="flex-1 min-w-0">
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-muted-foreground border-b text-xs">
              <th className="px-4 py-2 text-start font-medium">Product</th>
              <th className="px-4 py-2 text-end font-medium">Variance (Qty)</th>
              <th className="px-4 py-2 text-end font-medium">Variance (Value)</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr><td colSpan={3} className="text-muted-foreground px-4 py-6 text-center text-xs">No data available</td></tr>
            ) : rows.map((r) => (
              <tr key={r.product_id} className="hover:bg-muted/50 border-b last:border-0 transition-colors">
                <td className="px-4 py-2">
                  <span className="font-medium">{r.product_name}</span>
                  <span className="text-muted-foreground ml-1.5 text-xs">{r.product_sku}</span>
                </td>
                <td className={`px-4 py-2 text-end font-mono tabular-nums ${r.variance_qty < 0 ? 'text-destructive' : 'text-emerald-600'}`}>
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
  const navigate = useNavigate();

  // Inventory value stats — three separate calls by product type
  const allStats = useQuery({
    queryKey: ['product-stats', 'all'],
    queryFn: () => productsService.stats({ product_types: 'raw_material,finished_good,packaging_material' }),
  });
  const rmStats = useQuery({
    queryKey: ['product-stats', 'raw_material'],
    queryFn: () => productsService.stats({ product_type: 'raw_material' }),
  });
  const fgStats = useQuery({
    queryKey: ['product-stats', 'finished_good'],
    queryFn: () => productsService.stats({ product_type: 'finished_good' }),
  });
  const pkgStats = useQuery({
    queryKey: ['product-stats', 'packaging_material'],
    queryFn: () => productsService.stats({ product_type: 'packaging_material' }),
  });

  // Count analytics dashboard
  const { data: countData, isLoading: countLoading, isError: countError } = useInventoryDashboard();

  const statsLoading = allStats.isLoading || rmStats.isLoading || fgStats.isLoading || pkgStats.isLoading;

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title="Inventory Dashboard"
        subtitle="Real-time inventory health and count analytics."
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Dashboard' }]}
      />

      {/* ── Section 1: Inventory Value ─────────────────────────────────────── */}
      <div className="flex flex-col gap-3">
        <SectionLabel>Inventory Value</SectionLabel>
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
          <ValueKpiCard
            title="Total Inventory Value"
            value={allStats.data ? `${fmtCurrency(allStats.data.total_inventory_value)} EGP` : '—'}
            sub={allStats.data ? `${fmtQty(allStats.data.total_on_hand)} units on hand` : undefined}
            icon={BarChart3}
            iconColor="text-primary"
            loading={statsLoading}
          />
          <ValueKpiCard
            title="Raw Materials Value"
            value={rmStats.data ? `${fmtCurrency(rmStats.data.total_inventory_value)} EGP` : '—'}
            sub={rmStats.data ? `${rmStats.data.total_count} SKU` : undefined}
            icon={FlaskConical}
            iconColor="text-blue-500"
            loading={statsLoading}
            onClick={() => navigate(ROUTES.rawMaterials)}
          />
          <ValueKpiCard
            title="Finished Goods Value"
            value={fgStats.data ? `${fmtCurrency(fgStats.data.total_inventory_value)} EGP` : '—'}
            sub={fgStats.data ? `${fgStats.data.total_count} SKU` : undefined}
            icon={Package}
            iconColor="text-emerald-500"
            loading={statsLoading}
            onClick={() => navigate(ROUTES.products)}
          />
          <ValueKpiCard
            title="Packaging Materials Value"
            value={pkgStats.data ? `${fmtCurrency(pkgStats.data.total_inventory_value)} EGP` : '—'}
            sub={pkgStats.data ? `${pkgStats.data.total_count} SKU` : undefined}
            icon={Archive}
            iconColor="text-amber-500"
            loading={statsLoading}
          />
          <ValueKpiCard
            title="Available Units"
            value={allStats.data ? fmtQty(allStats.data.total_available) : '—'}
            sub={allStats.data ? `${fmtQty(allStats.data.total_reserved)} reserved` : undefined}
            icon={ShoppingBag}
            iconColor="text-purple-500"
            loading={statsLoading}
          />
        </div>
      </div>

      {/* ── Section 2: Count Health KPIs ───────────────────────────────────── */}
      {countLoading ? (
        <LoadingState />
      ) : countError || !countData ? (
        <ErrorState />
      ) : (
        <>
          <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
              <SectionLabel>Count Session Health</SectionLabel>
              <Badge variant={healthVariant(countData.kpis.health)} className="text-xs">
                {countData.kpis.health === 'excellent' ? 'Excellent' : countData.kpis.health === 'good' ? 'Good' : countData.kpis.health === 'warning' ? 'Warning' : 'Critical'}
              </Badge>
            </div>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
              <ValueKpiCard
                title="Inventory Accuracy"
                value={fmtPct(countData.kpis.accuracy_pct)}
                sub={`${countData.kpis.matched_products}/${countData.kpis.total_counted_products} matched`}
                icon={BarChart3}
                iconColor="text-emerald-500"
              />
              <ValueKpiCard
                title="Open Sessions"
                value={String(countData.kpis.open_sessions)}
                icon={ClipboardList}
                iconColor="text-blue-500"
                onClick={() => navigate(ROUTES.inventoryCount)}
              />
              <ValueKpiCard
                title="Products with Variance"
                value={String(countData.kpis.products_with_variance)}
                icon={TrendingDown}
                iconColor="text-amber-500"
              />
              <ValueKpiCard
                title="Month Adjustments"
                value={`${fmtCurrency(countData.kpis.adjustment_value_month)} EGP`}
                icon={Package}
              />
              <ValueKpiCard
                title="Shrinkage (Month)"
                value={`${fmtCurrency(countData.kpis.shrinkage_value_month)} EGP`}
                icon={PackageX}
                iconColor="text-destructive"
              />
            </div>
          </div>

          {/* Variance tables */}
          <div className="flex flex-col gap-3">
            <SectionLabel>Top Variances</SectionLabel>
            <div className="flex flex-col gap-4 md:flex-row">
              <VarianceTable rows={countData.top_negative} title="Top Negative Variances (Shortage)" />
              <VarianceTable rows={countData.top_positive} title="Top Positive Variances (Surplus)" />
            </div>
          </div>

          {/* Recent sessions */}
          <div className="flex flex-col gap-3">
            <SectionLabel>Recent Count Sessions</SectionLabel>
            <Card>
              <CardContent className="p-0">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-muted-foreground border-b text-xs">
                      <th className="px-4 py-2 text-start font-medium">Session</th>
                      <th className="px-4 py-2 text-start font-medium">Warehouse</th>
                      <th className="px-4 py-2 text-start font-medium">Completion Date</th>
                      <th className="px-4 py-2 text-end font-medium">Accuracy</th>
                    </tr>
                  </thead>
                  <tbody>
                    {countData.recent_sessions.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="text-muted-foreground px-4 py-6 text-center text-xs">
                          No sessions yet.{' '}
                          <button
                            onClick={() => navigate(ROUTES.inventoryCount)}
                            className="text-primary underline underline-offset-2"
                          >
                            Create a session
                          </button>
                        </td>
                      </tr>
                    ) : countData.recent_sessions.map((s) => (
                      <tr
                        key={s.id}
                        className="hover:bg-muted/50 border-b last:border-0 transition-colors cursor-pointer"
                        onClick={() => navigate(ROUTES.inventoryCount)}
                      >
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
        </>
      )}
    </div>
  );
}
