import { useState } from 'react';
import { AlertTriangle, CheckCircle, Clock, Eye, Loader2 } from 'lucide-react';

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
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ds/use-toast';
import { PageHeader } from '@/components/crud';

import {
  useWasteInvestigationsQuery,
  useResolveWasteInvestigation,
} from '../hooks/use-inventory-count';
import type {
  WasteInvestigation,
  WasteInvestigationOutcome,
} from '../types/inventory-count';
import { WasteInvestigationDetailDrawer } from '../components/waste-investigation-detail-drawer';

const OUTCOME_OPTIONS: { value: WasteInvestigationOutcome; label: string; description: string }[] = [
  { value: 'operational_waste', label: 'Operational Waste', description: 'Normal ops loss — deduct inventory, no liability charge' },
  { value: 'warehouse_responsibility', label: 'Warehouse Responsibility', description: 'Assign to warehouse manager as liability + deduct inventory' },
  { value: 'supplier_responsibility', label: 'Supplier Responsibility', description: 'Supplier caused damage — create supplier claim, no deduction' },
  { value: 'preparation_responsibility', label: 'Preparation Responsibility', description: 'Damage during prep — tag for Preparation OS, no deduction now' },
];

function SlaChip({ inv }: { inv: WasteInvestigation }) {
  if (inv.status !== 'pending_investigation') return null;
  if (inv.is_overdue_7) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-destructive/10 text-destructive px-2 py-0.5 text-[11px] font-medium">
        <AlertTriangle className="size-2.5" /> 7+ days
      </span>
    );
  }
  if (inv.is_overdue_3) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300 px-2 py-0.5 text-[11px] font-medium">
        <Clock className="size-2.5" /> 3+ days
      </span>
    );
  }
  return null;
}

function statusBadge(status: string) {
  if (status === 'resolved') {
    return (
      <Badge variant="outline" className="text-emerald-600 border-emerald-200 bg-emerald-50 dark:bg-emerald-950/20 text-xs">
        <CheckCircle className="size-3 mr-1" />Resolved
      </Badge>
    );
  }
  return (
    <Badge variant="outline" className="text-amber-600 border-amber-200 bg-amber-50 dark:bg-amber-950/20 text-xs">
      <Clock className="size-3 mr-1" />Pending
    </Badge>
  );
}

function outcomeBadge(outcome: string | null) {
  if (!outcome) return null;
  const map: Record<string, string> = {
    operational_waste:          'Operational',
    warehouse_responsibility:   'Warehouse',
    supplier_responsibility:    'Supplier',
    preparation_responsibility: 'Preparation',
  };
  return <span className="text-xs text-muted-foreground">{map[outcome] ?? outcome}</span>;
}

type ResolveState = {
  investigation: WasteInvestigation;
  outcome: WasteInvestigationOutcome | '';
  notes: string;
};

