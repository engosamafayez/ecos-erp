import { useState } from 'react';
import {
  CheckCircle2,
  PackageCheck,
  ShieldCheck,
  XCircle,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import { useColumnVisibility } from '@/components/data-grid/use-column-visibility';
import { useRowSelection } from '@/components/data-grid/use-row-selection';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { useToastStore } from '@/components/ds/use-toast';
import { usePreparedPool, useUpdatePoolQuality } from '../hooks/use-preparation';
import { useWarehouseOptions } from '@/features/products/hooks/use-warehouse-options';
import type { PreparedPoolEntry, QualityStatus } from '../types/preparation';

// ── Status metadata ────────────────────────────────────────────────────────────

const QUALITY_COLORS: Record<QualityStatus, string> = {
  pending_review: 'bg-gray-100 text-gray-700',
  passed:         'bg-green-100 text-green-700',
  failed:         'bg-red-100 text-red-700',
};

const QUALITY_LABELS: Record<QualityStatus, string> = {
  pending_review: 'Pending Review',
  passed:         'Passed',
  failed:         'Failed',
};

function fmt(n: number) {
  return n.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

// ── Quality Check Dialog ───────────────────────────────────────────────────────

type QualityDialogProps = {
  entry: PreparedPoolEntry | null;
  onClose: () => void;
  onSave: (poolId: string, result: 'passed' | 'failed', notes: string) => Promise<void>;
  saving: boolean;
};

function QualityCheckDialog({ entry, onClose, onSave, saving }: QualityDialogProps) {
  const [result, setResult] = useState<'passed' | 'failed'>('passed');
  const [notes, setNotes]   = useState('');

  if (!entry) return null;

  return (
    <Dialog open={!!entry} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle className="text-sm">Quality Check — {entry.sku}</DialogTitle>
        </DialogHeader>
        <div className="space-y-4 py-2">
          <div className="space-y-1">
            <Label className="text-xs">Result</Label>
            <Select value={result} onValueChange={(v) => setResult(v as 'passed' | 'failed')}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="passed">Passed</SelectItem>
                <SelectItem value="failed">Failed</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1">
            <Label className="text-xs">Notes (optional)</Label>
            <Textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Inspection notes..."
              className="text-sm resize-none"
              rows={3}
            />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" size="sm" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button
            size="sm"
            onClick={() => void onSave(entry.id, result, notes)}
            disabled={saving}
          >
            {result === 'passed'
              ? <CheckCircle2 className="w-3.5 h-3.5 mr-1.5 text-green-600" />
              : <XCircle className="w-3.5 h-3.5 mr-1.5 text-red-500" />}
            Save Result
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Column definitions ─────────────────────────────────────────────────────────

function buildColumns(onQualityCheck: (e: PreparedPoolEntry) => void): DataGridColumnDef<PreparedPoolEntry>[] {
  return [
    {
      key: 'product',
      label: 'Product',
      alwaysVisible: true,
      cell: (e) => (
        <div className="min-w-0">
          <p className="text-sm font-medium truncate">{e.name}</p>
          <p className="text-xs text-muted-foreground">{e.sku}</p>
        </div>
      ),
    },
    {
      key: 'wave',
      label: 'Wave',
      defaultVisible: true,
      cell: (e) => (
        <span className="text-xs text-muted-foreground">{e.preparation_wave_number ?? '—'}</span>
      ),
    },
    {
      key: 'quantity_available',
      label: 'Available',
      defaultVisible: true,
      align: 'end',
      cell: (e) => (
        <span className="text-sm tabular-nums font-medium">{fmt(e.quantity_available)}</span>
      ),
    },
    {
      key: 'quantity_reserved',
      label: 'Reserved',
      defaultVisible: true,
      align: 'end',
      cell: (e) => <span className="text-sm tabular-nums">{fmt(e.quantity_reserved)}</span>,
    },
    {
      key: 'quantity_loaded',
      label: 'Loaded',
      defaultVisible: true,
      align: 'end',
      cell: (e) => <span className="text-sm tabular-nums">{fmt(e.quantity_loaded)}</span>,
    },
    {
      key: 'quality_status',
      label: 'Quality',
      defaultVisible: true,
      cell: (e) => (
        <Badge className={`text-xs ${QUALITY_COLORS[e.quality_status]}`}>
          {QUALITY_LABELS[e.quality_status]}
        </Badge>
      ),
    },
    {
      key: 'quality_checked_at',
      label: 'Checked At',
      defaultVisible: false,
      cell: (e) => (
        <span className="text-xs text-muted-foreground">
          {e.quality_checked_at
            ? new Date(e.quality_checked_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })
            : '—'}
        </span>
      ),
    },
    {
      key: 'prepared_at',
      label: 'Prepared At',
      defaultVisible: true,
      cell: (e) => (
        <span className="text-xs text-muted-foreground">
          {e.prepared_at
            ? new Date(e.prepared_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })
            : '—'}
        </span>
      ),
    },
    {
      key: 'actions',
      label: 'Actions',
      alwaysVisible: true,
      pin: 'right',
      width: 48,
      cell: (e) => (
        e.quality_status === 'pending_review' ? (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 text-xs gap-1"
            onClick={(ev) => { ev.stopPropagation(); onQualityCheck(e); }}
          >
            <ShieldCheck className="w-3 h-3" />
            QC
          </Button>
        ) : null
      ),
    },
  ];
}

// ── Page ───────────────────────────────────────────────────────────────────────

export function PreparedPoolPage() {
  const toast = useToastStore((s) => s.toast);
  const { data: warehouseOptions = [] } = useWarehouseOptions();
  const [warehouseId, setWarehouseId]         = useState('');
  const [qualityFilter, setQualityFilter]     = useState<QualityStatus | 'all'>('all');
  const [availableOnly, setAvailableOnly]     = useState(false);
  const [page, setPage]                       = useState(1);
  const [qualityEntry, setQualityEntry]       = useState<PreparedPoolEntry | null>(null);

  const effectiveWarehouseId = warehouseId || (warehouseOptions[0]?.value ?? '');

  const { data, isLoading, isFetching, error, refetch } = usePreparedPool({
    warehouse_id:   effectiveWarehouseId,
    quality_status: qualityFilter !== 'all' ? qualityFilter : undefined,
    available_only: availableOnly || undefined,
    page,
    per_page: 25,
  });

  const entries = data?.data ?? [];
  const meta    = data?.meta;

  const updateQuality = useUpdatePoolQuality();

  const selection = useRowSelection<PreparedPoolEntry>({ items: entries, getId: (e) => e.id });

  const colMetas = buildColumns(setQualityEntry).map((c) => ({
    key: c.key,
    label: c.label,
    alwaysVisible: c.alwaysVisible,
    defaultVisible: c.defaultVisible,
  }));
  const colVis   = useColumnVisibility('prep-pool-cols', colMetas);
  const columns  = buildColumns(setQualityEntry);

  async function handleQualitySave(poolId: string, result: 'passed' | 'failed', notes: string) {
    await updateQuality.mutateAsync({ poolId, payload: { quality_result: result, notes: notes || undefined } });
    toast({ type: result === 'passed' ? 'success' : 'error', title: `Quality ${result === 'passed' ? 'passed ✓' : 'failed ✗'}` });
    setQualityEntry(null);
  }

  // ── Quick-filter chips ──────────────────────────────────────────────────────
  const pendingCount = entries.filter((e) => e.quality_status === 'pending_review').length;

  return (
    <div className="flex flex-col h-full">
      <SmartToolbar
        onRefresh={() => void refetch()}
        isFetching={isFetching}
        viewControls={
          <ColumnVisibilityMenu
            columns={colMetas}
            visibility={colVis.visibility}
            onToggle={colVis.toggle}
            onReset={colVis.reset}
          />
        }
      />

      {/* Filter bar */}
      <div className="flex items-center gap-2 px-4 py-2 border-b bg-muted/30 flex-wrap">
        <Select value={effectiveWarehouseId} onValueChange={(v) => { setWarehouseId(v); setPage(1); }}>
          <SelectTrigger className="h-7 text-xs w-44">
            <SelectValue placeholder="Select warehouse" />
          </SelectTrigger>
          <SelectContent>
            {warehouseOptions.map((w: { value: string; label: string }) => (
              <SelectItem key={w.value} value={w.value}>{w.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={qualityFilter} onValueChange={(v) => { setQualityFilter(v as QualityStatus | 'all'); setPage(1); }}>
          <SelectTrigger className="h-7 text-xs w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Quality</SelectItem>
            <SelectItem value="pending_review">Pending Review</SelectItem>
            <SelectItem value="passed">Passed</SelectItem>
            <SelectItem value="failed">Failed</SelectItem>
          </SelectContent>
        </Select>

        <Button
          variant={availableOnly ? 'default' : 'outline'}
          size="sm"
          className="h-7 text-xs"
          onClick={() => { setAvailableOnly((v) => !v); setPage(1); }}
        >
          Available Only
        </Button>

        {pendingCount > 0 && (
          <span className="ml-auto text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-0.5">
            {pendingCount} pending QC
          </span>
        )}
      </div>

      <div className="flex-1 overflow-hidden">
        <UniversalDataGrid<PreparedPoolEntry>
          columns={columns}
          data={entries}
          rowId={(e) => e.id}
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
              <PackageCheck className="w-8 h-8" />
              <p className="text-sm">
                {!effectiveWarehouseId
                  ? 'Select a warehouse to view the pool.'
                  : 'No entries in the prepared pool.'}
              </p>
            </div>
          }
        />
      </div>

      <QualityCheckDialog
        entry={qualityEntry}
        onClose={() => setQualityEntry(null)}
        onSave={handleQualitySave}
        saving={updateQuality.isPending}
      />
    </div>
  );
}
