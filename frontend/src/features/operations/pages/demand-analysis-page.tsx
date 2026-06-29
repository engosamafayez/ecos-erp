import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Download, RefreshCw } from 'lucide-react';

import { ErrorState, LoadingState, PageHeader, SearchInput } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useDemandAnalysis } from '@/features/operations/hooks/use-demand-analysis';
import type { DemandLine, InventoryStatus } from '@/features/operations/types/demand-analysis';
import { ROUTES } from '@/router/routes';

// ── Status helpers ────────────────────────────────────────────────────────────

type StatusFilter = InventoryStatus | 'ALL';

const STATUS_BADGE: Record<InventoryStatus, string> = {
  READY:        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
  SHORTAGE:     'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  OUT_OF_STOCK: 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  UNKNOWN:      'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-muted text-muted-foreground',
};

// ── Summary card ─────────────────────────────────────────────────────────────

function SummaryCard({
  title,
  value,
  accent,
}: {
  title: string;
  value: number;
  accent?: 'green' | 'amber' | 'red';
}) {
  const colorMap = {
    green: 'text-green-600 dark:text-green-400',
    amber: 'text-amber-600 dark:text-amber-400',
    red:   'text-red-600 dark:text-red-400',
  };
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-muted-foreground text-sm">{title}</p>
        <p className={`mt-1 text-3xl font-semibold tabular-nums ${accent ? colorMap[accent] : ''}`}>
          {value.toLocaleString()}
        </p>
      </CardContent>
    </Card>
  );
}

// ── CSV export ────────────────────────────────────────────────────────────────