export function WasteInvestigationsPage() {
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7));
  const [statusFilter, setStatusFilter] = useState<'all' | 'pending_investigation' | 'resolved'>('all');
  const [resolveState, setResolveState] = useState<ResolveState | null>(null);
  const [resolvedBy, setResolvedBy] = useState('');
  const [detailId, setDetailId] = useState<string | null>(null);

  const query = useWasteInvestigationsQuery({
    month,
    status: statusFilter === 'all' ? undefined : statusFilter,
  });

  const resolveMutation = useResolveWasteInvestigation();

  const items: WasteInvestigation[] = query.data?.data ?? [];
  const summary = query.data?.summary;

  async function handleResolve() {
    if (!resolveState || !resolveState.outcome || !resolvedBy.trim()) return;
    try {
      await resolveMutation.mutateAsync({
        id: resolveState.investigation.id,
        payload: {
          outcome: resolveState.outcome,
          resolved_by: resolvedBy.trim(),
          investigator_notes: resolveState.notes || null,
        },
      });
      toast.success('Investigation resolved.');
      setResolveState(null);
      setResolvedBy('');
    } catch {
      toast.error('Failed to resolve investigation.');
    }
  }

  return (
    <div className="flex flex-col gap-6 p-6">
      <PageHeader
        title="Waste Investigations"
        subtitle="Review and resolve damaged inventory items from count sessions"
      />

      {/* KPI strip */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">Pending</p>
          <p className="text-2xl font-semibold mt-1 text-amber-600">{summary?.pending ?? '—'}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">Overdue 3+ days</p>
          <p className="text-2xl font-semibold mt-1 text-amber-600">{summary?.pending_over_3 ?? '—'}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">Overdue 7+ days</p>
          <p className="text-2xl font-semibold mt-1 text-destructive">{summary?.pending_over_7 ?? '—'}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">Resolved</p>
          <p className="text-2xl font-semibold mt-1 text-emerald-600">{summary?.resolved ?? '—'}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">Month</p>
          <input
            type="month"
            value={month}
            onChange={(e) => setMonth(e.target.value)}
            className="mt-1 h-8 w-full rounded border border-input bg-background px-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
      </div>

      {/* Status filters */}
      <div className="flex gap-2">
        {(['all', 'pending_investigation', 'resolved'] as const).map((s) => (
          <button
            key={s}
            onClick={() => setStatusFilter(s)}
            className={`rounded px-3 py-1.5 text-xs font-medium transition-colors ${
              statusFilter === s
                ? 'bg-primary text-primary-foreground'
                : 'bg-muted text-muted-foreground hover:bg-muted/80'
            }`}
          >
            {s === 'all' ? 'All' : s === 'pending_investigation' ? 'Pending' : 'Resolved'}
          </button>
        ))}
      </div>

      {/* Table */}
      <div className="rounded-lg border bg-card overflow-hidden">
        {query.isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="size-6 animate-spin text-muted-foreground" />
          </div>
        ) : items.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-16 text-center">
            <AlertTriangle className="size-8 text-muted-foreground/40" />
            <p className="text-sm text-muted-foreground">No waste investigations found</p>
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b bg-muted/40">
                <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Product</th>
                <th className="px-4 py-2.5 text-right text-xs font-medium text-muted-foreground">Qty</th>
                <th className="px-4 py-2.5 text-right text-xs font-medium text-muted-foreground">Value</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Damage Reason</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Warehouse</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Status</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Outcome</th>
                <th className="px-4 py-2.5 text-right text-xs font-medium text-muted-foreground">Actions</th>
              </tr>
            </thead>
            <tbody>
              {items.map((inv) => (
                <tr
                  key={inv.id}
                  className={`border-b last:border-0 hover:bg-muted/30 transition-colors ${
                    inv.is_overdue_7 ? 'bg-destructive/5' : inv.is_overdue_3 ? 'bg-amber-50/50 dark:bg-amber-950/10' : ''
                  }`}
                >
                  <td className="px-4 py-2.5">
                    <p className="font-medium">{inv.product?.name ?? '—'}</p>
                    <p className="text-[11px] text-muted-foreground">{inv.product?.sku}</p>
                  </td>
                  <td className="px-4 py-2.5 text-right tabular-nums">{Number(inv.quantity).toFixed(2)}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums font-medium">
                    {Number(inv.cost_snapshot_total_value ?? inv.total_cost).toLocaleString(undefined, {
                      minimumFractionDigits: 2, maximumFractionDigits: 2,
                    })}
                  </td>
                  <td className="px-4 py-2.5 text-muted-foreground text-xs">{inv.damage_reason ?? '—'}</td>
                  <td className="px-4 py-2.5 text-muted-foreground text-xs">
                    {(inv.warehouse as { name: string } | null)?.name ?? '—'}
                  </td>
                  <td className="px-4 py-2.5">
                    <div className="flex flex-col gap-1">
                      {statusBadge(inv.status)}
                      <SlaChip inv={inv} />
                    </div>
                  </td>
                  <td className="px-4 py-2.5">{outcomeBadge(inv.outcome)}</td>
                  <td className="px-4 py-2.5 text-right">
                    <div className="flex items-center justify-end gap-1.5">
                      <Button
                        size="sm"
                        variant="ghost"
                        className="h-7 w-7 p-0 text-muted-foreground"
                        title="View details"
                        onClick={() => setDetailId(inv.id)}
                      >
                        <Eye className="size-3.5" />
                      </Button>
                      {inv.status === 'pending_investigation' && (
                        <Button
                          size="sm"
                          variant="outline"
                          className="h-7 text-xs"
                          onClick={() => setResolveState({ investigation: inv, outcome: '', notes: '' })}
                        >
                          Resolve
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Detail drawer */}
      <WasteInvestigationDetailDrawer
        investigationId={detailId}
        open={!!detailId}
        onOpenChange={(o) => { if (!o) setDetailId(null); }}
      />

      {/* Resolve dialog */}
      <Dialog open={!!resolveState} onOpenChange={(o) => { if (!o) setResolveState(null); }}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Resolve Waste Investigation</DialogTitle>
          </DialogHeader>
          {resolveState && (
            <div className="space-y-4 py-2">
              <div className="rounded-md border bg-muted/40 px-4 py-3 text-sm">
                <p className="font-medium">{resolveState.investigation.product?.name}</p>
                <p className="text-muted-foreground text-xs mt-0.5">
                  Qty: {Number(resolveState.investigation.quantity).toFixed(2)} ·
                  Reason: {resolveState.investigation.damage_reason ?? '—'}
                </p>
              </div>

              <div className="space-y-2">
                <Label className="text-xs font-medium">Outcome</Label>
                <div className="space-y-2">
                  {OUTCOME_OPTIONS.map((opt) => (
                    <label
                      key={opt.value}
                      className={`flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer transition-colors ${
                        resolveState.outcome === opt.value
                          ? 'border-primary bg-primary/5'
                          : 'hover:bg-muted/50'
                      }`}
                    >
                      <input
                        type="radio"
                        name="outcome"
                        value={opt.value}
                        checked={resolveState.outcome === opt.value}
                        onChange={() => setResolveState((s) => s ? { ...s, outcome: opt.value } : s)}
                        className="mt-0.5"
                      />
                      <div>
                        <p className="text-sm font-medium">{opt.label}</p>
                        <p className="text-xs text-muted-foreground mt-0.5">{opt.description}</p>
                      </div>
                    </label>
                  ))}
                </div>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="resolved-by" className="text-xs font-medium">Resolved By</Label>
                <input
                  id="resolved-by"
                  value={resolvedBy}
                  onChange={(e) => setResolvedBy(e.target.value)}
                  placeholder="Name of investigator"
                  className="h-9 w-full rounded border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="inv-notes" className="text-xs font-medium">Investigator Notes (optional)</Label>
                <Textarea
                  id="inv-notes"
                  value={resolveState.notes}
                  onChange={(e) => setResolveState((s) => s ? { ...s, notes: e.target.value } : s)}
                  placeholder="Investigation findings, evidence, etc."
                  rows={3}
                  className="text-sm"
                />
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setResolveState(null)}>Cancel</Button>
            <Button
              onClick={handleResolve}
              disabled={!resolveState?.outcome || !resolvedBy.trim() || resolveMutation.isPending}
            >
              {resolveMutation.isPending && <Loader2 className="size-3.5 mr-1.5 animate-spin" />}
              Confirm Resolution
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
