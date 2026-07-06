import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  AlertTriangle,
  Ban,
  Clock,
  Download,
  Plus,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import { useColumnVisibility } from '@/components/data-grid/use-column-visibility';
import { useRowSelection } from '@/components/data-grid/use-row-selection';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { useToastStore } from '@/components/ds/use-toast';
import {
  usePreparationWaves,
  useCancelWave,
} from '../hooks/use-preparation';
import { useKeyboardShortcuts } from '../hooks/use-keyboard-shortcuts';
import { PreparationWaveDrawer } from '../components/preparation-wave-drawer';
import { CreateWaveDialog } from '../components/create-wave-dialog';
import type { PreparationWave, WaveStatus } from '../types/preparation';

// ── Status metadata ────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<WaveStatus, string> = {
  draft:            'bg-gray-100 text-gray-700',
  planning:         'bg-blue-100 text-blue-700',
  shortage_blocked: 'bg-amber-100 text-amber-700',
  preparing:        'bg-purple-100 text-purple-700',
  completed:        'bg-green-100 text-green-700',
  cancelled:        'bg-red-100 text-red-700',
};

const STATUS_LABELS: Record<WaveStatus, string> = {
  draft:            'Draft',
  planning:         'Planning',
  shortage_blocked: 'Shortage Blocked',
  preparing:        'Preparing',
  completed:        'Completed',
  cancelled:        'Cancelled',
};

type StatusTab = { value: WaveStatus | 'all'; label: string };

const STATUS_TABS: StatusTab[] = [
  { value: 'all',             label: 'All' },
  { value: 'draft',           label: 'Draft' },
  { value: 'planning',        label: 'Planning' },
  { value: 'shortage_blocked',label: 'Blocked' },
  { value: 'preparing',       label: 'Preparing' },
  { value: 'completed',       label: 'Completed' },
  { value: 'cancelled',       label: 'Cancelled' },
];

