import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, ClipboardList, Loader2, PackageCheck, PackageOpen, Truck } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { EntityToolbar, PageHeader, Pagination } from '@/components/crud';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { useWarehouseOptions } from '@/features/goods-receipts/hooks/use-warehouse-options';
import { useSupplierOptions } from '@/features/purchase-orders/hooks/use-supplier-options';
import { useReceivingQueue } from '../hooks/use-receiving';
import type { ReceivingQueueRow, ReceivingScope } from '../types/receiving';
import { ReceiveDrawer } from '../components/receive-drawer';

const PER_PAGE = 15;

function fmt(n: number) {
  return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
}

type ReceiptStage = 'awaiting' | 'partial' | 'received';

function stageOf(row: ReceivingQueueRow): ReceiptStage {
  if (row.remaining_qty <= 0) return 'received';
  return row.received_qty > 0 ? 'partial' : 'awaiting';
}

function StageBadge({ row }: { row: ReceivingQueueRow }) {
  const { t } = useTranslation('receiving-center');
  const stage = stageOf(row);
  const cls: Record<ReceiptStage, string> = {
    awaiting: 'bg-muted text-muted-foreground',
    partial: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    received: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  };
  return <Badge className={`${cls[stage]} hover:${cls[stage]}`}>{t($ => $.page.status[stage])}</Badge>;
}