function exportCsv(lines: DemandLine[], operationalDay: string): void {
  const headers = [
    'SKU', 'Product', 'Ordered Qty', 'Reserved Qty', 'Available Qty',
    'Required Qty', 'Shortage Qty', 'Status', 'Orders', 'Channels', 'Warehouses',
  ];
  const rows = lines.map((l) => [
    l.sku,
    l.product_name,
    l.ordered_qty,
    l.reserved_qty,
    l.available_qty ?? '',
    l.required_qty,
    l.shortage_qty,
    l.inventory_status,
    l.affected_orders_count,
    l.affected_channels_count,
    l.warehouse_count,
  ]);

  const csv = [headers, ...rows]
    .map((row) =>
      row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(','),
    )
    .join('\n');

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `demand-analysis-${operationalDay}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function DemandAnalysisPage() {
  const { t }       = useTranslation('operations');
  const { t: tCommon } = useTranslation('common');

  const { data, isLoading, isError, refetch, isFetching } = useDemandAnalysis();

  const [search, setSearch]             = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('ALL');

  const filteredLines = useMemo(() => {
    if (!data) return [];
    const q = search.trim().toLowerCase();
    return data.demand_lines.filter((line) => {
      const matchSearch =
        !q ||
        line.product_name.toLowerCase().includes(q) ||
        line.sku.toLowerCase().includes(q);
      const matchStatus =
        statusFilter === 'ALL' || line.inventory_status === statusFilter;
      return matchSearch && matchStatus;
    });
  }, [data, search, statusFilter]);

  if (isLoading) return <LoadingState />;
  if (isError || !data) return <ErrorState />;

  const { summary, operational_day, generated_at } = data;

  const generatedTime = new Date(generated_at).toLocaleTimeString(undefined, {
    hour: '2-digit',
    minute: '2-digit',
  });

  const STATUS_FILTERS: { label: string; value: StatusFilter }[] = [
    { label: t('demandAnalysis.filterAll'),        value: 'ALL' },
    { label: t('demandAnalysis.filterReady'),      value: 'READY' },
    { label: t('demandAnalysis.filterShortage'),   value: 'SHORTAGE' },
    { label: t('demandAnalysis.filterOutOfStock'), value: 'OUT_OF_STOCK' },
    { label: t('demandAnalysis.filterUnknown'),    value: 'UNKNOWN' },
  ];

  return (
    <div className="flex flex-col gap-6">
      {/* Header */}
      <PageHeader
        title={t('demandAnalysis.title')}
        subtitle={`${t('demandAnalysis.subtitle')} ${operational_day} · ${t('demandAnalysis.generatedAt')} ${generatedTime}`}
        breadcrumbs={[
          { label: tCommon('home'), to: ROUTES.dashboard },
          { label: t('demandAnalysis.title') },
        ]}
        actions={
          <div className="flex gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() => void refetch()}
              disabled={isFetching}
            >
              <RefreshCw className={`mr-1.5 size-3.5 ${isFetching ? 'animate-spin' : ''}`} />
              {t('demandAnalysis.refresh')}
            </Button>
            <Button
              size="sm"
              onClick={() => exportCsv(filteredLines, operational_day)}
              disabled={filteredLines.length === 0}
            >
              <Download className="mr-1.5 size-3.5" />
              {t('demandAnalysis.exportCsv')}
            </Button>
          </div>
        }
      />

      {/* Summary cards */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <SummaryCard title={t('demandAnalysis.summary.totalOrders')}  value={summary.total_orders} />
        <SummaryCard title={t('demandAnalysis.summary.totalProducts')} value={summary.total_products} />
        <SummaryCard title={t('demandAnalysis.summary.ready')}        value={summary.ready_count}        accent="green" />
        <SummaryCard title={t('demandAnalysis.summary.shortage')}     value={summary.shortage_count}     accent="amber" />
        <SummaryCard title={t('demandAnalysis.summary.outOfStock')}   value={summary.out_of_stock_count} accent="red" />
      </div>

      {/* Demand Matrix Table */}
      <Card>
        <CardContent className="flex flex-col gap-4 pt-4">
          {/* Toolbar */}
          <div className="flex flex-wrap items-center gap-3">
            <SearchInput
              onChange={setSearch}
              placeholder={t('demandAnalysis.search')}
              className="max-w-xs"
            />
            <div className="flex flex-wrap gap-1.5">
              {STATUS_FILTERS.map((f) => (
                <Button
                  key={f.value}
                  size="sm"
                  variant={statusFilter === f.value ? 'default' : 'outline'}
                  onClick={() => setStatusFilter(f.value)}
                >
                  {f.label}
                </Button>
              ))}
            </div>
          </div>

          {/* Table */}
          <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm">
              <thead className="sticky top-0 z-10 bg-muted/60 backdrop-blur-sm border-b">
                <tr className="text-muted-foreground text-xs">
                  <th className="px-4 py-2.5 text-start font-medium">{t('demandAnalysis.table.product')}</th>
                  <th className="px-4 py-2.5 text-start font-medium">{t('demandAnalysis.table.sku')}</th>
                  <th className="px-4 py-2.5 text-end font-medium">{t('demandAnalysis.table.ordered')}</th>
                  <th className="px-4 py-2.5 text-end font-medium">{t('demandAnalysis.table.reserved')}</th>
                  <th className="px-4 py-2.5 text-end font-medium">{t('demandAnalysis.table.available')}</th>
                  <th className="px-4 py-2.5 text-end font-medium">{t('demandAnalysis.table.required')}</th>
                  <th className="px-4 py-2.5 text-end font-medium">{t('demandAnalysis.table.shortage')}</th>
                  <th className="px-4 py-2.5 text-center font-medium">{t('demandAnalysis.table.status')}</th>
                  <th className="px-4 py-2.5 text-end font-medium">{t('demandAnalysis.table.orders')}</th>
                  <th className="px-4 py-2.5 text-end font-medium">{t('demandAnalysis.table.warehouses')}</th>
                </tr>
              </thead>
              <tbody>
                {filteredLines.length === 0 ? (
                  <tr>
                    <td
                      colSpan={10}
                      className="text-muted-foreground px-4 py-10 text-center text-sm"
                    >
                      {t('demandAnalysis.table.empty')}
                    </td>
                  </tr>
                ) : (
                  filteredLines.map((line) => (
                    <tr
                      key={line.product_id}
                      className="hover:bg-muted/40 border-b last:border-0 transition-colors"
                    >
                      <td className="px-4 py-2.5 font-medium">{line.product_name}</td>
                      <td className="text-muted-foreground px-4 py-2.5 font-mono text-xs">
                        {line.sku}
                      </td>
                      <td className="px-4 py-2.5 text-end font-mono tabular-nums">
                        {line.ordered_qty.toLocaleString(undefined, { maximumFractionDigits: 2 })}
                      </td>
                      <td className="text-muted-foreground px-4 py-2.5 text-end font-mono tabular-nums">
                        {line.reserved_qty.toLocaleString(undefined, { maximumFractionDigits: 2 })}
                      </td>
                      <td className="px-4 py-2.5 text-end font-mono tabular-nums">
                        {line.available_qty !== null
                          ? line.available_qty.toLocaleString(undefined, { maximumFractionDigits: 2 })
                          : <span className="text-muted-foreground">{t('demandAnalysis.table.noInventory')}</span>
                        }
                      </td>
                      <td className="px-4 py-2.5 text-end font-mono tabular-nums">
                        {line.required_qty.toLocaleString(undefined, { maximumFractionDigits: 2 })}
                      </td>
                      <td className={`px-4 py-2.5 text-end font-mono tabular-nums ${line.shortage_qty > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-muted-foreground'}`}>
                        {line.shortage_qty > 0
                          ? line.shortage_qty.toLocaleString(undefined, { maximumFractionDigits: 2 })
                          : '—'
                        }
                      </td>
                      <td className="px-4 py-2.5 text-center">
                        <span className={STATUS_BADGE[line.inventory_status]}>
                          {t(`demandAnalysis.status.${line.inventory_status}`)}
                        </span>
                      </td>
                      <td className="text-muted-foreground px-4 py-2.5 text-end tabular-nums">
                        {line.affected_orders_count}
                      </td>
                      <td className="text-muted-foreground px-4 py-2.5 text-end tabular-nums">
                        {line.warehouse_count}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {filteredLines.length > 0 && (
            <p className="text-muted-foreground text-xs">
              {filteredLines.length} {filteredLines.length === 1 ? 'product' : 'products'} shown
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