function fmt(n: number) {
  return n.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

// ── Column definitions ─────────────────────────────────────────────────────────

function buildColumns(onOpen: (w: PreparationWave) => void): DataGridColumnDef<PreparationWave>[] {
  return [
  {
    key: 'wave_number',
    label: 'Wave #',
    alwaysVisible: true,
    cell: (w) => (
      <button
        className="flex items-center gap-1.5 hover:underline text-left"
        onClick={() => onOpen(w)}
      >
        <span className="text-sm font-medium text-primary">{w.wave_number}</span>
        {w.shortage_detected && (
          <AlertTriangle className="w-3 h-3 text-amber-500" aria-label="Shortage detected" />
        )}
      </button>
    ),
  },
  {
    key: 'status',
    label: 'Status',
    alwaysVisible: true,
    cell: (w) => (
      <Badge className={`text-xs ${STATUS_COLORS[w.status]}`}>
        {STATUS_LABELS[w.status]}
      </Badge>
    ),
  },
  {
    key: 'planning_date',
    label: 'Planning Date',
    defaultVisible: true,
    cell: (w) => (
      <span className="text-sm text-muted-foreground">
        {new Date(w.planning_date).toLocaleDateString()}
      </span>
    ),
  },
  {
    key: 'orders_count',
    label: 'Orders',
    defaultVisible: true,
    align: 'end',
    cell: (w) => <span className="text-sm tabular-nums">{w.orders_count}</span>,
  },
  {
    key: 'products_count',
    label: 'Products',
    defaultVisible: true,
    align: 'end',
    cell: (w) => <span className="text-sm tabular-nums">{w.products_count}</span>,
  },
  {
    key: 'total_units_required',
    label: 'Required',
    defaultVisible: true,
    align: 'end',
    cell: (w) => <span className="text-sm tabular-nums">{fmt(w.total_units_required)}</span>,
  },
  {
    key: 'total_units_prepared',
    label: 'Prepared',
    defaultVisible: true,
    align: 'end',
    cell: (w) => <span className="text-sm tabular-nums">{fmt(w.total_units_prepared)}</span>,
  },
  {
    key: 'completion_pct',
    label: 'Completion',
    defaultVisible: true,
    width: 140,
    cell: (w) => (
      <div className="space-y-0.5 min-w-[100px]">
        <Progress value={w.completion_pct} className="h-1.5" />
        <p className="text-xs text-muted-foreground">{w.completion_pct.toFixed(1)}%</p>
      </div>
    ),
  },
  {
    key: 'created_at',
    label: 'Created',
    defaultVisible: true,
    cell: (w) => (
      <div className="flex items-center gap-1 text-xs text-muted-foreground">
        <Clock className="w-3 h-3" />
        {new Date(w.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
      </div>
    ),
  },
  ];
}

const COL_METAS = buildColumns(() => undefined).map((c) => ({ key: c.key, label: c.label, alwaysVisible: c.alwaysVisible, defaultVisible: c.defaultVisible }));

// ── Page ───────────────────────────────────────────────────────────────────────

export function PreparationWavesPage() {
  const toast = useToastStore((s) => s.toast);
  const [searchParams, setSearchParams] = useSearchParams();

  const [search, setSearch]                   = useState('');
  const [selectedWaveId, setSelectedWaveId]   = useState<string | null>(null);
  const [createOpen, setCreateOpen]           = useState(false);
  const [page, setPage]                       = useState(1);

  const statusParam = (searchParams.get('status') ?? 'all') as WaveStatus | 'all';
  const planningDate = searchParams.get('planning_date') ?? '';

  const { data, isLoading, isFetching, error, refetch } = usePreparationWaves({
    status:        statusParam !== 'all' ? statusParam : undefined,
    planning_date: planningDate || undefined,
    search:        search || undefined,
    page,
    per_page:      25,
  });

  const waves = data?.data ?? [];
  const meta  = data?.meta;

  // ── Selection ──────────────────────────────────────────────────────────────
  const selection = useRowSelection<PreparationWave>({ items: waves, getId: (w) => w.id });

  // ── Column visibility ──────────────────────────────────────────────────────
  const colVis = useColumnVisibility('prep-waves-cols', COL_METAS);
  const columns = buildColumns((w) => setSelectedWaveId(w.id));

  // ── Mutations ──────────────────────────────────────────────────────────────
  const cancelWave = useCancelWave();

  async function handleBulkCancel() {
    const ids = Array.from(selection.selectedIds);
    const cancelable = waves.filter((w) => ids.includes(w.id) && !['completed', 'cancelled'].includes(w.status));
    if (cancelable.length === 0) {
      toast({ type: 'error', title: 'No cancelable waves selected' });
      return;
    }
    await Promise.all(
      cancelable.map((w) =>
        cancelWave.mutateAsync({ id: w.id, payload: { reason: 'Bulk cancelled by operator' } }),
      ),
    );
    toast({ type: 'success', title: `${cancelable.length} wave(s) cancelled` });
    selection.clearSelection();
  }

  function handleExport() {
    const rows = waves.map((w) => [
      w.wave_number,
      STATUS_LABELS[w.status],
      w.planning_date,
      w.orders_count,
      w.products_count,
      w.total_units_required,
      w.total_units_prepared,
      `${w.completion_pct.toFixed(1)}%`,
    ]);
    const csv = [
      'Wave #,Status,Planning Date,Orders,Products,Required,Prepared,Completion',
      ...rows.map((r) => r.join(',')),
    ].join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `waves-${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  useKeyboardShortcuts({
    onNew:     () => setCreateOpen(true),
    onRefresh: () => void refetch(),
    onFilter:  () => document.querySelector<HTMLInputElement>('input[placeholder*="Search"]')?.focus(),
    onEscape:  () => { setSelectedWaveId(null); selection.clearSelection(); },
    onExport:  handleExport,
  });

  function setStatus(v: string) {
    setSearchParams((prev) => {
      if (v === 'all') prev.delete('status');
      else prev.set('status', v);
      return prev;
    });
    setPage(1);
    selection.clearSelection();
  }

  // Count per status from current full result (if not filtered) — fallback to 0
  const byStatus = data
    ? waves.reduce<Record<string, number>>((acc, w) => {
        acc[w.status] = (acc[w.status] ?? 0) + 1;
        return acc;
      }, {})
    : {};

  return (
    <div className="flex flex-col h-full">
      {/* Smart Toolbar */}
      <SmartToolbar
        primaryAction={{ label: 'New Wave', onClick: () => setCreateOpen(true), icon: Plus }}
        secondaryActions={[
          {
            key: 'export',
            label: 'Export',
            icon: Download,
            onClick: handleExport,
            hideOnMobile: true,
          },
        ]}
        bulkActions={[
          {
            key: 'bulk-cancel',
            label: 'Cancel Selected',
            onClick: () => void handleBulkCancel(),
            destructive: true,
            icon: Ban,
          } as never,
        ]}
        selectedCount={selection.selectedCount}
        onRefresh={() => void refetch()}
        isFetching={isFetching}
        viewControls={
          <ColumnVisibilityMenu
            columns={COL_METAS}
            visibility={colVis.visibility}
            onToggle={colVis.toggle}
            onReset={colVis.reset}
          />
        }
      />

      {/* Status tabs + search */}
      <div className="flex items-center justify-between gap-3 px-4 py-2 border-b bg-muted/30 flex-wrap">
        <div className="flex items-center gap-1 overflow-x-auto">
          {STATUS_TABS.map((tab) => {
            const active = statusParam === tab.value;
            const count = tab.value === 'all' ? (meta?.total ?? 0) : (byStatus[tab.value] ?? 0);
            return (
              <button
                key={tab.value}
                onClick={() => setStatus(tab.value)}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium whitespace-nowrap transition-colors ${
                  active
                    ? 'bg-background text-foreground shadow-sm border'
                    : 'text-muted-foreground hover:text-foreground hover:bg-background/60'
                }`}
              >
                {tab.label}
                {(tab.value === 'all' || byStatus[tab.value]) ? (
                  <span className={`inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] tabular-nums ${
                    active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                  }`}>
                    {count}
                  </span>
                ) : null}
              </button>
            );
          })}
        </div>

        <div className="flex items-center gap-2 shrink-0">
          <Input
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            placeholder="Search wave #..."
            className="h-7 text-xs w-44"
          />
          {planningDate && (
            <Button
              variant="outline"
              size="sm"
              className="h-7 text-xs"
              onClick={() => setSearchParams((p) => { p.delete('planning_date'); return p; })}
            >
              {planningDate} ×
            </Button>
          )}
        </div>
      </div>

      {/* Data grid */}
      <div className="flex-1 overflow-hidden">
        <UniversalDataGrid<PreparationWave>
          columns={columns}
          data={waves}
          rowId={(w) => w.id}
          loading={isLoading}
          error={!!error}
          columnVisibility={colVis.visibility}
          selection={selection}
          pagination={
            meta
              ? {
                  meta: { page: meta.page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page },
                  onPageChange: setPage,
                }
              : undefined
          }
          emptyState={
            <div className="flex flex-col items-center justify-center py-16 text-muted-foreground gap-2">
              <AlertTriangle className="w-8 h-8" />
              <p className="text-sm">No waves found. Create a new wave to get started.</p>
              <Button size="sm" onClick={() => setCreateOpen(true)}>
                <Plus className="w-4 h-4 mr-1.5" />
                New Wave
              </Button>
            </div>
          }
        />
      </div>

      <PreparationWaveDrawer waveId={selectedWaveId} onClose={() => setSelectedWaveId(null)} />
      <CreateWaveDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => { setCreateOpen(false); void refetch(); }}
      />
    </div>
  );
}
