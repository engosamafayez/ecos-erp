import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Package, ShoppingCart, TrendingUp, Wallet } from 'lucide-react';

import { PageHeader } from '@/components/crud';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useSupplierQuery } from '@/features/suppliers/hooks/use-suppliers';
import {
  useSupplierAnalytics,
  useSupplierInventoryBreakdown,
} from '@/features/suppliers/hooks/use-supplier-analytics';
import { ROUTES } from '@/router/routes';

function fmt(n: number, decimals = 2) {
  return n.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function StatCard({
  label,
  value,
  icon: Icon,
  accent,
}: {
  label: string;
  value: string;
  icon: React.ElementType;
  accent?: boolean;
}) {
  return (
    <div
      className={`flex items-center gap-4 rounded-lg border px-4 py-3 ${
        accent ? 'border-primary/20 bg-primary/5' : 'bg-card'
      }`}
    >
      <div
        className={`flex size-9 shrink-0 items-center justify-center rounded-md ${
          accent ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
        }`}
      >
        <Icon className="size-4" />
      </div>
      <div className="min-w-0">
        <p className="text-muted-foreground text-xs">{label}</p>
        <p className="text-foreground truncate text-base font-semibold tabular-nums">{value}</p>
      </div>
    </div>
  );
}

export function ViewSupplierPage() {
  const { t } = useTranslation('suppliers');
  const { id } = useParams<{ id: string }>();

  const { data: supplier, isLoading: supplierLoading } = useSupplierQuery(id ?? '');
  const { data: analytics, isLoading: analyticsLoading } = useSupplierAnalytics(id ?? '');
  const { data: breakdown = [], isLoading: breakdownLoading } = useSupplierInventoryBreakdown(id ?? '');

  if (supplierLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <span className="text-muted-foreground text-sm">{t('detail.loading')}</span>
      </div>
    );
  }

  if (!supplier) {
    return (
      <div className="flex h-64 items-center justify-center">
        <span className="text-destructive text-sm">{t('detail.notFound')}</span>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={supplier.name}
        subtitle={supplier.code}
        breadcrumbs={[
          { label: t('title'), to: ROUTES.suppliers },
          { label: supplier.name },
        ]}
      />

      {/* Purchasing Summary */}
      <Card>
        <CardHeader>
          <CardTitle>{t('detail.purchasing')}</CardTitle>
        </CardHeader>
        <CardContent>
          {analyticsLoading ? (
            <p className="text-muted-foreground text-sm">{t('detail.loading')}</p>
          ) : analytics ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
              <StatCard
                label={t('detail.totalPurchases')}
                value={String(analytics.total_purchases)}
                icon={ShoppingCart}
              />
              <StatCard
                label={t('detail.totalInvoiced')}
                value={fmt(analytics.total_invoiced)}
                icon={Wallet}
              />
              <StatCard
                label={t('detail.totalPaid')}
                value={fmt(analytics.total_paid)}
                icon={Wallet}
              />
              <StatCard
                label={t('detail.outstandingBalance')}
                value={fmt(analytics.outstanding_balance)}
                icon={Wallet}
                accent={analytics.outstanding_balance > 0}
              />
              <StatCard
                label={t('detail.lastPurchaseDate')}
                value={analytics.last_purchase_date ?? '—'}
                icon={ShoppingCart}
              />
            </div>
          ) : null}
        </CardContent>
      </Card>

      {/* Current Inventory */}
      <Card>
        <CardHeader>
          <CardTitle>{t('detail.inventory')}</CardTitle>
        </CardHeader>
        <CardContent>
          {analyticsLoading ? (
            <p className="text-muted-foreground text-sm">{t('detail.loading')}</p>
          ) : analytics ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <StatCard
                label={t('detail.currentQty')}
                value={fmt(analytics.current_inventory_quantity, 4)}
                icon={Package}
              />
              <StatCard
                label={t('detail.costValue')}
                value={fmt(analytics.current_inventory_cost_value)}
                icon={Wallet}
              />
              <StatCard
                label={t('detail.saleValue')}
                value={fmt(analytics.current_inventory_sale_value)}
                icon={TrendingUp}
              />
              <StatCard
                label={t('detail.grossProfit')}
                value={fmt(analytics.potential_gross_profit)}
                icon={TrendingUp}
                accent={analytics.potential_gross_profit > 0}
              />
            </div>
          ) : null}
        </CardContent>
      </Card>

      {/* Inventory Breakdown */}
      <Card>
        <CardHeader>
          <CardTitle>{t('detail.inventoryBreakdown')}</CardTitle>
        </CardHeader>
        <CardContent>
          {breakdownLoading ? (
            <p className="text-muted-foreground text-sm">{t('detail.loading')}</p>
          ) : breakdown.length === 0 ? (
            <p className="text-muted-foreground text-sm">{t('detail.noInventory')}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-muted-foreground border-b text-left">
                    <th className="pb-2 pr-3 font-medium">{t('detail.product')}</th>
                    <th className="w-24 pb-2 pr-3 text-right font-medium">{t('detail.qty')}</th>
                    <th className="w-28 pb-2 pr-3 text-right font-medium">{t('detail.avgCost')}</th>
                    <th className="w-28 pb-2 pr-3 text-right font-medium">{t('detail.salePrice')}</th>
                    <th className="w-28 pb-2 pr-3 text-right font-medium">{t('detail.costValue')}</th>
                    <th className="w-28 pb-2 pr-3 text-right font-medium">{t('detail.saleValue')}</th>
                    <th className="w-28 pb-2 text-right font-medium">{t('detail.grossProfit')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {breakdown.map((p) => (
                    <tr key={p.product_id}>
                      <td className="py-2 pr-3">
                        <span className="font-medium">{p.product_name}</span>
                        <span className="text-muted-foreground ml-1.5 text-xs">{p.product_sku}</span>
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(p.remaining_quantity, 4)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">
                        {p.average_cost != null ? fmt(p.average_cost, 4) : '—'}
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums">
                        {p.sale_price != null ? fmt(p.sale_price) : '—'}
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(p.cost_value)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{fmt(p.sale_value)}</td>
                      <td className="py-2 text-right tabular-nums font-medium">{fmt(p.gross_profit)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
