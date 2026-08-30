import { useMemo, useState } from 'react';
import { CheckCircle2, Clock, Download, Loader2, Package, Printer, RotateCcw, Waves } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ds/use-toast';
import { Progress } from '@/components/ui/progress';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { PrintTable } from '../components/print-table';
import { RelatedOrdersPanel } from '../components/related-orders-panel';
import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import { useColumnVisibility } from '@/components/data-grid/use-column-visibility';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import {
  useCompleteProductPreparation,
  usePreparationWave,
  useProductRelatedOrders,
  useUncompleteProductPreparation,
  useUpdateProductPrepared,
  useWaveProductDemand,
} from '../hooks/use-preparation';
import { useSelectedWaveId } from '../components/wave-picker';
import type { WaveProductDemandItem } from '../types/preparation';

type CompletionFilter = 'all' | 'not_started' | 'in_progress' | 'completed';

function fmt(n: number) {
  return n.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

/**
 * CSV of the FULL row set, not the visible subset.
 *
 * Column-visibility and the completion tabs are view preferences; an export that
 * honoured them would silently ship a partial sheet. Headers are therefore listed
 * explicitly rather than derived from `colVis`, and every field the row carries is
 * written — including Progress, which is a derived read-time figure.
 *
 * BOM-prefixed because product names are frequently Arabic and Excel misreads UTF-8
 * without it — the same variant the Missing Materials and Orders exports use.
 */
function downloadCsv(filename: string, headers: string[], rows: (string | number | null | undefined)[][]) {
  const escape = (v: string | number | null | undefined) => {
    const s = v == null ? '' : String(v);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const csv = [headers, ...rows].map((r) => r.map(escape).join(',')).join('\n');
  // U+FEFF via fromCharCode: a literal sits in the Arabic Presentation Forms block
  // and trips the no-arabic-literals lint rule.
  const bom = String.fromCharCode(0xFEFF);
  const blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = Object.assign(document.createElement('a'), { href: url, download: filename });
  a.click();
  URL.revokeObjectURL(url);
}


/**
 * Inline Prepared editor — product-level and operator-owned.
 *
 * Commits on blur/Enter and then lets the query invalidate; Remaining and Completion %
 * are re-read from the backend rather than recomputed here, so the table can never show
 * a figure the server does not hold. `order_lines.prepared_qty` is not involved at any
 * point: Prepared is one number per product, never distributed across orders.
 */
function PreparedEditor({
  item,
  waveId,
}: {
  item: WaveProductDemandItem;
  waveId: string;
}) {
  const { t } = useTranslation('operations');
  const update = useUpdateProductPrepared();
  const [draft, setDraft] = useState<string>(String(item.prepared_qty));

  // Re-sync when the row changes underneath (rebuild, postpone, another operator).
  const committed = String(item.prepared_qty);
  const [lastCommitted, setLastCommitted] = useState(committed);
  if (committed !== lastCommitted) {
    setLastCommitted(committed);
    setDraft(committed);
  }

  function commit() {
    const value = Number(draft);
    if (!Number.isFinite(value) || value === item.prepared_qty) {
      setDraft(committed);
      return;
    }
    // The server clamps to required_qty; mirroring that here only avoids a doomed round trip.
    const clamped = Math.min(Math.max(value, 0), item.required_qty);
    update.mutate(
      { waveId, productId: item.product_id, preparedQty: clamped },
      {
        onSuccess: () => toast.success(t($ => $.wave.productDemand.preparedSaved)),
        onError: () => {
          setDraft(committed);
          toast.error(t($ => $.wave.productDemand.preparedError));
        },
      },
    );
  }

  return (
    <Input
      type="number"
      inputMode="decimal"
      min={0}
      max={item.required_qty}
      value={draft}
      disabled={update.isPending}
      onChange={(e) => setDraft(e.target.value)}
      onBlur={commit}
      onKeyDown={(e) => { if (e.key === 'Enter') { e.currentTarget.blur(); } }}
      className="no-spinner h-7 w-20 text-end text-sm tabular-nums"
    />
  );
}

/**
 * The product's primary completion action — mark complete / withdraw completion.
 *
 * Extracted so the desktop table cell and the mobile card render the SAME control with the
 * same guards (§18: one workflow, two presentations). All authorization/eligibility guards
 * (P-04 shortfall, waiting_material) are preserved; the backend remains authoritative.
 */
function CompletionAction({ item, waveId }: { item: WaveProductDemandItem; waveId: string }) {
  const { t } = useTranslation('operations');
  const completePreparation = useCompleteProductPreparation();
  const uncompletePreparation = useUncompleteProductPreparation();

  if (item.preparation_completed_at !== null) {
    return (
      <div className="flex items-center justify-end gap-1.5">
        <Badge variant="outline" className="gap-1 text-[11px] font-normal text-emerald-700">
          <CheckCircle2 className="size-3" />
          {t($ => $.wave.productDemand.completed)}
        </Badge>
        <Button
          variant="ghost"
          size="sm"
          className="h-7 gap-1.5 text-xs print:hidden"
          disabled={uncompletePreparation.isPending}
          title={t($ => $.wave.productDemand.markNotCompleteTooltip)}
          onClick={() =>
            uncompletePreparation.mutate(
              { waveId, productId: item.product_id },
              { onError: () => toast.error(t($ => $.wave.productDemand.uncompleteError)) },
            )
          }
        >
          <RotateCcw className="size-3.5" />
          {t($ => $.wave.productDemand.markNotComplete)}
        </Button>
      </div>
    );
  }

  const blocked = item.remaining_qty > 0 || item.material_status === 'waiting_material';
  return (
    <Button
      variant="ghost"
      size="sm"
      className="h-7 gap-1.5 text-xs print:hidden"
      disabled={completePreparation.isPending || blocked}
      title={
        item.material_status === 'waiting_material'
          ? t($ => $.wave.productDemand.statusWaitingMaterial)
          : item.remaining_qty > 0
            ? t($ => $.wave.productDemand.markCompleteBlocked)
            : undefined
      }
      onClick={() => {
        if (blocked) return;
        completePreparation.mutate(
          { waveId, productId: item.product_id },
          { onError: () => toast.error(t($ => $.wave.productDemand.completeError)) },
        );
      }}
    >
      <CheckCircle2 className="size-3.5" />
      {t($ => $.wave.productDemand.markComplete)}
    </Button>
  );
}

/**
 * Mobile operational card for one Active product (§10). Same canonical data and workflow as
 * the desktop row: required / prepared / remaining, the inline Prepared editor, the readiness
 * badge, and the primary completion action kept in reach — never behind an overflow menu (§11).
 */
function ProductMobileCard({
  item,
  waveId,
  onOpen,
}: {
  item: WaveProductDemandItem;
  waveId: string | null;
  onOpen: (item: WaveProductDemandItem) => void;
}) {
  const { t } = useTranslation('operations');
  return (
    <div role="listitem" className="border-b p-3.5 last:border-0 space-y-2.5">
      <div className="flex items-start justify-between gap-2">
        <button type="button" onClick={() => onOpen(item)} className="min-w-0 text-start">
          <p className="text-sm font-medium truncate">{item.product_name}</p>
          {item.product_sku && <p className="text-[11px] text-muted-foreground font-mono">{item.product_sku}</p>}
        </button>
        {item.material_status === 'waiting_material' ? (
          <Badge variant="outline" className="shrink-0 gap-1 text-[11px] font-normal text-amber-700 border-amber-200 dark:text-amber-400 dark:border-amber-800">
            <Clock className="size-3" />
            {t($ => $.wave.productDemand.statusWaitingMaterial)}
          </Badge>
        ) : (
          <Badge variant="outline" className="shrink-0 gap-1 text-[11px] font-normal text-emerald-700 border-emerald-200 dark:text-emerald-400 dark:border-emerald-800">
            <CheckCircle2 className="size-3" />
            {t($ => $.wave.productDemand.statusReady)}
          </Badge>
        )}
      </div>

      <div className="grid grid-cols-3 gap-2 text-sm">
        <div>
          <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.wave.productDemand.columns.required)}</p>
          <p className="tabular-nums font-medium">{fmt(item.required_qty)}</p>
        </div>
        <div>
          <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.wave.productDemand.columns.prepared)}</p>
          {waveId ? <PreparedEditor item={item} waveId={waveId} /> : <p className="tabular-nums font-medium">{fmt(item.prepared_qty)}</p>}
        </div>
        <div>
          <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.wave.productDemand.columns.remaining)}</p>
          <p className={`tabular-nums font-medium ${item.remaining_qty > 0 ? 'text-amber-700' : 'text-muted-foreground'}`}>
            {item.remaining_qty > 0 ? fmt(item.remaining_qty) : '—'}
          </p>
        </div>
      </div>

      <div className="space-y-0.5">
        <Progress value={item.completion_pct} className="h-1.5" />
        <p className="text-[11px] text-muted-foreground">{item.completion_pct.toFixed(1)}%</p>
      </div>

      <div className="flex items-center justify-between gap-2">
        <button type="button" onClick={() => onOpen(item)} className="text-xs text-muted-foreground hover:underline">
          {t($ => $.wave.productDemand.columns.orders)}: {item.orders_count}
        </button>
        {waveId && <CompletionAction item={item} waveId={waveId} />}
      </div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function WaveProductDemandPage() {
  const { t } = useTranslation('operations');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;

  const waveId = useSelectedWaveId();
  const { data: wave } = usePreparationWave(waveId);
  const { data: items, isLoading, isFetching, refetch } = useWaveProductDemand(waveId);

  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<CompletionFilter>('all');
  const [drillProduct, setDrillProduct] = useState<WaveProductDemandItem | null>(null);

  const { data: relatedOrders, isLoading: relatedLoading } = useProductRelatedOrders(
    waveId,
    drillProduct?.product_id ?? null,
  );

  const COMPLETION_TABS: Array<{ value: CompletionFilter; label: string }> = [
    { value: 'all',         label: t($ => $.wave.productDemand.filters.all) },
    { value: 'not_started', label: t($ => $.wave.productDemand.filters.notStarted) },
    { value: 'in_progress', label: t($ => $.wave.productDemand.filters.inProgress) },
    { value: 'completed',   label: t($ => $.wave.productDemand.filters.completed) },
  ];

  const columns: DataGridColumnDef<WaveProductDemandItem>[] = useMemo(() => [
    {
      key: 'product',
      label: t($ => $.wave.productDemand.columns.product),
      alwaysVisible: true,
      cell: (item) => (
        <button type="button" onClick={() => setDrillProduct(item)} className="text-start hover:underline print:no-underline">
          <div className="text-sm font-medium">{item.product_name}</div>
          {item.product_sku && (
            <div className="text-[10px] text-muted-foreground font-mono">{item.product_sku}</div>
          )}
        </button>
      ),
    },
    {
      key: 'required_qty',
      label: t($ => $.wave.productDemand.columns.required),
      defaultVisible: true,
      align: 'end',
      cell: (item) => <span className="text-sm tabular-nums">{fmt(item.required_qty)}</span>,
    },
    {
      key: 'prepared_qty',
      label: t($ => $.wave.productDemand.columns.prepared),
      defaultVisible: true,
      align: 'end',
      cell: (item) => (
        waveId
          ? (
              <div className="flex justify-end">
                {/* Printed sheets show the value, not an input. */}
                <span className="hidden print:inline text-sm tabular-nums">{fmt(item.prepared_qty)}</span>
                <span className="print:hidden">
                  {/* Editable even after completion: preparation is a live count, and a
                      completed product can still need correcting or re-opening. */}
                  <PreparedEditor item={item} waveId={waveId} />
                </span>
              </div>
            )
          : <span className="text-sm tabular-nums text-emerald-700">{fmt(item.prepared_qty)}</span>
      ),
    },
    {
      key: 'remaining_qty',
      label: t($ => $.wave.productDemand.columns.remaining),
      defaultVisible: true,
      align: 'end',
      cell: (item) => (
        <span className={`text-sm tabular-nums ${item.remaining_qty > 0 ? 'text-amber-700' : 'text-muted-foreground'}`}>
          {item.remaining_qty > 0 ? fmt(item.remaining_qty) : '—'}
        </span>
      ),
    },
    {
      key: 'orders_count',
      label: t($ => $.wave.productDemand.columns.orders),
      defaultVisible: true,
      align: 'end',
      cell: (item) => (
        <span className="text-sm tabular-nums text-muted-foreground">{item.orders_count}</span>
      ),
    },
    {
      key: 'completion_pct',
      label: t($ => $.wave.productDemand.columns.progress),
      defaultVisible: true,
      width: 140,
      cell: (item) => (
        <div className="space-y-0.5 min-w-[100px]">
          <Progress value={item.completion_pct} className="h-1.5" />
          <p className="text-xs text-muted-foreground">{item.completion_pct.toFixed(1)}%</p>
        </div>
      ),
    },
    {
      key: 'material_status',
      label: t($ => $.wave.productDemand.columns.readiness),
      defaultVisible: true,
      width: 150,
      cell: (item) => (
        item.material_status === 'waiting_material'
          ? (
              <Badge variant="outline" className="gap-1 text-[11px] font-normal text-amber-700 border-amber-200 dark:text-amber-400 dark:border-amber-800">
                <Clock className="size-3" />
                {t($ => $.wave.productDemand.statusWaitingMaterial)}
                {item.blocking_materials_count > 0 ? ` (${item.blocking_materials_count})` : ''}
              </Badge>
            )
          : (
              <Badge variant="outline" className="gap-1 text-[11px] font-normal text-emerald-700 border-emerald-200 dark:text-emerald-400 dark:border-emerald-800">
                <CheckCircle2 className="size-3" />
                {t($ => $.wave.productDemand.statusReady)}
              </Badge>
            )
      ),
    },
    {
      key: 'action',
      label: t($ => $.wave.productDemand.columns.action),
      alwaysVisible: true,
      align: 'end',
      // The completion control is shared with the mobile card (§18) so both presentations
      // apply the identical guards; the backend remains authoritative.
      cell: (item) => (waveId ? <CompletionAction item={item} waveId={waveId} /> : null),
    },
  ], [t, waveId]);

  const colMetas = useMemo(() => columns.map((c) => ({
    key: c.key,
    label: c.label,
    alwaysVisible: c.alwaysVisible,
    defaultVisible: c.defaultVisible,
  })), [columns]);

  const colVis = useColumnVisibility('wave-product-demand-cols', colMetas);

  const allItems = items ?? [];

  function matchesFilter(item: WaveProductDemandItem): boolean {
    switch (filter) {
      case 'not_started': return item.prepared_qty === 0;
      case 'in_progress': return item.prepared_qty > 0 && item.remaining_qty > 0;
      case 'completed':   return item.remaining_qty === 0;
      default:            return true;
    }
  }

  const filtered = allItems.filter((item) => {
    if (!matchesFilter(item)) return false;
    if (search) {
      const q = search.toLowerCase();
      return (
        item.product_name.toLowerCase().includes(q) ||
        (item.product_sku ?? '').toLowerCase().includes(q)
      );
    }
    return true;
  });

  const countByFilter: Record<CompletionFilter, number> = {
    all:         allItems.length,
    not_started: allItems.filter((i) => i.prepared_qty === 0).length,
    in_progress: allItems.filter((i) => i.prepared_qty > 0 && i.remaining_qty > 0).length,
    completed:   allItems.filter((i) => i.remaining_qty === 0).length,
  };


  // Exports ALL rows the wave has, ignoring the completion tab and column visibility —
  // both are view preferences, and a partial sheet is worse than none.
  function handleDownload() {
    downloadCsv(
      `product-demand-${wave?.wave_number ?? 'wave'}.csv`,
      [
        t($ => $.wave.productDemand.columns.product),
        t($ => $.wave.productDemand.columns.sku),
        t($ => $.wave.productDemand.columns.required),
        t($ => $.wave.productDemand.columns.prepared),
        t($ => $.wave.productDemand.columns.remaining),
        t($ => $.wave.productDemand.columns.orders),
        t($ => $.wave.productDemand.columns.progress),
      ],
      allItems.map((i) => [
        i.product_name,
        i.product_sku ?? '',
        i.required_qty,
        i.prepared_qty,
        i.remaining_qty,
        i.orders_count,
        `${Number(i.completion_pct ?? 0).toFixed(1)}%`,
      ]),
    );
  }

  return (
    <div className="flex flex-col h-full">
      {/* Toolbar, filters and search are UI chrome — excluded from the printed sheet. */}
      <div className="print:hidden">
        <SmartToolbar
          onRefresh={() => void refetch()}
          isFetching={isFetching}
          secondaryActions={[
            // Print / CSV export are desktop utilities (§17): kept off the cramped phone
            // toolbar, unchanged on desktop. hideOnMobile shows them from the sm breakpoint up.
            {
              key: 'download',
              label: t($ => $.wave.productDemand.download),
              icon: Download,
              onClick: handleDownload,
              hideOnMobile: true,
            },
            {
              key: 'print',
              label: t($ => $.wave.productDemand.print),
              icon: Printer,
              onClick: () => window.print(),
              hideOnMobile: true,
            },
          ]}
          viewControls={
            // Column visibility applies only to the desktop table; the mobile view renders
            // cards, so the control is hidden below lg.
            <span className="hidden lg:flex">
              <ColumnVisibilityMenu
                columns={colMetas}
                visibility={colVis.visibility}
                onToggle={colVis.toggle}
                onReset={colVis.reset}
              />
            </span>
          }
        />
      </div>

      {/* Print-only header: the sheet must identify itself off-screen. */}
      <div className="hidden print:block px-4 py-3 border-b">
        <h1 className="text-lg font-semibold">{t($ => $.wave.productDemand.printTitle)}</h1>
        <p className="text-sm text-muted-foreground">
          {wave?.wave_number}
          {wave?.planning_date ? ` · ${wave.planning_date}` : ''}
        </p>
      </div>

      {/* Filter tabs + search */}
      <div className="flex items-center justify-between gap-3 px-4 py-2 border-b bg-muted/30 flex-wrap print:hidden">
        <div className="flex items-center gap-1 overflow-x-auto">
          {COMPLETION_TABS.map((tab) => {
            const active = filter === tab.value;
            const count  = countByFilter[tab.value];
            return (
              <button
                key={tab.value}
                onClick={() => setFilter(tab.value)}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium whitespace-nowrap transition-colors ${
                  active
                    ? 'bg-background text-foreground shadow-sm border'
                    : 'text-muted-foreground hover:text-foreground hover:bg-background/60'
                }`}
              >
                {tab.label}
                <span className={`inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] tabular-nums ${
                  active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                }`}>
                  {count}
                </span>
              </button>
            );
          })}
        </div>

        <div className="flex items-center gap-2 shrink-0">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={tAny('wave.productDemand.searchPlaceholder')}
            className="h-7 text-xs w-48"
          />
        </div>
      </div>

      {/* Grid */}
      {/* Print output. All approved Product Demand columns are listed explicitly,
          so Columns Manager state never removes data from the printout, and the
          rows print in full — the grid's lg: breakpoint variants do not resolve
          against the print viewport, which is why printing it produced a header
          with no body. */}
      <PrintTable
        title={t($ => $.wave.productDemand.printTitle)}
        subtitle={`${wave?.wave_number ?? ''}${wave?.planning_date ? ` · ${wave.planning_date}` : ''}`}
        rows={filtered}
        rowKey={(i) => i.id}
        columns={[
          { header: t($ => $.wave.productDemand.columns.product), cell: (i) => i.product_name },
          { header: t($ => $.wave.productDemand.columns.sku), cell: (i) => i.product_sku ?? '' },
          { header: t($ => $.wave.productDemand.columns.required), cell: (i) => fmt(i.required_qty), align: 'end' },
          { header: t($ => $.wave.productDemand.columns.prepared), cell: (i) => fmt(i.prepared_qty), align: 'end' },
          { header: t($ => $.wave.productDemand.columns.remaining), cell: (i) => fmt(i.remaining_qty), align: 'end' },
          { header: t($ => $.wave.productDemand.columns.orders), cell: (i) => i.orders_count, align: 'end' },
          { header: t($ => $.wave.productDemand.columns.progress), cell: (i) => `${i.completion_pct.toFixed(1)}%`, align: 'end' },
        ]}
      />

      <div className="flex-1 overflow-hidden print:hidden">
        {!waveId ? (
          <div className="flex flex-col items-center justify-center h-64 gap-2 text-muted-foreground">
            <Waves className="h-8 w-8 opacity-30" />
            <p className="text-sm">{t($ => $.wave.productDemand.noWave)}</p>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span className="text-sm">{t($ => $.wave.loading)}</span>
          </div>
        ) : (
          <UniversalDataGrid<WaveProductDemandItem>
            columns={columns}
            data={filtered}
            rowId={(item) => item.id}
            loading={false}
            columnVisibility={colVis.visibility}
            renderMobileCard={(item) => (
              <ProductMobileCard item={item} waveId={waveId} onOpen={setDrillProduct} />
            )}
            emptyState={
              <div className="flex flex-col items-center justify-center py-16 text-muted-foreground gap-2">
                <Package className="w-8 h-8" />
                <p className="text-sm">
                  {allItems.length === 0
                    ? t($ => $.wave.productDemand.emptyNoDemand)
                    : t($ => $.wave.productDemand.emptyNoMatch)}
                </p>
              </div>
            }
          />
        )}
      </div>

      {/* Product → Related Orders. Required per order only — Prepared is product-level
          and is deliberately NOT shown per order. */}
      <RelatedOrdersPanel
        open={drillProduct !== null}
        onOpenChange={(open) => { if (!open) setDrillProduct(null); }}
        title={t($ => $.wave.productDemand.relatedOrders)}
        entityName={drillProduct?.product_name}
        entitySubtitle={drillProduct?.product_sku}
        waveId={waveId}
        orders={relatedOrders}
        isLoading={relatedLoading}
        emptyLabel={t($ => $.wave.productDemand.noRelatedOrders)}
        extraColumns={[
          {
            key: 'required_qty',
            header: t($ => $.wave.productDemand.requiredPerOrder),
            align: 'end',
            cell: (o) => fmt(o.required_qty),
          },
        ]}
      />
    </div>
  );
}