function KpiChip({ icon: Icon, label, value, tone }: { icon: typeof Truck; label: string; value: number; tone: string }) {
  return (
    <div className="rounded-lg border bg-card p-3 flex items-center gap-3 min-w-0">
      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-md ${tone}`}>
        <Icon className="h-5 w-5" />
      </span>
      <div className="min-w-0">
        <p className="text-[11px] uppercase tracking-wide text-muted-foreground truncate">{label}</p>
        <p className="text-xl font-semibold tabular-nums leading-tight">{value}</p>
      </div>
    </div>
  );
}

export function ReceivingCenterPage() {
  const { t } = useTranslation('receiving-center');
  const { data: warehouseOptions } = useWarehouseOptions();
  const { data: supplierOptions } = useSupplierOptions();

  const [scope, setScope] = useState<ReceivingScope>('active');
  const [search, setSearch] = useState('');
  const [supplierId, setSupplierId] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [receivePo, setReceivePo] = useState<ReceivingQueueRow | null>(null);

  // Empty string = "all" — every filter narrows the SERVER-SIDE queue (see ReceivingCenterController::queue).
  const params = useMemo(
    () => ({
      scope,
      search: search || undefined,
      supplier_id: supplierId || undefined,
      warehouse_id: warehouseId || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page,
      per_page: PER_PAGE,
    }),
    [scope, search, supplierId, warehouseId, dateFrom, dateTo, page],
  );

  const { data, isLoading, isFetching, isError, refetch } = useReceivingQueue(params);
  const items = data?.items ?? [];
  const meta = data?.meta;
  const kpis = data?.kpis;

  function changeScope(next: ReceivingScope) {
    setScope(next);
    setPage(1);
  }

  // Clears the advanced filters (search keeps its own inline clear button, per the ECOS toolbar idiom).
  function clearFilters() {
    setSupplierId('');
    setWarehouseId('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
  }

  const columns: DataGridColumnDef<ReceivingQueueRow>[] = useMemo(
    () => [
      {
        key: 'po_number',
        label: t($ => $.page.columns.po),
        alwaysVisible: true,
        cell: (r) => (
          <div className="min-w-0">
            <span className="font-mono text-sm font-medium block">{r.po_number}</span>
            <span className="text-[11px] text-muted-foreground">{r.order_date ?? '—'}</span>
          </div>
        ),
      },
      {
        key: 'supplier',
        label: t($ => $.page.columns.supplier),
        defaultVisible: true,
        cell: (r) => <span className="text-sm">{r.supplier?.name ?? '—'}</span>,
      },
      {
        key: 'warehouse',
        label: t($ => $.page.columns.warehouse),
        defaultVisible: true,
        cell: (r) => <span className="text-sm text-muted-foreground">{r.warehouse ? `${r.warehouse.code} — ${r.warehouse.name}` : '—'}</span>,
      },
      {
        key: 'products',
        label: t($ => $.page.columns.products),
        defaultVisible: true,
        align: 'end',
        cell: (r) => <span className="tabular-nums text-sm">{r.product_count}</span>,
      },
      {
        key: 'expected',
        label: t($ => $.page.columns.expected),
        defaultVisible: true,
        align: 'end',
        cell: (r) => <span className="tabular-nums text-sm">{fmt(r.expected_qty)}</span>,
      },
      {
        key: 'received',
        label: t($ => $.page.columns.received),
        defaultVisible: true,
        align: 'end',
        cell: (r) => <span className="tabular-nums text-sm text-emerald-700 dark:text-emerald-400">{fmt(r.received_qty)}</span>,
      },
      {
        key: 'remaining',
        label: t($ => $.page.columns.remaining),
        defaultVisible: true,
        align: 'end',
        cell: (r) => (
          <span className={`tabular-nums text-sm font-medium ${r.remaining_qty > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'}`}>
            {r.remaining_qty > 0 ? fmt(r.remaining_qty) : '—'}
          </span>
        ),
      },
      {
        key: 'status',
        label: t($ => $.page.columns.status),
        alwaysVisible: true,
        cell: (r) => <StageBadge row={r} />,
      },
      {
        key: 'action',
        label: t($ => $.page.columns.action),
        alwaysVisible: true,
        align: 'end',
        cell: (r) =>
          r.remaining_qty > 0 ? (
            <Button variant="ghost" size="sm" className="h-7 gap-1.5 text-xs" onClick={() => setReceivePo(r)}>
              <PackageCheck className="h-3.5 w-3.5" />
              {t($ => $.page.actions.receive)}
            </Button>
          ) : (
            <span className="text-xs text-muted-foreground">—</span>
          ),
      },
    ],
    [t],
  );

  return (
    <div className="flex flex-col h-full min-h-0">
      {/* Header — NO "New Receipt": receiving is driven by eligible Purchase Orders (§3). */}
      <div className="px-4 py-3 border-b bg-card flex items-start justify-between gap-3 flex-wrap">
        <PageHeader title={t($ => $.page.title)} subtitle={t($ => $.page.subtitle)} />
        <Tabs value={scope} onValueChange={(v) => changeScope(v as ReceivingScope)}>
          <TabsList>
            <TabsTrigger value="active">{t($ => $.page.tabs.active)}</TabsTrigger>
            <TabsTrigger value="history">{t($ => $.page.tabs.history)}</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {/* KPIs */}
      <div className="px-4 pt-3">
        <div className="grid grid-cols-3 gap-3">
          <KpiChip icon={ClipboardList} tone="bg-muted text-muted-foreground" label={t($ => $.page.kpis.awaiting)} value={kpis?.awaiting ?? 0} />
          <KpiChip icon={PackageOpen} tone="bg-amber-500/10 text-amber-600 dark:text-amber-400" label={t($ => $.page.kpis.partial)} value={kpis?.partial ?? 0} />
          <KpiChip icon={PackageCheck} tone="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400" label={t($ => $.page.kpis.received)} value={kpis?.received ?? 0} />
        </div>
      </div>

      {/* Search + collapsible Filters — approved ECOS responsive pattern (Search inline, Supplier/
          Warehouse/Date behind the Filters toggle); no wide filter toolbar on mobile (§3). */}
      <div className="px-4 py-3">
        <EntityToolbar
          searchPlaceholder={t($ => $.page.filters.search)}
          onSearchChange={(v) => { setSearch(v); setPage(1); }}
          onRefresh={() => void refetch()}
          isRefreshing={isFetching}
          onClearFilters={clearFilters}
          filterPanel={
            <>
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">{t($ => $.page.filters.supplier)}</span>
                <select
                  value={supplierId}
                  onChange={(e) => { setSupplierId(e.target.value); setPage(1); }}
                  aria-label={t($ => $.page.filters.supplier)}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="">{t($ => $.page.filters.allSuppliers)}</option>
                  {(supplierOptions ?? []).map((opt) => (
                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                  ))}
                </select>
              </div>

              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">{t($ => $.page.filters.warehouse)}</span>
                <select
                  value={warehouseId}
                  onChange={(e) => { setWarehouseId(e.target.value); setPage(1); }}
                  aria-label={t($ => $.page.filters.warehouse)}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="">{t($ => $.page.filters.allWarehouses)}</option>
                  {(warehouseOptions ?? []).map((w) => (
                    <option key={w.value} value={w.value}>{w.label}</option>
                  ))}
                </select>
              </div>

              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">{t($ => $.page.filters.dateFrom)}</span>
                <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1); }} className="h-9" />
              </div>

              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">{t($ => $.page.filters.dateTo)}</span>
                <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1); }} className="h-9" />
              </div>
            </>
          }
        />
      </div>

      {/* Queue */}
      <div className="flex-1 overflow-hidden px-4 pb-4 flex flex-col">
        {isError ? (
          <div className="flex flex-col items-center justify-center h-64 gap-3 text-muted-foreground">
            <AlertTriangle className="h-8 w-8 text-destructive/70" />
            <p className="text-sm">{t($ => $.page.loadError)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>{t($ => $.page.retry)}</Button>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span className="text-sm">{t($ => $.page.loading)}</span>
          </div>
        ) : (
          <>
            <div className="flex-1 overflow-hidden">
              <UniversalDataGrid<ReceivingQueueRow>
                columns={columns}
                data={items}
                rowId={(r) => r.id}
                loading={false}
                renderMobileCard={(r) => (
                  <div role="listitem" className="border-b p-3.5 last:border-0 space-y-2.5">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="font-mono text-sm font-medium">{r.po_number}</p>
                        <p className="text-xs text-muted-foreground truncate">{r.supplier?.name ?? '—'}</p>
                        <p className="text-[11px] text-muted-foreground truncate">{r.warehouse ? `${r.warehouse.code} — ${r.warehouse.name}` : '—'}</p>
                      </div>
                      <StageBadge row={r} />
                    </div>
                    <div className="grid grid-cols-3 gap-2 text-sm">
                      <div>
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.page.columns.expected)}</p>
                        <p className="tabular-nums font-medium">{fmt(r.expected_qty)}</p>
                      </div>
                      <div>
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.page.columns.received)}</p>
                        <p className="tabular-nums font-medium text-emerald-700 dark:text-emerald-400">{fmt(r.received_qty)}</p>
                      </div>
                      <div>
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.page.columns.remaining)}</p>
                        <p className={`tabular-nums font-medium ${r.remaining_qty > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'}`}>
                          {r.remaining_qty > 0 ? fmt(r.remaining_qty) : '—'}
                        </p>
                      </div>
                    </div>
                    {r.remaining_qty > 0 && (
                      <div className="flex justify-end">
                        <Button size="sm" className="h-8 gap-1.5 text-xs" onClick={() => setReceivePo(r)}>
                          <PackageCheck className="h-3.5 w-3.5" />
                          {t($ => $.page.actions.receive)}
                        </Button>
                      </div>
                    )}
                  </div>
                )}
                emptyState={
                  <div className="flex flex-col items-center justify-center py-16 gap-2 text-muted-foreground">
                    <Truck className="w-8 h-8 opacity-30" />
                    <p className="text-sm">{scope === 'history' ? t($ => $.page.empty.history) : t($ => $.page.empty.active)}</p>
                  </div>
                }
              />
            </div>

            {meta && meta.total > 0 ? (
              <div className="pt-3">
                <Pagination
                  meta={{ page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page }}
                  onPageChange={setPage}
                />
              </div>
            ) : null}
          </>
        )}
      </div>

      <ReceiveDrawer
        poId={receivePo?.id ?? null}
        poNumber={receivePo?.po_number}
        open={receivePo !== null}
        onOpenChange={(open) => { if (!open) setReceivePo(null); }}
      />
    </div>
  );
}
